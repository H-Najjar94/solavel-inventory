import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(120_000);
const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const documentPrefix = `P13-TAX-${Date.now()}`;

function password(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email = 'qa.owner@solavel.test') {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password(email));
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

test('visible mixed purchase and sale preserve snapshots across rate edit and deactivation', async ({ page }) => {
    await login(page);
    const originalPurchaseDefault = '';
    const originalSalesDefault = '';
    const items = await api(page, 'GET', '/items?per_page=100&is_active=true');
    const rows = items.body.data?.data ?? items.body.data ?? [];
    const item = rows.find((row) => !row.tracking_type || row.tracking_type === 'none');
    expect(item?.id).toBeTruthy();

    await page.goto('/inventory/settings');
    await page.getByLabel('Default purchase tax').selectOption('QA12-ZERO');
    await page.getByLabel('Default sales tax').selectOption('QA12-ZERO');
    await page.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.').last()).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Default purchase tax')).toHaveValue('QA12-ZERO');
    await expect(page.getByLabel('Default sales tax')).toHaveValue('QA12-ZERO');

    await page.goto('/inventory/purchase-orders/new');
    await expect(page.getByLabel('Purchase tax line 1')).toHaveValue('QA12-ZERO');
    await page.getByLabel('PO number').fill(`${documentPrefix}-PO`);
    await page.getByLabel('Warehouse').selectOption('1');
    const poPanel = page.locator('.panel').filter({ hasText: 'Lines' });
    for (let index = 0; index < 3; index++) {
        if (index > 0) await page.getByRole('button', { name: '+ Add line' }).click();
        const row = poPanel.locator('tbody tr').nth(index);
        await row.locator('select').first().selectOption(String(item.id));
        await row.locator('input[type="number"]').nth(0).fill(index === 0 ? '2' : '1');
        await row.locator('input[type="number"]').nth(1).fill(index === 0 ? '10' : index === 1 ? '5' : '7');
        await page.getByLabel(`Purchase tax line ${index + 1}`).selectOption(index === 0 ? 'QA12-STD16' : index === 1 ? 'QA12-ZERO' : 'QA12-EXEMPT');
    }
    await page.getByRole('button', { name: '+ Add line' }).click();
    await poPanel.locator('tbody tr').nth(3).getByRole('button').click();
    await expect(poPanel).toContainText('35.20');
    await page.getByRole('button', { name: 'Create PO' }).click();
    await expect(page).toHaveURL(/\/inventory\/purchase-orders\/\d+$/);
    const poId = Number(page.url().match(/(\d+)$/)[1]);
    await expect(page.locator('.kv')).toContainText('3.20');
    await expect(page.locator('.kv')).toContainText('35.20');

    await page.goto('/inventory/sales-orders/new');
    await expect(page.getByLabel('Sales tax line 1')).toHaveValue('QA12-ZERO');
    await page.getByLabel('Order number').fill(`${documentPrefix}-SO`);
    await page.getByLabel('Customer name').fill('Phase 13 Mixed Tax Customer');
    await page.getByLabel('Warehouse').selectOption('1');
    const soPanel = page.locator('.panel').filter({ hasText: 'Lines' });
    for (let index = 0; index < 3; index++) {
        if (index > 0) await page.getByRole('button', { name: '+ Add line' }).click();
        const row = soPanel.locator('tbody tr').nth(index);
        await row.locator('select').first().selectOption(String(item.id));
        await row.locator('input[type="number"]').nth(0).fill(index === 0 ? '2' : '1');
        await row.locator('input[type="number"]').nth(1).fill(index === 0 ? '10' : index === 1 ? '5' : '7');
        await row.locator('input[type="number"]').nth(2).fill(index === 0 ? '10' : '0');
        await page.getByLabel(`Sales tax line ${index + 1}`).selectOption(index === 0 ? 'QA12-STD16' : index === 1 ? 'QA12-ZERO' : 'QA12-EXEMPT');
    }
    await page.getByRole('button', { name: '+ Add line' }).click();
    await soPanel.locator('tbody tr').nth(3).getByRole('button').click();
    await expect(soPanel).toContainText('20.88');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/sales-orders\/\d+$/);
    const soId = Number(page.url().match(/(\d+)$/)[1]);
    await expect(page.locator('.kv')).toContainText('2.88');
    await expect(page.locator('.kv')).toContainText('32.88');

    const poBefore = (await api(page, 'GET', `/purchase-orders/${poId}`)).body.data.purchase_order;
    const soBefore = (await api(page, 'GET', `/sales-orders/${soId}`)).body.data.sales_order;
    expect(poBefore.lines.map((line) => line.tax_treatment)).toEqual(['standard', 'zero', 'exempt']);
    expect(soBefore.lines.map((line) => line.tax_treatment)).toEqual(['standard', 'zero', 'exempt']);
    expect(Number(poBefore.tax_total)).toBe(3.2);
    expect(Number(soBefore.tax_total)).toBe(2.88);

    await page.goto('/inventory/settings');
    await page.getByLabel('Tax rate QA12-STD16').fill('17');
    await page.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.').last()).toBeVisible();
    expect(Number((await api(page, 'GET', `/purchase-orders/${poId}`)).body.data.purchase_order.tax_total)).toBe(3.2);
    expect(Number((await api(page, 'GET', `/sales-orders/${soId}`)).body.data.sales_order.tax_total)).toBe(2.88);

    await page.getByLabel('Tax active QA12-STD16').uncheck();
    await page.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.').last()).toBeVisible();
    const rejected = await api(page, 'POST', '/purchase-orders', {
        warehouse_id: 1, order_date: new Date().toISOString().slice(0, 10),
        lines: [{ item_id: item.id, ordered_qty: 1, unit_price: 10, tax_code: 'QA12-STD16' }],
    });
    expect(rejected.status).toBe(422);
    const unknown = await api(page, 'POST', '/sales-orders', {
        order_number: `${documentPrefix}-UNKNOWN`, warehouse_id: 1, order_date: new Date().toISOString().slice(0, 10),
        lines: [{ item_id: item.id, ordered_qty: 1, unit_price: 10, tax_code: 'UNKNOWN' }],
    });
    expect(unknown.status).toBe(422);

    await page.getByLabel('Tax rate QA12-STD16').fill('16');
    await page.getByLabel('Tax active QA12-STD16').check();
    await page.getByLabel('Default purchase tax').selectOption(originalPurchaseDefault);
    await page.getByLabel('Default sales tax').selectOption(originalSalesDefault);
    await page.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.').last()).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Tax rate QA12-STD16')).toHaveValue('16');
    await expect(page.getByLabel('Tax active QA12-STD16')).toBeChecked();
});

test('tax administration and document mutation follow role permissions', async ({ browser }) => {
    const managerContext = await browser.newContext();
    const viewerContext = await browser.newContext();
    const manager = await managerContext.newPage();
    const viewer = await viewerContext.newPage();
    await Promise.all([login(manager, 'qa.manager@solavel.test'), login(viewer, 'qa.viewer@solavel.test')]);
    expect((await api(manager, 'GET', '/settings')).status).toBe(200);
    expect((await api(manager, 'PUT', '/settings/taxes', { taxes: [] })).status).toBe(403);
    expect((await api(viewer, 'GET', '/settings')).status).toBe(200);
    expect((await api(viewer, 'POST', '/purchase-orders', {})).status).toBe(403);
    await manager.goto('/inventory/purchase-orders/new');
    await expect(manager.getByRole('heading', { name: 'New purchase order' })).toBeVisible();
    await viewer.goto('/inventory/purchase-orders/new');
    await expect(viewer.locator('body')).toContainText(/permission|not allowed|unavailable/i);
    await Promise.all([managerContext.close(), viewerContext.close()]);
});
