import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.warehouse@solavel.test');
    await page.locator('input[type="password"]').fill(passwordFor('qa.warehouse@solavel.test'));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

test('warehouse-restricted user cannot read restricted documents or report exports', async ({ page }) => {
    await login(page);

    const warehouses = await page.evaluate(async () => (await fetch('/inventory/api/v1/warehouses?per_page=100')).json());
    const warehouseRows = warehouses.data?.data ?? warehouses.data ?? [];
    expect(warehouseRows.map((row) => row.id)).toEqual([1]);

    for (const path of ['/transfers/5', '/adjustments/6', '/counts/2']) {
        const response = await page.request.get(`/inventory/api/v1${path}`);
        expect([403, 404]).toContain(response.status());
    }

    await page.goto('/inventory/reports');
    await page.getByRole('button', { name: /Transfer Report/ }).click();
    await page.getByRole('button', { name: 'Run report' }).click();
    await page.waitForTimeout(500);
    await expect(page.locator('body')).not.toContainText('QA Reserve Warehouse 100246');
    await expect(page.locator('body')).not.toContainText('QA-B-100246');

    const exportLink = page.getByRole('link', { name: 'Export CSV' });
    const download = await Promise.all([page.waitForEvent('download'), exportLink.click()]);
    const exportPath = await download[0].path();
    expect(exportPath).toBeTruthy();
    const exportText = fs.readFileSync(exportPath, 'utf8');
    expect(exportText).not.toContain('QA Reserve Warehouse 100246');
    expect(exportText).not.toContain('QA-B-100246');
});
