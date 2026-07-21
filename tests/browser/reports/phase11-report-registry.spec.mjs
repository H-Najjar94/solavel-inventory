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

test('all report rows, totals, representative exports and role warehouse boundaries agree', async ({ browser }) => {
    // This matrix performs more than 200 live report/export requests across
    // four independently authenticated roles. Keep the exhaustive coverage
    // while allowing the standing QA dataset to grow over repeated runs.
    test.setTimeout(420_000);
    const contexts = {
        owner: await browser.newContext(),
        manager: await browser.newContext(),
        viewer: await browser.newContext(),
        warehouse: await browser.newContext(),
    };
    const pages = Object.fromEntries(await Promise.all(Object.entries(contexts).map(async ([role, context]) => {
        const page = await context.newPage();
        const email = role === 'owner' ? 'qa.owner@solavel.test' : `qa.${role}@solavel.test`;
        await login(page, email);
        return [role, page];
    })));
    const warehouseB = (await (await pages.owner.request.get('/inventory/api/v1/warehouses/2')).json()).data.warehouse;
    const assignments = (await (await pages.owner.request.get('/inventory/api/v1/settings/warehouse-assignments/91')).json()).data.assignments;
    expect(assignments.map((row) => Number(row.warehouse_id))).toEqual([1]);
    const restrictedWarehousePayload = (await (await pages.warehouse.request.get('/inventory/api/v1/warehouses?per_page=100')).json()).data;
    const restrictedWarehouses = restrictedWarehousePayload.data ?? restrictedWarehousePayload;
    expect(restrictedWarehouses.map((row) => Number(row.id))).toEqual([1]);
    const itemPayload = (await (await pages.owner.request.get('/inventory/api/v1/items?per_page=1')).json()).data;
    const itemId = Number((itemPayload.data ?? itemPayload)[0].id);
    const countKeys = {
        'inventory-valuation': 'total_lines', 'stock-movement': 'rows', 'item-ledger': 'movements', 'warehouse-stock': 'lines',
        'low-stock': 'items', 'out-of-stock': 'items', 'dead-stock': 'items', 'fast-moving': 'items', 'stock-aging': 'layers',
        'lot-expiry': 'lots', serial: 'serials', adjustment: 'documents', receiving: 'documents', transfer: 'documents',
        'count-variance': 'variance_lines', 'fulfillment-status': 'orders', 'pick-list': 'pick_lists', shipment: 'shipments',
        reservation: 'active_reservations', 'lot-trace': 'lots', 'serial-lifecycle': 'serials', 'expiry-risk': 'at_risk', 'recall-impact': 'recall_lines',
    };

    for (const report of reports) {
        const query = report === 'item-ledger' ? `?item_id=${itemId}` : '';
        const exportSuffix = report === 'item-ledger' ? `&item_id=${itemId}` : '';
        const ownerResponse = await pages.owner.request.get(`/inventory/api/v1/reports/${report}${query}`);
        expect(ownerResponse.ok(), report).toBeTruthy();
        const payload = (await ownerResponse.json()).data;
        const rows = payload.rows ?? [];
        expect(Number(payload.summary[countKeys[report]]), `${report} summary count`).toBe(rows.length);
        for (const row of rows) {
            for (const column of payload.columns) expect(row, `${report}.${column}`).toHaveProperty(column);
            for (const [key, value] of Object.entries(row)) {
                if (value !== null && /(?:qty|cost|value|amount|total|variance|movements|days)$/.test(key)) expect(Number.isFinite(Number(value)), `${report}.${key}`).toBeTruthy();
                if (value && /(?:_date|_at)$/.test(key)) expect(Number.isNaN(Date.parse(String(value))), `${report}.${key}`).toBeFalsy();
            }
        }

        for (const [format, mime] of [['csv', 'text/csv'], ['xlsx', 'application/vnd.ms-excel'], ['pdf', 'text/html']]) {
            const exported = await pages.owner.request.get(`/inventory/api/v1/reports/${report}/export?format=${format}${exportSuffix}`);
            expect(exported.headers()['content-type']).toContain(mime);
            const body = await exported.text();
            if (rows.length > 0) {
                const representative = payload.columns
                    .filter((column) => column !== 'id' && !column.endsWith('_id'))
                    .map((column) => rows[0][column])
                    .find((value) => value !== null && String(value).trim().length > 2 && body.includes(String(value)));
                expect(representative, `${report}.${format} exported canonical row value`).toBeTruthy();
                expect(body, `${report}.${format} representative`).toContain(String(representative));
            }
        }

        expect((await pages.manager.request.get(`/inventory/api/v1/reports/${report}${query}`)).status()).toBe(200);
        expect((await pages.manager.request.get(`/inventory/api/v1/reports/${report}/export?format=csv${exportSuffix}`)).status()).toBe(200);
        expect((await pages.viewer.request.get(`/inventory/api/v1/reports/${report}${query}`)).status()).toBe(200);
        expect([403, 404]).toContain((await pages.viewer.request.get(`/inventory/api/v1/reports/${report}/export?format=csv${exportSuffix}`)).status());

        const scoped = await pages.warehouse.request.get(`/inventory/api/v1/reports/${report}${query}`);
        expect(scoped.status()).toBe(200);
        const scopedPayload = (await scoped.json()).data;
        const scopedText = JSON.stringify(scopedPayload);
        expect(scopedPayload.rows?.find((row) => Object.values(row).includes(warehouseB.name)), `${report} leaked Warehouse B row`).toBeUndefined();
        expect(scopedPayload.rows?.find((row) => Object.values(row).includes(warehouseB.code)), `${report} leaked Warehouse B code`).toBeUndefined();
        const tampered = await pages.warehouse.request.get(`/inventory/api/v1/reports/${report}?warehouse_id=2`);
        expect([200, 403, 404]).toContain(tampered.status());
        if (tampered.status() === 200) {
            const tamperedPayload = (await tampered.json()).data;
            expect(tamperedPayload.rows?.find((row) => Object.values(row).includes(warehouseB.name)), `${report} tamper leaked Warehouse B row`).toBeUndefined();
            expect(tamperedPayload.rows?.find((row) => Object.values(row).includes(warehouseB.code)), `${report} tamper leaked Warehouse B code`).toBeUndefined();
        }
        const exportTamper = await pages.warehouse.request.get(`/inventory/api/v1/reports/${report}/export?format=csv&warehouse_id=2`);
        expect([403, 404]).toContain(exportTamper.status());
    }
    await Promise.all(Object.values(contexts).map((context) => context.close()));
});
