import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(180_000);
test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });
const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const evidenceFile = '/tmp/solastock-practical-verification-20260719/17-phase13-final/finance-report-delta.json';
const prefix = 'QA-SOLASTOCK-PHASE13-20260720-' + Date.now();
const accountCodes = ['1200', '1301', '1703', '2100', '2201', '4101', '4303', '5001', '6804'];

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

function amount(value) {
    if (!value || value.trim() === '-') return 0;
    return Number(value.replace(/,/g, '').replace(/[^\d.-]/g, '')) || 0;
}

async function trialBalance(page) {
    await page.goto('/finance/reports/trial-balance');
    await expect(page.locator('body')).toContainText(/Trial Balance|ميزان المراجعة/i);
    const rows = await page.locator('tbody tr').evaluateAll((elements) => elements.map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim())));
    const result = Object.fromEntries(accountCodes.map((code) => [code, { debit: 0, credit: 0 }]));
    for (const cells of rows) {
        if (cells.length < 4 || !accountCodes.includes(cells[1])) continue;
        result[cells[1]] = { debit: amount(cells[2]), credit: amount(cells[3]) };
    }
    return result;
}

async function postAdjustment(page, itemId, reasonCode, direction, quantity, unitCost, suffix) {
    const created = await api(page, 'POST', '/adjustments', {
        adjustment_number: prefix + '-' + suffix,
        adjustment_date: new Date().toISOString().slice(0, 10),
        warehouse_id: 1,
        reason_code: reasonCode,
        notes: prefix,
        lines: [{ item_id: itemId, direction, quantity, unit_cost: unitCost }],
    });
    expect(created.status).toBe(201);
    const posted = await api(page, 'POST', '/adjustments/' + created.body.data.id + '/post');
    expect(posted.status).toBe(200);
    return { id: created.body.data.id, number: created.body.data.adjustment_number };
}

async function deliver(page, eventType, aggregateId) {
    const listed = await api(page, 'GET', '/integration/solabooks/events?status=pending&event_type=' + eventType + '&per_page=100');
    const rows = listed.body.data?.data ?? listed.body.data ?? [];
    const event = rows.find((row) => Number(row.aggregate_id) === Number(aggregateId));
    expect(event?.id, eventType + ' event').toBeTruthy();
    const result = await api(page, 'POST', '/integration/solabooks/events/' + event.id + '/retry');
    expect(result.status).toBe(200);
    expect(result.body.data.status).toBe('sent');
    return { event_id: event.id, journal_id: result.body.data.external_document_id };
}

test('controlled Inventory sources reconcile to Finance trial balance, statements and VAT detail', async ({ page }) => {
    await login(page);
    await page.goto('/sso/finance/redirect?organization_id=29');
    await expect(page).toHaveURL(/\/finance\//);
    const before = await trialBalance(page);

    await page.goto('/inventory/dashboard');
    const settings = (await api(page, 'GET', '/settings')).body.data.settings;
    const reasonCode = settings.adjustment_reason_codes.find((row) => row.active !== false)?.code;
    expect(reasonCode).toBeTruthy();
    const item = await api(page, 'POST', '/items', {
        sku: prefix + '-DELTA', name: prefix + ' Finance Delta Item', item_type: 'inventory',
        tracking_type: 'none', costing_method: 'fifo', purchase_price: 10, sales_price: 20,
        is_active: true,
    });
    expect(item.status).toBe(201);
    const itemId = item.body.data.id;

    const positive = await postAdjustment(page, itemId, reasonCode, 'increase', 2, 4, 'GAIN');

    const parties = await Promise.all([api(page, 'GET', '/suppliers?per_page=1'), api(page, 'GET', '/customers?per_page=1')]);
    const supplier = (parties[0].body.data?.data ?? parties[0].body.data ?? [])[0];
    const customer = (parties[1].body.data?.data ?? parties[1].body.data ?? [])[0];
    const po = await api(page, 'POST', '/purchase-orders', {
        warehouse_id: 1, supplier_id: supplier?.id ?? null, order_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ item_id: itemId, ordered_qty: 2, entered_qty: 2, unit_price: 10, tax_code: 'QA12-STD16' }],
    });
    expect(po.status).toBe(201);
    expect((await api(page, 'POST', '/purchase-orders/' + po.body.data.id + '/approve')).status).toBe(200);
    const poDetail = (await api(page, 'GET', '/purchase-orders/' + po.body.data.id)).body.data;
    const poLine = poDetail.lines[0];
    expect(Number(poLine.tax_rate)).toBe(16);
    expect(Number(poLine.tax_amount)).toBeCloseTo(3.2, 2);

    const grn = await api(page, 'POST', '/goods-receipts', {
        grn_number: prefix + '-GRN', purchase_order_id: po.body.data.id, supplier_id: supplier?.id ?? null,
        warehouse_id: 1, receipt_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ purchase_order_line_id: poLine.id, item_id: itemId, received_qty: 2, accepted_qty: 2, entered_qty: 2, unit_cost: 10 }],
    });
    expect(grn.status).toBe(201);
    expect((await api(page, 'POST', '/goods-receipts/' + grn.body.data.id + '/post')).status).toBe(200);

    const so = await api(page, 'POST', '/sales-orders', {
        order_number: prefix + '-SO', warehouse_id: 1, customer_id: customer?.id ?? null,
        customer_name: customer?.name ?? prefix + ' Customer', order_date: new Date().toISOString().slice(0, 10), notes: prefix,
        lines: [{ item_id: itemId, ordered_qty: 1, unit_price: 20, tax_code: 'QA12-STD16' }],
    });
    expect(so.status).toBe(201);
    expect((await api(page, 'POST', '/sales-orders/' + so.body.data.id + '/confirm')).status).toBe(200);
    expect((await api(page, 'POST', '/sales-orders/' + so.body.data.id + '/reserve', {})).status).toBe(200);
    const soDetail = (await api(page, 'GET', '/sales-orders/' + so.body.data.id)).body.data.sales_order;
    const soLine = soDetail.lines[0];
    expect(Number(soLine.tax_amount)).toBeCloseTo(3.2, 2);
    const pick = await api(page, 'POST', '/pick-lists', { sales_order_id: so.body.data.id, pick_number: prefix + '-PICK', warehouse_id: 1 });
    expect(pick.status).toBe(201);
    expect((await api(page, 'POST', '/pick-lists/' + pick.body.data.id + '/picked')).status).toBe(200);
    const pack = await api(page, 'POST', '/packs', { pick_list_id: pick.body.data.id, pack_number: prefix + '-PACK', package_count: 1 });
    expect(pack.status).toBe(201);
    expect((await api(page, 'POST', '/packs/' + pack.body.data.id + '/packed')).status).toBe(200);
    const shipment = await api(page, 'POST', '/shipments', {
        shipment_number: prefix + '-SHIP', sales_order_id: so.body.data.id, pack_id: pack.body.data.id, warehouse_id: 1,
        lines: [{ sales_order_line_id: soLine.id, item_id: itemId, quantity: 1, warehouse_id: 1 }],
    });
    expect(shipment.status).toBe(201);
    expect((await api(page, 'POST', '/shipments/' + shipment.body.data.id + '/post')).status).toBe(200);

    const negative = await postAdjustment(page, itemId, reasonCode, 'decrease', 1, null, 'LOSS');
    const transfer = await api(page, 'POST', '/transfers', {
        transfer_number: 'P13D-' + Date.now() + '-TR', transfer_date: new Date().toISOString().slice(0, 10),
        from_warehouse_id: 1, to_warehouse_id: 2, notes: prefix,
        lines: [{ item_id: itemId, quantity: 1 }],
    });
    expect(transfer.status).toBe(201);
    expect((await api(page, 'POST', '/transfers/' + transfer.body.data.id + '/post')).status).toBe(200);

    const positiveDetail = (await api(page, 'GET', '/adjustments/' + positive.id)).body.data;
    const negativeDetail = (await api(page, 'GET', '/adjustments/' + negative.id)).body.data;
    const shipmentDetail = (await api(page, 'GET', '/shipments/' + shipment.body.data.id)).body.data;
    const gain = Math.abs(Number(positiveDetail.ledger[0].total_cost));
    const loss = Math.abs(Number(negativeDetail.ledger[0].total_cost));
    const cogs = Math.abs(Number(shipmentDetail.ledger[0].total_cost));

    const journals = {
        positive: await deliver(page, 'adjustment.posted', positive.id),
        grn: await deliver(page, 'grn.posted', grn.body.data.id),
        shipment: await deliver(page, 'shipment.posted', shipment.body.data.id),
        negative: await deliver(page, 'adjustment.posted', negative.id),
    };

    await page.goto('/sso/finance/redirect?organization_id=29');
    const after = await trialBalance(page);
    const expected = {
        '1200': { debit: 23.2, credit: 0 },
        // Trial Balance presents each account net on its natural side.
        '1301': { debit: gain + 20 - cogs - loss, credit: 0 },
        '1703': { debit: 3.2, credit: 0 },
        '2100': { debit: 0, credit: 23.2 },
        '2201': { debit: 0, credit: 3.2 },
        '4101': { debit: 0, credit: 20 },
        '4303': { debit: 0, credit: gain },
        '5001': { debit: cogs, credit: 0 },
        '6804': { debit: loss, credit: 0 },
    };
    const reconciliation = {};
    for (const code of accountCodes) {
        const actual = {
            debit: Number((after[code].debit - before[code].debit).toFixed(2)),
            credit: Number((after[code].credit - before[code].credit).toFixed(2)),
        };
        reconciliation[code] = {
            before: before[code], expected_change: expected[code], actual_change: actual,
            debit_difference: Number((actual.debit - expected[code].debit).toFixed(2)),
            credit_difference: Number((actual.credit - expected[code].credit).toFixed(2)),
        };
        expect(reconciliation[code].debit_difference, code + ' debit').toBe(0);
        expect(reconciliation[code].credit_difference, code + ' credit').toBe(0);
    }

    for (const path of ['/finance/reports/balance-sheet', '/finance/reports/profit-and-loss', '/finance/reports/vat-detail-by-rate']) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBeLessThan(400);
        await expect(page.locator('body')).not.toContainText(/Server Error|Whoops|not balanced/i);
    }
    await page.goto('/finance/reports/vat-detail-by-rate');
    await expect(page.locator('body')).toContainText(/VAT_STD_B|16(?:\.00)?%|QA Phase 12 Standard VAT/i);

    for (const source of Object.values(journals)) {
        await page.goto('/finance/entries/' + source.journal_id);
        await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);
    }

    fs.writeFileSync(evidenceFile, JSON.stringify({
        captured_at: new Date().toISOString(),
        prefix,
        sources: {
            item_id: itemId, positive_adjustment: positive, purchase_order: { id: po.body.data.id, number: po.body.data.po_number },
            goods_receipt: { id: grn.body.data.id, number: grn.body.data.grn_number },
            sales_order: { id: so.body.data.id, number: so.body.data.order_number },
            shipment: { id: shipment.body.data.id, number: shipment.body.data.shipment_number },
            negative_adjustment: negative, transfer: { id: transfer.body.data.id, number: transfer.body.data.transfer_number },
        },
        measured: { gain, grn_inventory: 20, input_vat: 3.2, sale_net: 20, output_vat: 3.2, cogs, loss },
        journals,
        reconciliation,
        unexplained_difference: 0,
    }, null, 2) + '\n');
});
