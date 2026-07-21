import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(150_000);
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE13-20260720-${Date.now()}`;
const documentPrefix = `P13-${Date.now()}`;

function password() {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith('qa.owner@solavel.test'));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(password());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function api(page, method, path, body) {
    return page.evaluate(async ({ method, path, body }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(`/inventory/api/v1${path}`, {
            method, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        return { status: response.status, body: await response.json().catch(() => null) };
    }, { method, path, body });
}

async function visibleIncrease(page, itemId, lotCode, expiryDate, quantity) {
    await page.goto('/inventory/adjustments/new');
    await page.getByLabel('Document number').fill(`${documentPrefix}-${lotCode.endsWith('FUTURE') ? 'FUT' : lotCode.endsWith('NEAR') ? 'NEAR' : 'EXP'}-IN`);
    await page.getByLabel('Warehouse').selectOption('1');
    const reason = page.getByLabel('Reason code');
    if (await reason.evaluate((element) => element.tagName === 'SELECT')) await reason.selectOption({ index: 1 });
    else await reason.fill('found');
    const panel = page.locator('.panel').filter({ hasText: 'Lines' });
    await panel.locator('select').nth(1).selectOption(String(itemId));
    await panel.locator('input[type="number"]').first().fill(String(quantity));
    await page.getByLabel('Lot code').fill(lotCode);
    await page.getByLabel('Expiry date').fill(expiryDate);
    await panel.locator('input[type="number"]').last().fill('7');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Adjustment posted.')).toBeVisible();
}

test('deployed manual expiry policy covers selection, blocking, movement, reports and restored warning setting', async ({ page }) => {
    await login(page);
    await page.goto('/inventory/items/new');
    await page.getByLabel('Item name').fill(`${prefix} Expiry Item`);
    await page.getByLabel('SKU').fill(`${prefix}-EXP-ITEM`);
    await page.getByLabel('Costing method').selectOption('fifo');
    await page.getByLabel('Lot', { exact: true }).check();
    await page.getByLabel('Expiry', { exact: true }).check();
    await page.getByRole('button', { name: 'Create item' }).click();
    await expect(page).toHaveURL(/\/inventory\/items\/\d+$/);
    const item = { id: Number(page.url().match(/(\d+)$/)[1]), tracks_expiry: true, tracking_type: 'lot' };
    expect(item?.id).toBeTruthy();
    const today = new Date();
    const iso = (offset) => new Date(today.getTime() + offset * 86400000).toISOString().slice(0, 10);
    const future = `${prefix}-FUTURE`;
    const near = `${prefix}-NEAR`;
    const expired = `${prefix}-EXPIRED`;
    await visibleIncrease(page, item.id, future, iso(180), 3);
    await visibleIncrease(page, item.id, near, iso(5), 3);
    await visibleIncrease(page, item.id, expired, iso(-5), 2);

    const lots = await api(page, 'GET', `/lots?item_id=${item.id}&per_page=100`);
    const rows = lots.body.data?.data ?? lots.body.data ?? [];
    const futureLot = rows.find((row) => row.lot_code === future);
    const nearLot = rows.find((row) => row.lot_code === near);
    const expiredLot = rows.find((row) => row.lot_code === expired);
    expect(futureLot?.id).toBeTruthy();
    expect(nearLot?.id).toBeTruthy();
    expect(expiredLot?.id).toBeTruthy();

    await page.goto(`/inventory/traceability/lots/${expiredLot.id}`);
    await expect(page.getByRole('heading', { name: expired, exact: true })).toBeVisible();
    await expect(page.locator('body')).toContainText(/expired/i);

    const availability = await api(page, 'GET', `/lots-availability?item_id=${item.id}&warehouse_id=1`);
    const availableLots = availability.body.data.lots ?? [];
    expect(availableLots.find((row) => Number(row.lot_id) === Number(expiredLot.id))?.status).toBe('expired');

    await page.goto('/inventory/transfers/new');
    await page.getByLabel('Transfer number').fill(`${documentPrefix}-EXP-TRANSFER`);
    await page.getByLabel('Source warehouse').selectOption('1');
    await page.getByLabel('Destination warehouse').selectOption('2');
    const transferLines = page.locator('.panel').filter({ hasText: 'Lines' });
    await transferLines.locator('select').first().selectOption(String(item.id));
    await page.getByLabel('Lot').selectOption(String(nearLot.id));
    await transferLines.locator('input[type="number"]').last().fill('1');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Transfer posted.')).toBeVisible();

    const wrongWarehouse = await api(page, 'POST', '/transfers', {
        transfer_number: `${documentPrefix}-WRONG-WH`, transfer_date: iso(0), from_warehouse_id: 2, to_warehouse_id: 1,
        lines: [{ item_id: item.id, quantity: 1, lot_id: futureLot.id }],
    });
    expect(wrongWarehouse.status).toBe(201);
    expect((await api(page, 'POST', `/transfers/${wrongWarehouse.body.data.id}/post`)).status).toBe(422);
    const overAllocation = await api(page, 'POST', '/transfers', {
        transfer_number: `${documentPrefix}-OVER`, transfer_date: iso(0), from_warehouse_id: 1, to_warehouse_id: 2,
        lines: [{ item_id: item.id, quantity: 9999, lot_id: futureLot.id }],
    });
    expect(overAllocation.status).toBe(201);
    expect((await api(page, 'POST', `/transfers/${overAllocation.body.data.id}/post`)).status).toBe(422);

    await page.goto('/inventory/adjustments/new');
    await page.getByLabel('Document number').fill(`${documentPrefix}-EXP-OUT`);
    await page.getByLabel('Warehouse').selectOption('1');
    const reason = page.getByLabel('Reason code');
    if (await reason.evaluate((element) => element.tagName === 'SELECT')) await reason.selectOption({ index: 1 });
    const adjustmentLines = page.locator('.panel').filter({ hasText: 'Lines' });
    await adjustmentLines.locator('select').nth(0).selectOption('decrease');
    await adjustmentLines.locator('select').nth(1).selectOption(String(item.id));
    await adjustmentLines.locator('input[type="number"]').first().fill('1');
    await page.getByLabel('Lot').selectOption(String(futureLot.id));
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Adjustment posted.')).toBeVisible();

    await page.goto('/inventory/sales-orders/new');
    await page.getByLabel('Order number').fill(`${documentPrefix}-EXP-SO`);
    await page.getByLabel('Customer name').fill('Phase 13 Expiry Customer');
    await page.getByLabel('Warehouse').selectOption('1');
    const orderLines = page.locator('.panel').filter({ hasText: 'Lines' });
    await orderLines.locator('select').first().selectOption(String(item.id));
    await orderLines.locator('input[type="number"]').nth(0).fill('1');
    await orderLines.locator('input[type="number"]').nth(1).fill('11');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Sales order confirmed.')).toBeVisible();
    await page.getByRole('button', { name: 'Reserve stock' }).click();
    await expect(page.getByText('Stock reserved.')).toBeVisible();
    await page.getByRole('button', { name: 'Create pick list' }).click();
    await page.getByRole('button', { name: 'Mark picked' }).click();
    await expect(page.getByText('Pick list marked picked.')).toBeVisible();
    await page.getByRole('button', { name: 'Create pack' }).click();
    await page.getByRole('button', { name: 'Mark packed' }).click();
    await expect(page.getByText('Pack marked packed.')).toBeVisible();
    await page.getByRole('link', { name: 'Go to order to ship' }).click();
    await page.getByRole('button', { name: 'Create shipment' }).click();
    const shipmentLot = page.getByLabel('Lot', { exact: true });
    await expect(shipmentLot.locator(`option[value="${expiredLot.id}"]`)).toBeDisabled();
    await shipmentLot.selectOption(String(nearLot.id));
    const saveLot = page.getByRole('button', { name: 'Save lot/serial' });
    if (await saveLot.isVisible()) await saveLot.click();
    await page.getByRole('button', { name: 'Post shipment' }).click();
    await page.locator('.modal').getByRole('button', { name: /Post/i }).click();
    await expect(page.getByText('Shipment posted — stock shipped OUT.')).toBeVisible();

    await page.goto('/inventory/counts/new');
    await page.getByLabel('Count number').fill(`${documentPrefix}-EXP-COUNT`);
    await page.getByLabel('Warehouse').selectOption('1');
    await page.getByRole('button', { name: 'Prefill expected from stock' }).click();
    const countRow = page.locator('tbody tr').filter({ hasText: near }).first();
    await expect(countRow).toBeVisible();
    const expectedQty = (await countRow.locator('td').nth(3).innerText()).match(/[\d.]+/)?.[0];
    await countRow.locator('input[type="number"]').fill(expectedQty);
    await page.getByRole('button', { name: 'Save & post variance' }).click();
    await expect(page.getByText('Count posted — variance adjustment created.')).toBeVisible({ timeout: 30_000 });

    await page.goto('/inventory/settings');
    const original = Number(await page.getByLabel('Expiry warning days').inputValue());
    const changed = original === 12 ? 13 : 12;
    await page.getByLabel('Expiry warning days').fill(String(changed));
    await page.getByLabel('Expiry warning days').blur();
    await expect(page.getByLabel('Expiry warning days')).toHaveValue(String(changed));
    await page.waitForTimeout(50); // allow React's controlled-state render before the separate click event
    const [saveResponse] = await Promise.all([
        page.waitForResponse((response) => response.url().includes('/inventory/api/v1/settings') && response.request().method() === 'PUT'),
        page.getByRole('button', { name: 'Save policy' }).click(),
    ]);
    expect(saveResponse.status()).toBe(200);
    expect(Number((await saveResponse.json()).data.expiry_warning_days)).toBe(changed);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Expiry warning days')).toHaveValue(String(changed));
    const risk = await api(page, 'GET', `/traceability/expiry-risk?within_days=${changed}&warehouse_id=1`);
    expect(risk.status).toBe(200);
    expect((risk.body.data.lots ?? []).some((row) => row.lot_code === near)).toBeTruthy();
    const report = await api(page, 'GET', `/reports/expiry-risk?warehouse_id=1&within_days=${changed}`);
    expect(report.status).toBe(200);
    const reportRows = report.body.data.rows ?? [];
    expect(reportRows.some((row) => Object.values(row).includes(near))).toBeTruthy();
    const csv = await page.request.get(`/inventory/api/v1/reports/expiry-risk/export?format=csv&warehouse_id=1&within_days=${changed}`);
    expect(await csv.text()).toContain(near);

    await page.goto('/inventory/settings');
    await page.getByLabel('Expiry warning days').fill(String(original));
    await page.getByLabel('Expiry warning days').blur();
    await expect(page.getByLabel('Expiry warning days')).toHaveValue(String(original));
    await page.waitForTimeout(50);
    const [restoreResponse] = await Promise.all([
        page.waitForResponse((response) => response.url().includes('/inventory/api/v1/settings') && response.request().method() === 'PUT'),
        page.getByRole('button', { name: 'Save policy' }).click(),
    ]);
    expect(restoreResponse.status()).toBe(200);
    expect(Number((await restoreResponse.json()).data.expiry_warning_days)).toBe(original);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Expiry warning days')).toHaveValue(String(original));
});
