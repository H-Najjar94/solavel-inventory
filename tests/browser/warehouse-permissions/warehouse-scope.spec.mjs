import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const warehouseUserId = 91;
const warehouseA = 1;
const warehouseB = 2;

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((v) => v.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(passwordFor(email));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function csrf(page) {
    return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}

test('owner assigns one warehouse and restricted user cannot read or write another', async ({ browser }) => {
    const ownerContext = await browser.newContext();
    const owner = await ownerContext.newPage();
    await login(owner, 'qa.owner@solavel.test');
    await owner.goto('/inventory/settings');
    await expect(owner.getByText('Warehouse user scope')).toBeVisible();
    await owner.getByPlaceholder('e.g. 91').fill(String(warehouseUserId));
    await owner.locator('label.check-inline').filter({ hasText: 'QA-A-100246' }).first().click();
    await owner.getByRole('button', { name: 'Save warehouse scope' }).click();
    await expect(owner.getByText('Warehouse scope saved.')).toBeVisible();
    const assignments = await owner.evaluate(async () => (await fetch('/inventory/api/v1/settings/warehouse-assignments/91')).json());
    expect(assignments.data.assignments.map((row) => row.warehouse_id)).toEqual([warehouseA]);
    await ownerContext.close();

    const restrictedContext = await browser.newContext();
    const restricted = await restrictedContext.newPage();
    await login(restricted, 'qa.warehouse@solavel.test');
    const restrictedMeta = await restricted.evaluate(async () => (await fetch('/inventory/api/v1/meta')).json());
    const warehouses = await restricted.evaluate(async () => (await fetch('/inventory/api/v1/warehouses?per_page=100')).json());
    const rows = warehouses.data?.data ?? warehouses.data ?? [];
    expect(rows.map((row) => row.id)).toEqual([warehouseA]);
    expect(rows.some((row) => row.id === warehouseB)).toBe(false);

    const detail = await restricted.request.get(`/inventory/api/v1/warehouses/${warehouseB}`);
    expect([403, 404]).toContain(detail.status());
    const balances = await restricted.request.get(`/inventory/api/v1/balances?warehouse_id=${warehouseB}`);
    expect(balances.ok()).toBeTruthy();
    const balanceJson = await balances.json();
    expect((balanceJson.data?.data ?? balanceJson.data ?? []).every((row) => Number(row.warehouse_id) !== warehouseB)).toBe(true);

    const token = await csrf(restricted);
    const adjustment = await restricted.request.post('/inventory/api/v1/adjustments', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        data: { warehouse_id: warehouseB, reason_code: 'qa_scope', lines: [{ item_id: 1, quantity: 1, direction: 'in' }] },
    });
    expect([403, 404, 422]).toContain(adjustment.status());
    await restrictedContext.close();
});
