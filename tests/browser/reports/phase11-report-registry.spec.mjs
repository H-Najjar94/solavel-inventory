import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const reports = [
    'inventory-valuation', 'stock-movement', 'item-ledger', 'warehouse-stock', 'low-stock',
    'out-of-stock', 'dead-stock', 'fast-moving', 'stock-aging', 'lot-expiry', 'serial',
    'adjustment', 'receiving', 'transfer', 'count-variance', 'fulfillment-status', 'pick-list',
    'shipment', 'reservation', 'lot-trace', 'serial-lifecycle', 'expiry-risk', 'recall-impact',
];

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email = 'qa.owner@solavel.test') {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(passwordFor(email));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

test('all registered reports render and every supported export opens with matching structure', async ({ page }) => {
    await login(page);
    await page.goto('/inventory/reports');
    await expect(page.locator('.report-card')).toHaveCount(reports.length);

    for (const report of reports) {
        const browser = await page.request.get(`/inventory/api/v1/reports/${report}`);
        expect(browser.ok(), `${report} browser payload`).toBeTruthy();
        const payload = (await browser.json()).data;
        expect(payload.key).toBe(report);
        expect(Array.isArray(payload.columns)).toBeTruthy();
        expect(Array.isArray(payload.rows)).toBeTruthy();
        expect(payload.summary).toBeTruthy();

        const csv = await page.request.get(`/inventory/api/v1/reports/${report}/export?format=csv`);
        expect(csv.headers()['content-type']).toContain('text/csv');
        expect(csv.headers()['content-disposition']).toMatch(new RegExp(`solastock-${report}-\\d{8}-\\d{6}\\.csv`));
        const csvBody = await csv.text();
        expect(csvBody).toContain(`"SolaStock report","${payload.title}"`);
        expect(csvBody).toContain(payload.columns.join(','));

        const excel = await page.request.get(`/inventory/api/v1/reports/${report}/export?format=xlsx`);
        expect(excel.headers()['content-type']).toContain('application/vnd.ms-excel');
        const excelBody = await excel.text();
        expect(excelBody).toContain('<Workbook');
        expect(excelBody).toContain(payload.title);
        for (const column of payload.columns) expect(excelBody).toContain(`>${column}<`);

        const print = await page.request.get(`/inventory/api/v1/reports/${report}/export?format=pdf`);
        expect(print.headers()['content-type']).toContain('text/html');
        const printBody = await print.text();
        expect(printBody).toContain('<!doctype html>');
        expect(printBody).toContain(`<h1>${payload.title}</h1>`);
        expect(printBody).toContain('window.print()');
    }
});

test('viewer can read reports but cannot use direct export URLs', async ({ page }) => {
    await login(page, 'qa.viewer@solavel.test');
    const report = await page.request.get('/inventory/api/v1/reports/inventory-valuation');
    expect(report.ok()).toBeTruthy();
    const exportResponse = await page.request.get('/inventory/api/v1/reports/inventory-valuation/export?format=csv');
    expect([403, 404]).toContain(exportResponse.status());
});
