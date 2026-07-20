import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const warehouseBName = 'QA Reserve Warehouse 100246';
const warehouseBCode = 'QA-B-100246';

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

test('warehouse A-only user cannot observe warehouse B across material read surfaces', async ({ page }) => {
    await login(page);

    const paths = [
        '/warehouses/2',
        '/balances?warehouse_id=2',
        '/balances?warehouse_id[]=1&warehouse_id[]=2',
        '/ledger?warehouse_id=2',
        '/purchase-orders?warehouse_id=2',
        '/goods-receipts?warehouse_id=2',
        '/sales-orders?warehouse_id=2',
        '/reservations?warehouse_id=2',
        '/pick-lists?warehouse_id=2',
        '/packs?warehouse_id=2',
        '/shipments?warehouse_id=2',
        '/transfers?warehouse_id=2',
        '/adjustments?warehouse_id=2',
        '/counts?warehouse_id=2',
        '/lots?warehouse_id=2',
        '/serials?warehouse_id=2',
        '/expiry-report?warehouse_id=2',
        '/traceability/expiry-risk?warehouse_id=2',
        '/traceability/lots/availability?warehouse_id=2',
        '/traceability/serials/availability?warehouse_id=2',
        '/dashboard?warehouse_id=2',
        '/reports?warehouse_id=2',
        '/items/24/movements?warehouse_id=2',
        '/items/24/valuation?warehouse_id=2',
        '/transfers/5',
        '/adjustments/6',
        '/counts/2',
        '/lots/3',
        '/serials/2',
    ];

    for (const path of paths) {
        const response = await page.request.get(`/inventory/api/v1${path}`);
        expect(response.status(), path).not.toBe(500);
        const body = await response.text();
        expect(body, path).not.toContain(warehouseBName);
        expect(body, path).not.toContain(warehouseBCode);
    }

    for (const query of [
        '?warehouse_id=2',
        '?warehouse_id[]=1&warehouse_id[]=2',
        '?warehouse_ids=1,2',
        '?warehouse_id=2&warehouse_id=1',
        '?warehouse_id=999999',
    ]) {
        const response = await page.request.get(`/inventory/api/v1/balances${query}`);
        expect(response.status(), query).not.toBe(500);
        const body = await response.text();
        expect(body, query).not.toContain(warehouseBName);
        expect(body, query).not.toContain(warehouseBCode);
    }
});
