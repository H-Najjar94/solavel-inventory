import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const prefix = `QA-SOLASTOCK-PHASE4-20260719-${Date.now()}`;

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((v) => v.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(passwordFor('qa.owner@solavel.test'));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function api(page, method, path, body) {
    return page.evaluate(async ({ method, path, body }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(`/inventory/api/v1${path}`, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        return { status: response.status, body: await response.json().catch(() => null) };
    }, { method, path, body });
}

test('QA purchase, receipt, reservation and shipment lifecycle creates reconciliable documents', async ({ page }) => {
    await login(page);
    const [items, suppliers, customers] = await Promise.all([
        api(page, 'GET', '/items?per_page=100'),
        api(page, 'GET', '/suppliers?per_page=100'),
        api(page, 'GET', '/customers?per_page=100'),
    ]);
    expect(items.status).toBe(200); expect(suppliers.status).toBe(200); expect(customers.status).toBe(200);
    const itemRows = items.body.data?.data ?? items.body.data ?? [];
    const supplierRows = suppliers.body.data?.data ?? suppliers.body.data ?? [];
    const customerRows = customers.body.data?.data ?? customers.body.data ?? [];
    const item = itemRows.find((row) => row.item_type === 'inventory') ?? itemRows[0];
    const supplier = supplierRows[0];
    const customer = customerRows[0];
    expect(item?.id).toBeTruthy();

    const po = await api(page, 'POST', '/purchase-orders', {
        warehouse_id: 1, supplier_id: supplier?.id ?? null, order_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ item_id: item.id, ordered_qty: 2, entered_qty: 2, unit_price: 10 }],
    });
    expect(po.status).toBe(201);
    const poId = po.body.data.id;
    const approve = await api(page, 'POST', `/purchase-orders/${poId}/approve`);
    expect(approve.status).toBe(200);
    const poDetail = await api(page, 'GET', `/purchase-orders/${poId}`);
    const poLine = poDetail.body.data.lines[0];

    const grn = await api(page, 'POST', '/goods-receipts', {
        purchase_order_id: poId, supplier_id: supplier?.id ?? null, warehouse_id: 1, receipt_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ purchase_order_line_id: poLine.id, item_id: item.id, received_qty: 2, accepted_qty: 2, entered_qty: 2, unit_cost: 10, lot_code: `${prefix}-BATCH-A` }],
    });
    expect(grn.status).toBe(201);
    const grnId = grn.body.data.id;
    const posted = await api(page, 'POST', `/goods-receipts/${grnId}/post`);
    expect(posted.status).toBe(200);

    const balances = await api(page, 'GET', `/balances?warehouse_id=1&item_id=${item.id}`);
    expect(balances.status).toBe(200);
    const balanceRows = balances.body.data?.data ?? balances.body.data ?? [];
    expect(balanceRows.some((row) => Number(row.item_id) === Number(item.id) && Number(row.warehouse_id) === 1)).toBe(true);

    const so = await api(page, 'POST', '/sales-orders', {
        warehouse_id: 1, customer_id: customer?.id ?? null, customer_name: customer?.name ?? `${prefix} Customer`, order_date: new Date().toISOString().slice(0, 10),
        lines: [{ item_id: item.id, ordered_qty: 1, unit_price: 20, tax_rate: 0 }],
    });
    expect(so.status).toBe(201);
    const soId = so.body.data.id;
    const soNumber = so.body.data.order_number;
    expect((await api(page, 'POST', `/sales-orders/${soId}/confirm`)).status).toBe(200);
    expect((await api(page, 'POST', `/sales-orders/${soId}/reserve`, {})).status).toBe(200);
    const pick = await api(page, 'POST', '/pick-lists', { sales_order_id: soId, pick_number: `${prefix}-PICK`, warehouse_id: 1 });
    expect(pick.status).toBe(201);
    const picked = await api(page, 'POST', `/pick-lists/${pick.body.data.id}/picked`);
    expect(picked.status).toBe(200);
    const pack = await api(page, 'POST', '/packs', { pick_list_id: pick.body.data.id, pack_number: `${prefix}-PACK`, package_count: 1 });
    expect(pack.status).toBe(201);
    expect((await api(page, 'POST', `/packs/${pack.body.data.id}/packed`)).status).toBe(200);
    const shipment = await api(page, 'POST', '/shipments', {
        shipment_number: `${prefix}-SHIP`, sales_order_id: soId, pack_id: pack.body.data.id, warehouse_id: 1,
        lines: [{ item_id: item.id, quantity: 1, warehouse_id: 1 }],
    });
    expect(shipment.status).toBe(201);
    const shipped = await api(page, 'POST', `/shipments/${shipment.body.data.id}/post`);
    expect(shipped.status).toBe(200);

    await page.goto(`/inventory/sales-orders/${soId}`);
    await expect(page.locator('h1')).toHaveText(soNumber);
    await expect(page.locator('body')).toContainText(/shipped/i);
});
