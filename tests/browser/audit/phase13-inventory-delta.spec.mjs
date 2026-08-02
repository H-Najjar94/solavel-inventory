import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(120_000);
test.use({ trace: 'off', screenshot: 'off', video: 'off' });

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');

function password() {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/)
        .find((value) => value.startsWith('qa.owner@solavel.test'));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(password());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function allRows(page, path, perPage = 200) {
    const rows = [];
    for (let current = 1; ; current += 1) {
        const separator = path.includes('?') ? '&' : '?';
        const response = await page.request.get(`${path}${separator}per_page=${perPage}&page=${current}`);
        expect(response.ok(), path).toBeTruthy();
        const payload = await response.json();
        rows.push(...(payload.data?.data ?? payload.data ?? []));
        if (current >= Number(payload.meta?.last_page ?? 1)) return rows;
    }
}

test('the complete immutable ledger reconciles to balances and FIFO layers', async ({ page }) => {
    await login(page);
    const [ledger, balances, items] = await Promise.all([
        allRows(page, '/inventory/api/v1/ledger'),
        allRows(page, '/inventory/api/v1/balances', 100),
        allRows(page, '/inventory/api/v1/items', 100),
    ]);
    const itemById = new Map(items.map((item) => [Number(item.id), item]));
    const keys = new Set([
        ...ledger.map((row) => `${row.item_id}:${row.warehouse_id}`),
        ...balances.map((row) => `${row.item_id}:${row.warehouse_id}`),
    ]);

    for (const key of keys) {
        const [itemId, warehouseId] = key.split(':').map(Number);
        const movements = ledger.filter((row) => Number(row.item_id) === itemId && Number(row.warehouse_id) === warehouseId);
        const coordinates = balances.filter((row) => Number(row.item_id) === itemId && Number(row.warehouse_id) === warehouseId);
        const expectedQty = Number(movements.reduce((sum, row) => sum + (row.direction === 'in' ? 1 : -1) * Number(row.quantity), 0).toFixed(4));
        const expectedValue = Number(movements.reduce((sum, row) => sum + (row.direction === 'in' ? 1 : -1) * Number(row.total_cost), 0).toFixed(2));
        const actualQty = Number(coordinates.reduce((sum, row) => sum + Number(row.on_hand_qty), 0).toFixed(4));
        const actualValue = Number(coordinates.reduce((sum, row) => sum + Number(row.total_value), 0).toFixed(2));
        expect(actualQty - expectedQty, `${key} quantity`).toBe(0);
        expect(actualValue - expectedValue, `${key} value`).toBe(0);
    }

    for (const item of items.filter((row) => row.costing_method === 'fifo')) {
        const response = await page.request.get(`/inventory/api/v1/items/${item.id}/valuation`);
        expect(response.ok(), `valuation ${item.id}`).toBeTruthy();
        const valuation = (await response.json()).data;
        for (const warehouse of valuation.warehouses) {
            expect(warehouse.qty_reconciled, `${item.sku} warehouse ${warehouse.warehouse_id}`).toBeTruthy();
            expect(Number(warehouse.fifo_value) - Number(warehouse.average_value), `${item.sku} warehouse ${warehouse.warehouse_id} FIFO value`).toBe(0);
        }
    }

    const reservations = await page.request.get('/inventory/api/v1/reports/reservation');
    expect(reservations.ok()).toBeTruthy();
    const reservationData = (await reservations.json()).data;
    const activeReservations = (reservationData.rows ?? []).filter((row) => row.status === 'active');
    expect(Number(reservationData.summary.active_reservations)).toBe(activeReservations.length);
    expect(ledger.length).toBeGreaterThan(0);
    expect(itemById.size).toBeGreaterThan(0);
});
