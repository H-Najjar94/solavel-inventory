import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(300_000);
test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const evidenceFile = '/tmp/solastock-practical-verification-20260719/18-phase14-final/signed-source-reversals.json';
const prefix = `QA-SOLASTOCK-PHASE14-20260721-${Date.now()}`;
const accountCodes = ['1200', '1301', '1703', '2100', '2201', '4101', '5001', '6804'];

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
    if (!page.url().includes('/inventory/')) await page.goto('/inventory/dashboard');
    return page.evaluate(async ({ method, path, body }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/inventory/api/v1' + path, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        return { status: response.status, body: await response.json().catch(() => null) };
    }, { method, path, body });
}

function rows(result) {
    return result.body?.data?.data ?? result.body?.data ?? [];
}

function amount(value) {
    if (!value || value.trim() === '-') return 0;
    return Number(value.replace(/,/g, '').replace(/[^\d.-]/g, '')) || 0;
}

async function trialBalance(page) {
    await page.goto('/finance/reports/trial-balance');
    await expect(page.locator('body')).toContainText(/Trial Balance|ميزان المراجعة/i);
    const table = await page.locator('tbody tr').evaluateAll((elements) => elements.map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim())));
    const result = Object.fromEntries(accountCodes.map((code) => [code, { debit: 0, credit: 0 }]));
    for (const cells of table) {
        if (cells.length >= 4 && accountCodes.includes(cells[1])) result[cells[1]] = { debit: amount(cells[2]), credit: amount(cells[3]) };
    }
    return result;
}

async function createItem(page, suffix, purchasePrice, salesPrice) {
    const result = await api(page, 'POST', '/items', {
        sku: `${prefix}-${suffix}`,
        name: `${prefix} ${suffix}`,
        item_type: 'inventory', tracking_type: 'none', costing_method: 'fifo',
        purchase_price: purchasePrice, sales_price: salesPrice, is_active: true,
    });
    expect(result.status).toBe(201);
    return result.body.data;
}

async function adjustment(page, itemId, reasonCode, direction, quantity, unitCost, suffix) {
    const created = await api(page, 'POST', '/adjustments', {
        adjustment_number: `P14-${Date.now().toString().slice(-8)}-${suffix}`,
        adjustment_date: new Date().toISOString().slice(0, 10), warehouse_id: 1,
        reason_code: reasonCode, notes: prefix,
        lines: [{ item_id: itemId, direction, quantity, unit_cost: unitCost }],
    });
    expect(created.status).toBe(201);
    expect((await api(page, 'POST', `/adjustments/${created.body.data.id}/post`)).status).toBe(200);
    return { id: created.body.data.id, number: created.body.data.adjustment_number };
}

async function deliver(page, eventType, aggregateId) {
    const listed = await api(page, 'GET', `/integration/solabooks/events?status=pending&event_type=${eventType}&per_page=100`);
    const event = rows(listed).find((row) => Number(row.aggregate_id) === Number(aggregateId));
    expect(event?.id, `${eventType} event for ${aggregateId}`).toBeTruthy();
    const delivered = await api(page, 'POST', `/integration/solabooks/events/${event.id}/retry`);
    expect(delivered.status).toBe(200);
    expect(delivered.body.data.status).toBe('sent');
    expect(delivered.body.data.external_document_id).toBeTruthy();
    return { event_id: event.id, event_uuid: event.event_uuid, journal_id: delivered.body.data.external_document_id };
}

async function balance(page, itemId) {
    const response = await api(page, 'GET', `/balances?item_id=${itemId}&warehouse_id=1`);
    const row = rows(response).find((value) => Number(value.item_id) === Number(itemId));
    return Number(row?.on_hand_qty ?? 0);
}

async function visibleReverse(page, path, reason) {
    await page.goto(path);
    await expect(page.getByRole('button', { name: 'Reverse', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reverse', exact: true }).click();
    await page.getByPlaceholder('Explain why this source document is being reversed').fill(reason);
    await page.locator('.modal').getByRole('button', { name: 'Reverse', exact: true }).click();
    await expect(page.locator('body')).toContainText(/reversed/i);
}

test('signed originals and source-driven reversals net Inventory and Finance to zero', async ({ page, browser }) => {
    fs.mkdirSync(new URL('.', `file://${evidenceFile}`).pathname, { recursive: true });
    const browserFailures = [];
    page.on('response', (response) => {
        if (response.status() >= 500) browserFailures.push({ status: response.status(), url: response.url().split('?')[0] });
    });

    await login(page);
    await page.goto('/inventory/settings/solabooks');
    const rotate = page.getByRole('button', { name: /(?:Generate|Rotate) signing key/ });
    await expect(rotate).toBeEnabled();
    await rotate.click();
    await expect(page.getByText('Signing key generated and transferred securely.')).toBeVisible();
    await expect(page.getByText('v1', { exact: true })).toBeVisible();
    const signing = (await api(page, 'GET', '/integration/solabooks/status')).body.data.signing;
    expect(signing.configured).toBe(true);
    expect(signing.protocol_version).toBe('v1');
    expect(signing).not.toHaveProperty('secret');

    await page.goto('/sso/finance/redirect?organization_id=29');
    await expect(page).toHaveURL(/\/finance\//);
    const financeBefore = await trialBalance(page);
    await page.goto('/inventory/dashboard');

    const settings = (await api(page, 'GET', '/settings')).body.data.settings;
    const reasonCode = settings.adjustment_reason_codes.find((code) => code.active !== false)?.code;
    expect(reasonCode).toBeTruthy();
    const [supplierResponse, customerResponse] = await Promise.all([
        api(page, 'GET', '/suppliers?per_page=1'), api(page, 'GET', '/customers?per_page=1'),
    ]);
    const supplier = rows(supplierResponse)[0];
    let customer = rows(customerResponse)[0];
    expect(supplier?.id).toBeTruthy();
    if (!customer) {
        const createdCustomer = await api(page, 'POST', '/customers', {
            code: `P14C-${Date.now()}`, name: `${prefix} Customer`, is_active: true,
        });
        expect(createdCustomer.status).toBe(201);
        customer = createdCustomer.body.data;
    }
    expect(customer?.id).toBeTruthy();

    // GRN source and visible source-driven reversal.
    const grnItem = await createItem(page, 'GRN-ITEM', 10, 20);
    const po = await api(page, 'POST', '/purchase-orders', {
        warehouse_id: 1, supplier_id: supplier.id, order_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ item_id: grnItem.id, ordered_qty: 2, entered_qty: 2, unit_price: 10, tax_code: 'QA12-STD16' }],
    });
    expect(po.status).toBe(201);
    expect((await api(page, 'POST', `/purchase-orders/${po.body.data.id}/approve`)).status).toBe(200);
    const poLine = (await api(page, 'GET', `/purchase-orders/${po.body.data.id}`)).body.data.lines[0];
    const grn = await api(page, 'POST', '/goods-receipts', {
        grn_number: `${prefix}-GRN`, purchase_order_id: po.body.data.id, supplier_id: supplier.id,
        warehouse_id: 1, receipt_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ purchase_order_line_id: poLine.id, item_id: grnItem.id, received_qty: 2, accepted_qty: 2, entered_qty: 2, unit_cost: 10 }],
    });
    expect(grn.status).toBe(201);
    expect((await api(page, 'POST', `/goods-receipts/${grn.body.data.id}/post`)).status).toBe(200);
    expect(await balance(page, grnItem.id)).toBe(2);
    const grnOriginal = await deliver(page, 'grn.posted', grn.body.data.id);
    await visibleReverse(page, `/inventory/goods-receipts/${grn.body.data.id}`, `${prefix} incorrect receipt`);
    const grnDetail = (await api(page, 'GET', `/goods-receipts/${grn.body.data.id}`)).body.data.grn;
    expect(grnDetail.reversal.id).toBeTruthy();
    expect(await balance(page, grnItem.id)).toBe(0);
    const grnReversal = await deliver(page, 'grn.reversed', grnDetail.reversal.id);

    // Negative adjustment source and visible reversal.
    const adjustmentItem = await createItem(page, 'ADJ-ITEM', 7, 14);
    await adjustment(page, adjustmentItem.id, reasonCode, 'increase', 3, 7, 'ADJ-SEED');
    const negative = await adjustment(page, adjustmentItem.id, reasonCode, 'decrease', 1, null, 'ADJ-LOSS');
    expect(await balance(page, adjustmentItem.id)).toBe(2);
    const adjustmentOriginal = await deliver(page, 'adjustment.posted', negative.id);
    await visibleReverse(page, `/inventory/adjustments/${negative.id}`, `${prefix} incorrect shrinkage`);
    const adjustmentDetail = (await api(page, 'GET', `/adjustments/${negative.id}`)).body.data.adjustment;
    expect(adjustmentDetail.reversal.id).toBeTruthy();
    expect(await balance(page, adjustmentItem.id)).toBe(3);
    const adjustmentReversal = await deliver(page, 'adjustment.reversed', adjustmentDetail.reversal.id);

    // Shipment source and a visible immutable source return.
    const shipmentItem = await createItem(page, 'SHIP-ITEM', 11, 22);
    await adjustment(page, shipmentItem.id, reasonCode, 'increase', 2, 11, 'SHIP-SEED');
    const so = await api(page, 'POST', '/sales-orders', {
        order_number: `${prefix}-SO`, warehouse_id: 1, customer_id: customer.id, customer_name: customer.name,
        order_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ item_id: shipmentItem.id, ordered_qty: 1, unit_price: 22, tax_code: 'QA12-STD16' }],
    });
    expect(so.status).toBe(201);
    expect((await api(page, 'POST', `/sales-orders/${so.body.data.id}/confirm`)).status).toBe(200);
    expect((await api(page, 'POST', `/sales-orders/${so.body.data.id}/reserve`, {})).status).toBe(200);
    const soLine = (await api(page, 'GET', `/sales-orders/${so.body.data.id}`)).body.data.sales_order.lines[0];
    const pick = await api(page, 'POST', '/pick-lists', { sales_order_id: so.body.data.id, pick_number: `${prefix}-PICK`, warehouse_id: 1 });
    expect(pick.status).toBe(201);
    expect((await api(page, 'POST', `/pick-lists/${pick.body.data.id}/picked`)).status).toBe(200);
    const pack = await api(page, 'POST', '/packs', { pick_list_id: pick.body.data.id, pack_number: `${prefix}-PACK`, package_count: 1 });
    expect(pack.status).toBe(201);
    expect((await api(page, 'POST', `/packs/${pack.body.data.id}/packed`)).status).toBe(200);
    const shipment = await api(page, 'POST', '/shipments', {
        shipment_number: `${prefix}-SHIP`, sales_order_id: so.body.data.id, pack_id: pack.body.data.id, warehouse_id: 1,
        lines: [{ sales_order_line_id: soLine.id, item_id: shipmentItem.id, quantity: 1, warehouse_id: 1 }],
    });
    expect(shipment.status).toBe(201);
    expect((await api(page, 'POST', `/shipments/${shipment.body.data.id}/post`)).status).toBe(200);
    expect(await balance(page, shipmentItem.id)).toBe(1);
    const shipmentOriginal = await deliver(page, 'shipment.posted', shipment.body.data.id);

    await page.goto(`/inventory/shipments/${shipment.body.data.id}`);
    await page.getByRole('button', { name: 'Create source reversal' }).click();
    await expect(page.getByText('Source reversal quantities, warehouse, lot/serial identity, and cost are locked')).toBeVisible();
    await expect(page.locator('.field').filter({ hasText: 'Warehouse' })).toContainText('#1 (from shipment)');
    await expect(page.getByText('Returned qty').locator('xpath=ancestor::table').locator('tbody tr').first()).toContainText('1');
    await page.getByLabel('Return number').fill(`${prefix}-RETURN`);
    await page.getByLabel('Reason').fill(`${prefix} shipment reversal`);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/sales-returns\/\d+$/);
    const returnId = Number(page.url().match(/(\d+)$/)[1]);
    await page.getByRole('button', { name: 'Authorize RMA' }).click();
    await expect(page.getByText('RMA authorized.')).toBeVisible();
    await page.getByRole('button', { name: 'Mark inspected' }).click();
    await expect(page.getByText('Return inspected.')).toBeVisible();
    await page.getByRole('button', { name: 'Post return' }).click();
    await page.locator('.modal').getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.getByText('Return posted — eligible stock brought back in.')).toBeVisible();
    expect(await balance(page, shipmentItem.id)).toBe(2);
    const shipmentReversal = await deliver(page, 'sales_return.posted', returnId);

    // Viewer must not obtain reversal authority through either UI or direct API.
    const viewerContext = await browser.newContext();
    const viewer = await viewerContext.newPage();
    await login(viewer, 'qa.viewer@solavel.test');
    await viewer.goto(`/inventory/goods-receipts/${grn.body.data.id}`);
    await expect(viewer.getByRole('button', { name: 'Reverse', exact: true })).toHaveCount(0);
    expect([403, 404]).toContain((await api(viewer, 'POST', `/goods-receipts/${grn.body.data.id}/reverse`, { reason: 'viewer bypass' })).status);
    await viewerContext.close();

    // Every compensating journal links back to its exact delivered original.
    const journalPairs = [
        { source: grn.body.data.grn_number, original: grnOriginal, reversal: grnReversal },
        { source: negative.number, original: adjustmentOriginal, reversal: adjustmentReversal },
        { source: shipment.body.data.shipment_number, original: shipmentOriginal, reversal: shipmentReversal },
    ];
    await page.goto('/sso/finance/redirect?organization_id=29');
    for (const pair of journalPairs) {
        await page.goto(`/finance/entries/${pair.original.journal_id}`);
        await expect(page.locator('body')).toContainText(pair.source);
        const originalJournalNumber = (await page.locator('body').innerText()).match(/JE-\d+/)?.[0];
        expect(originalJournalNumber).toBeTruthy();
        await page.goto(`/finance/entries/${pair.reversal.journal_id}`);
        await expect(page.locator('body')).toContainText(/Reversal|عكس/i);
        await expect(page.locator('body')).toContainText(originalJournalNumber);
        await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);
    }

    const financeAfter = await trialBalance(page);
    const financeDifference = {};
    for (const code of accountCodes) {
        financeDifference[code] = {
            debit: Number((financeAfter[code].debit - financeBefore[code].debit).toFixed(2)),
            credit: Number((financeAfter[code].credit - financeBefore[code].credit).toFixed(2)),
        };
        expect(financeDifference[code].debit, `${code} debit net`).toBe(0);
        expect(financeDifference[code].credit, `${code} credit net`).toBe(0);
    }
    await page.goto('/finance/reports/vat-detail-by-rate');
    await expect(page.locator('body')).not.toContainText(/Server Error|Whoops/i);
    expect(browserFailures).toEqual([]);

    fs.writeFileSync(evidenceFile, JSON.stringify({
        captured_at: new Date().toISOString(), prefix,
        signing: { configured: signing.configured, protocol_version: signing.protocol_version, key_id: signing.key_id },
        inventory: {
            grn: { item_id: grnItem.id, source_id: grn.body.data.id, source_number: grn.body.data.grn_number, reversal_id: grnDetail.reversal.id, final_on_hand: 0 },
            adjustment: { item_id: adjustmentItem.id, source_id: negative.id, source_number: negative.number, reversal_id: adjustmentDetail.reversal.id, final_on_hand: 3 },
            shipment: { item_id: shipmentItem.id, source_id: shipment.body.data.id, source_number: shipment.body.data.shipment_number, return_id: returnId, final_on_hand: 2 },
        },
        journals: journalPairs,
        finance_trial_balance_net_difference: financeDifference,
        browser_http_5xx: browserFailures,
        unexplained_difference: 0,
    }, null, 2) + '\n');
});

test('retrying already-sent signed events is journal-idempotent', async ({ page }) => {
    const evidence = JSON.parse(fs.readFileSync(evidenceFile, 'utf8'));
    await login(page);
    for (const pair of evidence.journals) {
        for (const delivery of [pair.original, pair.reversal]) {
            const retried = await api(page, 'POST', `/integration/solabooks/events/${delivery.event_id}/retry`);
            expect(retried.status).toBe(200);
            expect(String(retried.body.data.external_document_id)).toBe(String(delivery.journal_id));
            expect(retried.body.data.status).toBe('sent');
        }
    }
});
