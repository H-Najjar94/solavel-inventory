import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(420_000);
test.describe.configure({ mode: 'serial' });
test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const evidenceFile = '/tmp/solastock-practical-verification-20260719/19-phase15-final/deployed-reversal-matrix.json';
const run = Date.now();
const prefix = `QA-SOLASTOCK-PHASE15-20260721-${run}`;
const doc = `P15-${String(run).slice(-9)}`;

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

async function createItem(page, suffix, trackingType = 'none', tracksExpiry = false, costingMethod = 'fifo') {
    const result = await api(page, 'POST', '/items', {
        sku: `${doc}-${suffix}`,
        name: `${prefix} ${suffix}`,
        item_type: 'inventory',
        tracking_type: trackingType,
        tracks_expiry: tracksExpiry,
        costing_method: costingMethod,
        purchase_price: 10,
        sales_price: 20,
        is_active: true,
    });
    expect(result.status, `create ${suffix}`).toBe(201);
    return result.body.data;
}

async function deliver(page, eventType, aggregateId) {
    const observed = [];
    let event;
    for (const status of ['pending', 'failed', 'sent', 'ignored']) {
        const listed = await api(page, 'GET', `/integration/solabooks/events?status=${status}&event_type=${eventType}&per_page=100`);
        observed.push(...rows(listed).map((candidate) => ({ id: candidate.id, aggregate_id: candidate.aggregate_id, status: candidate.status })));
        event = rows(listed).find((candidate) => Number(candidate.aggregate_id) === Number(aggregateId));
        if (event) break;
    }
    expect(event?.id, `${eventType}:${aggregateId}; observed=${JSON.stringify(observed.slice(0, 12))}`).toBeTruthy();
    if (event.status === 'sent') {
        return { event_id: event.id, journal_id: Number(event.external_document_id) };
    }
    const delivered = await api(page, 'POST', `/integration/solabooks/events/${event.id}/retry`);
    expect(delivered.status).toBe(200);
    expect(delivered.body.data.status).toBe('sent');
    expect(delivered.body.data.external_document_id).toBeTruthy();
    return { event_id: event.id, journal_id: Number(delivered.body.data.external_document_id) };
}

async function visibleReverse(page, path, reason) {
    await page.goto(path);
    const button = page.getByRole('button', { name: 'Reverse', exact: true });
    await expect(button).toBeVisible();
    await button.click();
    await page.getByPlaceholder('Explain why this source document is being reversed').fill(reason);
    await page.locator('.modal').getByRole('button', { name: 'Reverse', exact: true }).click();
    await expect(page.locator('body')).toContainText(/reversed/i);
}

async function balance(page, itemId, warehouseId = 1) {
    const result = await api(page, 'GET', `/balances?item_id=${itemId}&warehouse_id=${warehouseId}`);
    const row = rows(result).find((candidate) => Number(candidate.item_id) === Number(itemId));
    return Number(row?.on_hand_qty ?? 0);
}

async function createPostedGrn(page, supplier, lines, suffix, taxCodes, warehouseId = 1) {
    const po = await api(page, 'POST', '/purchase-orders', {
        warehouse_id: warehouseId,
        supplier_id: supplier.id,
        order_date: new Date().toISOString().slice(0, 10),
        notes: prefix,
        lines: lines.map((line, index) => ({
            item_id: line.item.id,
            ordered_qty: line.quantity,
            entered_qty: line.quantity,
            unit_price: line.cost,
            tax_code: taxCodes[index],
        })),
    });
    expect(po.status).toBe(201);
    expect((await api(page, 'POST', `/purchase-orders/${po.body.data.id}/approve`)).status).toBe(200);
    const detail = (await api(page, 'GET', `/purchase-orders/${po.body.data.id}`)).body.data;
    const grn = await api(page, 'POST', '/goods-receipts', {
        grn_number: `${doc}-${suffix}`,
        purchase_order_id: po.body.data.id,
        supplier_id: supplier.id,
        warehouse_id: warehouseId,
        receipt_date: new Date().toISOString().slice(0, 10),
        notes: prefix,
        lines: lines.map((line, index) => ({
            purchase_order_line_id: detail.lines[index].id,
            item_id: line.item.id,
            received_qty: line.quantity,
            accepted_qty: line.quantity,
            entered_qty: line.quantity,
            unit_cost: line.cost,
            ...(line.lotCode ? { lot_code: line.lotCode } : {}),
            ...(line.expiryDate ? { expiry_date: line.expiryDate } : {}),
            ...(line.serials ? { serials: line.serials } : {}),
        })),
    });
    expect(grn.status).toBe(201);
    expect((await api(page, 'POST', `/goods-receipts/${grn.body.data.id}/post`)).status).toBe(200);
    return { po: detail, grn: grn.body.data };
}

async function postAdjustment(page, reasonCode, lines, suffix, warehouseId = 1) {
    const created = await api(page, 'POST', '/adjustments', {
        adjustment_number: `${doc}-${suffix}`,
        adjustment_date: new Date().toISOString().slice(0, 10),
        warehouse_id: warehouseId,
        reason_code: reasonCode,
        notes: prefix,
        lines,
    });
    expect(created.status).toBe(201);
    const posted = await api(page, 'POST', `/adjustments/${created.body.data.id}/post`);
    expect(posted.status, JSON.stringify(posted.body)).toBe(200);
    return created.body.data;
}

async function createShipment(page, customer, lines, serialIds, suffix) {
    const so = await api(page, 'POST', '/sales-orders', {
        order_number: `${doc}-${suffix}-SO`,
        warehouse_id: 1,
        customer_id: customer.id,
        customer_name: customer.name,
        order_date: new Date().toISOString().slice(0, 10),
        notes: prefix,
        lines: lines.map((line) => ({
            item_id: line.item.id,
            ordered_qty: line.quantity,
            unit_price: line.price,
            tax_code: line.taxCode,
            discount_rate: line.discountRate ?? 0,
        })),
    });
    expect(so.status).toBe(201);
    expect((await api(page, 'POST', `/sales-orders/${so.body.data.id}/confirm`)).status).toBe(200);
    const soDetail = (await api(page, 'GET', `/sales-orders/${so.body.data.id}`)).body.data.sales_order;
    const serialSelections = {};
    soDetail.lines.forEach((line) => {
        if (serialIds[line.item_id]) serialSelections[line.id] = [serialIds[line.item_id]];
    });
    expect((await api(page, 'POST', `/sales-orders/${so.body.data.id}/reserve`, { serial_ids: serialSelections })).status).toBe(200);
    const pick = await api(page, 'POST', '/pick-lists', { sales_order_id: so.body.data.id, pick_number: `${doc}-${suffix}-PICK`, warehouse_id: 1 });
    expect(pick.status).toBe(201);
    expect((await api(page, 'POST', `/pick-lists/${pick.body.data.id}/picked`)).status).toBe(200);
    const pack = await api(page, 'POST', '/packs', { pick_list_id: pick.body.data.id, pack_number: `${doc}-${suffix}-PACK`, package_count: 1 });
    expect(pack.status).toBe(201);
    expect((await api(page, 'POST', `/packs/${pack.body.data.id}/packed`)).status).toBe(200);
    const shipment = await api(page, 'POST', '/shipments', {
        shipment_number: `${doc}-${suffix}-SHIP`,
        sales_order_id: so.body.data.id,
        pack_id: pack.body.data.id,
        warehouse_id: 1,
        lines: soDetail.lines.map((line) => {
            const source = lines.find((candidate) => Number(candidate.item.id) === Number(line.item_id));
            return {
                sales_order_line_id: line.id,
                item_id: line.item_id,
                quantity: source.quantity,
                warehouse_id: 1,
                ...(source.lotId ? { lot_id: source.lotId } : {}),
                ...(serialIds[line.item_id] ? { serial_id: serialIds[line.item_id] } : {}),
            };
        }),
    });
    expect(shipment.status).toBe(201);
    expect((await api(page, 'POST', `/shipments/${shipment.body.data.id}/post`)).status).toBe(200);
    return { so: soDetail, shipment: shipment.body.data };
}

async function visibleShipmentReturn(page, shipmentId, suffix) {
    await page.goto(`/inventory/shipments/${shipmentId}`);
    await page.getByRole('button', { name: 'Create source reversal' }).click();
    await expect(page.getByText('Source reversal quantities, warehouse, lot/serial identity, and cost are locked')).toBeVisible();
    await page.getByLabel('Return number').fill(`${doc}-${suffix}-RET`);
    await page.getByLabel('Reason').fill(`${prefix} source return`);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/sales-returns\/\d+/);
    const returnId = Number(new URL(page.url()).pathname.split('/').filter(Boolean).at(-1));
    expect(returnId).toBeGreaterThan(0);
    await page.getByRole('button', { name: 'Authorize RMA' }).click();
    await page.getByRole('button', { name: 'Mark inspected' }).click();
    await page.getByRole('button', { name: 'Post return' }).click();
    await page.locator('.modal').getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.getByText('Return posted — eligible stock brought back in.')).toBeVisible();
    return returnId;
}

async function journalLines(page, journalId) {
    await page.goto(`/finance/entries/${journalId}`);
    await expect(page.locator('body')).not.toContainText(/Server Error|Whoops|out of balance/i);
    return page.locator('tbody tr').evaluateAll((elements) => elements.map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent.trim())));
}

test('deployed visible traceable originals and reversals preserve source, tax, identity, cost and Finance links', async ({ page }) => {
    await login(page);
    const settings = (await api(page, 'GET', '/settings')).body.data.settings;
    const reasonCode = settings.adjustment_reason_codes.find((code) => code.active !== false)?.code;
    expect(reasonCode).toBeTruthy();
    const supplier = rows(await api(page, 'GET', '/suppliers?per_page=1'))[0];
    const customer = rows(await api(page, 'GET', '/customers?per_page=1'))[0];
    expect(supplier?.id).toBeTruthy();
    expect(customer?.id).toBeTruthy();

    const expiryDate = new Date(Date.now() + 180 * 86400000).toISOString().slice(0, 10);
    const grnBatch = await createItem(page, 'GRN-BATCH', 'lot');
    const grnSerial = await createItem(page, 'GRN-SERIAL', 'serial');
    const grnExpiry = await createItem(page, 'GRN-EXPIRY', 'lot', true);
    const grnSerialText = `${doc}-GRN-SERIAL-001`;
    const mixed = await createPostedGrn(page, supplier, [
        { item: grnBatch, quantity: 2, cost: 10, lotCode: `${doc}-GRN-BATCH-A` },
        { item: grnSerial, quantity: 1, cost: 20, serials: [grnSerialText] },
        { item: grnExpiry, quantity: 2, cost: 5, lotCode: `${doc}-GRN-EXP-A`, expiryDate },
    ], 'MIX-GRN', ['QA12-STD16', 'QA12-ZERO', 'QA12-EXEMPT']);
    expect(mixed.po.lines.map((line) => line.tax_treatment)).toEqual(['standard', 'zero', 'exempt']);
    const mixedOriginal = await deliver(page, 'grn.posted', mixed.grn.id);
    await visibleReverse(page, `/inventory/goods-receipts/${mixed.grn.id}`, `${prefix} mixed traceable receipt reversal`);
    const mixedDetail = (await api(page, 'GET', `/goods-receipts/${mixed.grn.id}`)).body.data;
    const mixedReversal = await deliver(page, 'grn.reversed', mixedDetail.grn.reversal.id);
    expect(await balance(page, grnBatch.id)).toBe(0);
    expect(await balance(page, grnSerial.id)).toBe(0);
    expect(await balance(page, grnExpiry.id)).toBe(0);
    const grnSerialRow = rows(await api(page, 'GET', `/serials?q=${encodeURIComponent(grnSerialText)}&per_page=10`))[0];
    expect(grnSerialRow.status).toBe('returned');
    const reversedExpiryLedger = mixedDetail.ledger.find((row) => Number(row.item_id) === Number(grnExpiry.id));
    expect(reversedExpiryLedger.expiry_date.slice(0, 10)).toBe(expiryDate);

    const simple = await createItem(page, 'GRN-TAX-SIMPLE');
    const taxGrns = [];
    for (const [suffix, taxCode, treatment] of [['ZERO', 'QA12-ZERO', 'zero'], ['EXEMPT', 'QA12-EXEMPT', 'exempt']]) {
        const source = await createPostedGrn(page, supplier, [{ item: simple, quantity: 1, cost: 3 }], `${suffix}-GRN`, [taxCode]);
        expect(source.po.lines[0].tax_treatment).toBe(treatment);
        const original = await deliver(page, 'grn.posted', source.grn.id);
        await visibleReverse(page, `/inventory/goods-receipts/${source.grn.id}`, `${prefix} ${treatment} receipt reversal`);
        const detail = (await api(page, 'GET', `/goods-receipts/${source.grn.id}`)).body.data.grn;
        const reversal = await deliver(page, 'grn.reversed', detail.reversal.id);
        taxGrns.push({ treatment, source_id: source.grn.id, reversal_id: detail.reversal.id, original, reversal });
    }

    const shipBatch = await createItem(page, 'SHIP-BATCH', 'lot');
    const shipSerial = await createItem(page, 'SHIP-SERIAL', 'serial');
    const shipExpiry = await createItem(page, 'SHIP-EXPIRY', 'lot', true);
    const shipSerialText = `${doc}-SHIP-SERIAL-001`;
    await postAdjustment(page, reasonCode, [
        { direction: 'increase', item_id: shipBatch.id, quantity: 2, unit_cost: 9, lot_code: `${doc}-SHIP-BATCH-A` },
        { direction: 'increase', item_id: shipSerial.id, quantity: 1, unit_cost: 12, serials: [shipSerialText] },
        { direction: 'increase', item_id: shipExpiry.id, quantity: 2, unit_cost: 7, lot_code: `${doc}-SHIP-EXP-A`, expiry_date: expiryDate },
    ], 'SHIP-SEED');
    const batchLot = rows(await api(page, 'GET', `/lots?item_id=${shipBatch.id}&per_page=20`)).find((lot) => lot.lot_code === `${doc}-SHIP-BATCH-A`);
    const expiryLot = rows(await api(page, 'GET', `/lots?item_id=${shipExpiry.id}&per_page=20`)).find((lot) => lot.lot_code === `${doc}-SHIP-EXP-A`);
    const shipSerialRow = rows(await api(page, 'GET', `/serials?q=${encodeURIComponent(shipSerialText)}&per_page=10`))[0];
    const shipped = await createShipment(page, customer, [
        { item: shipBatch, quantity: 1, price: 20, taxCode: 'QA12-STD16', discountRate: 10, lotId: batchLot.id },
        { item: shipSerial, quantity: 1, price: 30, taxCode: 'QA12-ZERO' },
        { item: shipExpiry, quantity: 1, price: 15, taxCode: 'QA12-EXEMPT', lotId: expiryLot.id },
    ], { [shipSerial.id]: shipSerialRow.id }, 'TRACE');
    expect(shipped.so.lines.map((line) => line.tax_treatment)).toEqual(['standard', 'zero', 'exempt']);
    expect(Number(shipped.so.lines[0].discount_rate)).toBe(10);
    const shipmentOriginal = await deliver(page, 'shipment.posted', shipped.shipment.id);
    const returnId = await visibleShipmentReturn(page, shipped.shipment.id, 'TRACE');
    const shipmentReversal = await deliver(page, 'sales_return.posted', returnId);
    expect(await balance(page, shipBatch.id)).toBe(2);
    expect(await balance(page, shipSerial.id)).toBe(1);
    expect(await balance(page, shipExpiry.id)).toBe(2);
    const serialAfterReturn = (await api(page, 'GET', `/serials/${shipSerialRow.id}`)).body.data.serial;
    expect(['available', 'in_stock']).toContain(serialAfterReturn.status);
    expect(Number(serialAfterReturn.warehouse_id)).toBe(1);
    const returnDetail = (await api(page, 'GET', `/sales-returns/${returnId}`)).body.data;
    expect(returnDetail.sales_return.lines.map((line) => Number(line.lot_id)).filter(Boolean).sort()).toEqual([batchLot.id, expiryLot.id].sort());

    const average = await createItem(page, 'ADJ-AVERAGE', 'none', false, 'average');
    await postAdjustment(page, reasonCode, [{ direction: 'increase', item_id: average.id, quantity: 3, unit_cost: 13 }], 'AVG-SEED');
    const negative = await postAdjustment(page, reasonCode, [
        { direction: 'decrease', item_id: shipBatch.id, quantity: 1, lot_id: batchLot.id },
        { direction: 'decrease', item_id: shipSerial.id, quantity: 1, serial_id: shipSerialRow.id },
        { direction: 'decrease', item_id: shipExpiry.id, quantity: 1, lot_id: expiryLot.id },
        { direction: 'decrease', item_id: average.id, quantity: 1 },
    ], 'TRACE-LOSS');
    const adjustmentOriginal = await deliver(page, 'adjustment.posted', negative.id);
    await visibleReverse(page, `/inventory/adjustments/${negative.id}`, `${prefix} traceable loss reversal`);
    const negativeDetail = (await api(page, 'GET', `/adjustments/${negative.id}`)).body.data;
    const adjustmentReversal = await deliver(page, 'adjustment.reversed', negativeDetail.adjustment.reversal.id);
    expect(await balance(page, shipBatch.id)).toBe(2);
    expect(await balance(page, shipSerial.id)).toBe(1);
    expect(await balance(page, shipExpiry.id)).toBe(2);
    expect(await balance(page, average.id)).toBe(3);
    expect(Number(negativeDetail.ledger.reduce((sum, line) => sum + Math.abs(Number(line.total_cost)), 0))).toBeGreaterThan(0);

    await page.goto('/sso/finance/redirect?organization_id=29');
    const pairs = [
        { type: 'grn', original: mixedOriginal, reversal: mixedReversal },
        ...taxGrns.map((entry) => ({ type: `grn-${entry.treatment}`, original: entry.original, reversal: entry.reversal })),
        { type: 'shipment', original: shipmentOriginal, reversal: shipmentReversal },
        { type: 'adjustment', original: adjustmentOriginal, reversal: adjustmentReversal },
    ];
    for (const pair of pairs) {
        const originalLines = await journalLines(page, pair.original.journal_id);
        const reversalLines = await journalLines(page, pair.reversal.journal_id);
        expect(originalLines.length).toBeGreaterThanOrEqual(2);
        expect(reversalLines.length).toBe(originalLines.length);
        await expect(page.locator('body')).toContainText(/Reversal|عكس/i);
    }
    for (const path of ['/finance/reports/trial-balance', '/finance/reports/balance-sheet', '/finance/reports/profit-and-loss', '/finance/reports/vat-detail-by-rate']) {
        const response = await page.goto(path);
        expect(response?.status()).toBeLessThan(400);
        await expect(page.locator('body')).not.toContainText(/Server Error|Whoops|out of balance/i);
    }

    fs.mkdirSync(new URL('.', `file://${evidenceFile}`).pathname, { recursive: true });
    fs.writeFileSync(evidenceFile, JSON.stringify({
        captured_at: new Date().toISOString(), prefix,
        traceable_grn: { source_id: mixed.grn.id, reversal_id: mixedDetail.grn.reversal.id, batch_item_id: grnBatch.id, serial_item_id: grnSerial.id, expiry_item_id: grnExpiry.id, expiry_date: expiryDate, serial_status: grnSerialRow.status },
        tax_grns: taxGrns,
        shipment: { source_id: shipped.shipment.id, return_id: returnId, batch_lot_id: batchLot.id, expiry_lot_id: expiryLot.id, serial_id: shipSerialRow.id, serial_status: serialAfterReturn.status },
        negative_adjustment: { source_id: negative.id, reversal_id: negativeDetail.adjustment.reversal.id },
        journal_pairs: pairs,
    }, null, 2) + '\n');
});

test('deployed reversal races and permission boundaries create one mutation without leakage', async ({ browser }) => {
    const ownerAContext = await browser.newContext();
    const ownerBContext = await browser.newContext();
    const ownerA = await ownerAContext.newPage();
    const ownerB = await ownerBContext.newPage();
    await Promise.all([login(ownerA), login(ownerB)]);
    const settings = (await api(ownerA, 'GET', '/settings')).body.data.settings;
    const reasonCode = settings.adjustment_reason_codes.find((code) => code.active !== false)?.code;
    const supplier = rows(await api(ownerA, 'GET', '/suppliers?per_page=1'))[0];
    const customer = rows(await api(ownerA, 'GET', '/customers?per_page=1'))[0];

    const grnItem = await createItem(ownerA, 'RACE-GRN');
    const raceGrn = await createPostedGrn(ownerA, supplier, [{ item: grnItem, quantity: 1, cost: 4 }], 'RACE-GRN', ['QA12-ZERO']);
    const grnResponses = await Promise.all([
        api(ownerA, 'POST', `/goods-receipts/${raceGrn.grn.id}/reverse`, { reason: `${prefix} context A` }),
        api(ownerB, 'POST', `/goods-receipts/${raceGrn.grn.id}/reverse`, { reason: `${prefix} context B` }),
    ]);
    expect(grnResponses.every((response) => response.status === 200)).toBeTruthy();
    const grnReversalIds = new Set(grnResponses.map((response) => Number(response.body.data.reversal.id)));
    expect(grnReversalIds.size).toBe(1);

    const adjustmentItem = await createItem(ownerA, 'RACE-ADJ');
    await postAdjustment(ownerA, reasonCode, [{ direction: 'increase', item_id: adjustmentItem.id, quantity: 2, unit_cost: 6 }], 'RACE-SEED');
    const raceAdjustment = await postAdjustment(ownerA, reasonCode, [{ direction: 'decrease', item_id: adjustmentItem.id, quantity: 1 }], 'RACE-LOSS');
    const adjustmentResponses = await Promise.all([
        api(ownerA, 'POST', `/adjustments/${raceAdjustment.id}/reverse`, { reason: `${prefix} context A` }),
        api(ownerB, 'POST', `/adjustments/${raceAdjustment.id}/reverse`, { reason: `${prefix} context B` }),
    ]);
    expect(adjustmentResponses.every((response) => response.status === 200)).toBeTruthy();
    const adjustmentReversalIds = new Set(adjustmentResponses.map((response) => Number(response.body.data.reversal.id)));
    expect(adjustmentReversalIds.size).toBe(1);

    const shipmentItem = await createItem(ownerA, 'RACE-SHIP');
    await postAdjustment(ownerA, reasonCode, [{ direction: 'increase', item_id: shipmentItem.id, quantity: 1, unit_cost: 8 }], 'RACE-SHIP-SEED');
    const shipped = await createShipment(ownerA, customer, [{ item: shipmentItem, quantity: 1, price: 12, taxCode: 'QA12-ZERO' }], {}, 'RACE');
    const returnPayload = {
        return_number: `${doc}-RACE-RET`, shipment_id: shipped.shipment.id, warehouse_id: 999999,
        reason: `${prefix} response race`, lines: [{ item_id: shipmentItem.id, returned_qty: 999, unit_cost: 0.01, condition: 'damaged' }],
    };
    const returnResponses = await Promise.all([
        api(ownerA, 'POST', '/sales-returns', returnPayload),
        api(ownerB, 'POST', '/sales-returns', returnPayload),
    ]);
    expect(returnResponses.every((response) => response.status === 201)).toBeTruthy();
    const returnIds = new Set(returnResponses.map((response) => Number(response.body.data.id)));
    expect(returnIds.size).toBe(1);
    const returnId = [...returnIds][0];
    await api(ownerA, 'POST', `/sales-returns/${returnId}/authorize`);
    await api(ownerA, 'POST', `/sales-returns/${returnId}/inspect`, {});
    const postResponses = await Promise.all([
        api(ownerA, 'POST', `/sales-returns/${returnId}/post`),
        api(ownerB, 'POST', `/sales-returns/${returnId}/post`),
    ]);
    expect(postResponses.every((response) => response.status === 200)).toBeTruthy();
    const matchingReturns = rows(await api(ownerA, 'GET', `/sales-returns?shipment_id=${shipped.shipment.id}&per_page=20`));
    expect(matchingReturns).toHaveLength(1);

    const viewerContext = await browser.newContext();
    const viewer = await viewerContext.newPage();
    await login(viewer, 'qa.viewer@solavel.test');
    for (const [path, body] of [
        [`/goods-receipts/${raceGrn.grn.id}/reverse`, { reason: 'viewer bypass' }],
        [`/adjustments/${raceAdjustment.id}/reverse`, { reason: 'viewer bypass' }],
        ['/sales-returns', returnPayload],
    ]) {
        const result = await api(viewer, 'POST', path, body);
        expect(result.status).toBe(403);
        expect(JSON.stringify(result.body)).not.toMatch(/unit_cost|journal_id|tax_amount|serial_id|lot_id/i);
    }

    const warehouseBAdjustment = await postAdjustment(ownerA, reasonCode, [{ direction: 'increase', item_id: adjustmentItem.id, quantity: 1, unit_cost: 6 }], 'WH-B', 2);
    const restrictedContext = await browser.newContext();
    const restricted = await restrictedContext.newPage();
    await login(restricted, 'qa.warehouse@solavel.test');
    const denied = await api(restricted, 'POST', `/adjustments/${warehouseBAdjustment.id}/reverse`, { reason: 'restricted bypass' });
    expect([403, 404]).toContain(denied.status);
    expect(JSON.stringify(denied.body)).not.toMatch(new RegExp(`${warehouseBAdjustment.id}|unit_cost|journal_id|tax_amount`, 'i'));

    const zeroItem = await createItem(ownerA, 'ZERO-ASSIGNMENT-GRN');
    const zeroSource = await createPostedGrn(ownerA, supplier, [{ item: zeroItem, quantity: 1, cost: 4 }], 'ZERO-ASSIGNMENT-GRN', ['QA12-ZERO']);
    const assignmentBefore = (await api(ownerA, 'GET', '/settings/warehouse-assignments/91')).body.data.assignments.map((row) => Number(row.warehouse_id));
    expect(assignmentBefore).toEqual([1]);
    expect((await api(ownerA, 'PUT', '/settings/warehouse-assignments/91', { warehouse_ids: [] })).status).toBe(200);
    const zeroContext = await browser.newContext();
    const zero = await zeroContext.newPage();
    let zeroDenied;
    try {
        await login(zero, 'qa.warehouse@solavel.test');
        expect(rows(await api(zero, 'GET', '/warehouses?per_page=100'))).toHaveLength(0);
        zeroDenied = await api(zero, 'POST', `/goods-receipts/${zeroSource.grn.id}/reverse`, { reason: 'zero assignment bypass' });
        expect([403, 404]).toContain(zeroDenied.status);
        const staleDenied = await api(zero, 'POST', `/goods-receipts/${raceGrn.grn.id}/reverse`, { reason: 'zero assignment stale replay' });
        expect([403, 404]).toContain(staleDenied.status);
    } finally {
        expect((await api(ownerA, 'PUT', '/settings/warehouse-assignments/91', { warehouse_ids: [1] })).status).toBe(200);
    }

    const managerSource = await postAdjustment(ownerA, reasonCode, [{ direction: 'decrease', item_id: adjustmentItem.id, quantity: 1 }], 'MANAGER-LOSS');
    const managerAssignments = (await api(ownerA, 'GET', '/settings/warehouse-assignments/90')).body.data.assignments.map((row) => Number(row.warehouse_id)).sort();
    if (JSON.stringify(managerAssignments) !== JSON.stringify([1, 2])) {
        expect((await api(ownerA, 'PUT', '/settings/warehouse-assignments/90', { warehouse_ids: [1, 2] })).status).toBe(200);
    }
    const managerContext = await browser.newContext();
    const manager = await managerContext.newPage();
    await login(manager, 'qa.manager@solavel.test');
    await visibleReverse(manager, `/inventory/adjustments/${managerSource.id}`, `${prefix} manager reversal`);

    const cleanupB = await api(ownerA, 'POST', `/adjustments/${warehouseBAdjustment.id}/reverse`, { reason: `${prefix} owner Warehouse B cleanup` });
    expect(cleanupB.status).toBe(200);
    const zeroCleanup = await api(ownerA, 'POST', `/goods-receipts/${zeroSource.grn.id}/reverse`, { reason: `${prefix} zero-assignment source cleanup` });
    expect(zeroCleanup.status).toBe(200);
    for (const [eventType, aggregateId] of [
        ['grn.posted', raceGrn.grn.id], ['grn.reversed', [...grnReversalIds][0]],
        ['adjustment.posted', raceAdjustment.id], ['adjustment.reversed', [...adjustmentReversalIds][0]],
        ['shipment.posted', shipped.shipment.id], ['sales_return.posted', returnId],
        ['adjustment.posted', managerSource.id], ['adjustment.reversed', (await api(ownerA, 'GET', `/adjustments/${managerSource.id}`)).body.data.adjustment.reversal.id],
        ['grn.posted', zeroSource.grn.id], ['grn.reversed', zeroCleanup.body.data.reversal.id],
    ]) await deliver(ownerA, eventType, aggregateId);

    const evidence = JSON.parse(fs.readFileSync(evidenceFile, 'utf8'));
    evidence.concurrency = {
        grn: { source_id: raceGrn.grn.id, response_codes: grnResponses.map((response) => response.status), reversal_rows: grnReversalIds.size },
        adjustment: { source_id: raceAdjustment.id, response_codes: adjustmentResponses.map((response) => response.status), reversal_rows: adjustmentReversalIds.size },
        shipment: { source_id: shipped.shipment.id, return_rows: returnIds.size, post_codes: postResponses.map((response) => response.status) },
    };
    evidence.permissions = { viewer: '403 no sensitive fields', warehouse_b: denied.status, zero_assignment: zeroDenied.status, manager: 'visible reversal passed', assignments_restored: [1] };
    fs.writeFileSync(evidenceFile, JSON.stringify(evidence, null, 2) + '\n');

    await Promise.all([ownerAContext.close(), ownerBContext.close(), viewerContext.close(), restrictedContext.close(), zeroContext.close(), managerContext.close()]);
});

test('deployed GRN eligibility failures preserve stock and warehouse scope without mutation', async ({ browser }) => {
    const ownerContext = await browser.newContext();
    const owner = await ownerContext.newPage();
    await login(owner);
    const settings = (await api(owner, 'GET', '/settings')).body.data.settings;
    const reasonCode = settings.adjustment_reason_codes.find((code) => code.active !== false)?.code;
    const supplier = rows(await api(owner, 'GET', '/suppliers?per_page=1'))[0];

    const consumedItem = await createItem(owner, 'ELIGIBILITY-CONSUMED');
    const receipt = await createPostedGrn(owner, supplier, [{ item: consumedItem, quantity: 2, cost: 5 }], 'ELIGIBILITY-CONSUMED', ['QA12-ZERO']);
    const consumption = await postAdjustment(owner, reasonCode, [{ direction: 'decrease', item_id: consumedItem.id, quantity: 1 }], 'ELIGIBILITY-CONSUMPTION');
    expect(await balance(owner, consumedItem.id)).toBe(1);

    await owner.goto(`/inventory/goods-receipts/${receipt.grn.id}`);
    await owner.getByRole('button', { name: 'Reverse', exact: true }).click();
    await owner.getByPlaceholder('Explain why this source document is being reversed').fill(`${prefix} downstream eligibility rejection`);
    await owner.locator('.modal').getByRole('button', { name: 'Reverse', exact: true }).click();
    await expect(owner.locator('body')).toContainText(/downstream stock consumption/i);
    const receiptAfterFailure = (await api(owner, 'GET', `/goods-receipts/${receipt.grn.id}`)).body.data.grn;
    expect(receiptAfterFailure.reversal_id).toBeNull();
    expect(await balance(owner, consumedItem.id)).toBe(1);

    await visibleReverse(owner, `/inventory/adjustments/${consumption.id}`, `${prefix} restore consumed receipt stock`);
    const consumptionReversal = (await api(owner, 'GET', `/adjustments/${consumption.id}`)).body.data.adjustment.reversal.id;
    expect(await balance(owner, consumedItem.id)).toBe(2);

    const warehouseBItem = await createItem(owner, 'WAREHOUSE-B-GRN');
    const warehouseBReceipt = await createPostedGrn(owner, supplier, [{ item: warehouseBItem, quantity: 1, cost: 6 }], 'WAREHOUSE-B-GRN', ['QA12-ZERO'], 2);
    const restrictedContext = await browser.newContext();
    const restricted = await restrictedContext.newPage();
    await login(restricted, 'qa.warehouse@solavel.test');
    const warehouseDenied = await api(restricted, 'POST', `/goods-receipts/${warehouseBReceipt.grn.id}/reverse`, { reason: 'restricted warehouse reversal' });
    expect([403, 404]).toContain(warehouseDenied.status);
    expect(JSON.stringify(warehouseDenied.body)).not.toMatch(/unit_cost|journal_id|tax_amount|serial_id|lot_id/i);
    const unknownDenied = await api(restricted, 'POST', '/goods-receipts/999999999/reverse', { reason: 'modified source id' });
    expect([403, 404]).toContain(unknownDenied.status);
    const warehouseBCleanup = await api(owner, 'POST', `/goods-receipts/${warehouseBReceipt.grn.id}/reverse`, { reason: `${prefix} Warehouse B cleanup` });
    expect(warehouseBCleanup.status).toBe(200);

    const delivered = [];
    for (const [eventType, aggregateId] of [
        ['grn.posted', receipt.grn.id],
        ['adjustment.posted', consumption.id],
        ['adjustment.reversed', consumptionReversal],
        ['grn.posted', warehouseBReceipt.grn.id],
        ['grn.reversed', warehouseBCleanup.body.data.reversal.id],
    ]) delivered.push(await deliver(owner, eventType, aggregateId));

    const evidence = JSON.parse(fs.readFileSync(evidenceFile, 'utf8'));
    evidence.eligibility = {
        downstream_consumption: { source_id: receipt.grn.id, result: 'rejected without mutation after downstream history', adjustment_reversal_id: consumptionReversal, final_on_hand: 2 },
        warehouse_scope: { source_id: warehouseBReceipt.grn.id, restricted_status: warehouseDenied.status, modified_id_status: unknownDenied.status },
        finance_journals: delivered.map((row) => row.journal_id),
    };
    fs.writeFileSync(evidenceFile, JSON.stringify(evidence, null, 2) + '\n');
    await Promise.all([ownerContext.close(), restrictedContext.close()]);
});
