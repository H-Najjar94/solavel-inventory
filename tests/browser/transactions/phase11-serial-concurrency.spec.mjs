import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE11-20260720-${Date.now()}`;

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
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

async function seedSerial(page, suffix) {
    const serial = `${prefix}-${suffix}`;
    const meta = await api(page, 'GET', '/meta');
    const reason = (meta.body.data?.settings?.adjustment_reason_codes ?? []).find((row) => row.active !== false)?.code;
    const adjustment = await api(page, 'POST', '/adjustments', {
        warehouse_id: 2, adjustment_date: new Date().toISOString().slice(0, 10), reason_code: reason ?? null,
        notes: serial,
        lines: [{ direction: 'increase', item_id: 25, quantity: 1, unit_cost: 10, serials: [serial] }],
    });
    expect(adjustment.status).toBe(201);
    expect((await api(page, 'POST', `/adjustments/${adjustment.body.data.id}/post`)).status).toBe(200);
    const serials = await api(page, 'GET', `/serials?q=${encodeURIComponent(serial)}&per_page=10`);
    const rows = serials.body.data?.data ?? serials.body.data ?? [];
    const row = rows.find((candidate) => candidate.serial === serial);
    expect(row?.id).toBeTruthy();

    return { text: serial, id: row.id };
}

test('two browser contexts cannot reserve the same last available serial', async ({ browser }) => {
    const contextA = await browser.newContext();
    const contextB = await browser.newContext();
    const pageA = await contextA.newPage();
    const pageB = await contextB.newPage();
    await Promise.all([login(pageA), login(pageB)]);
    const seeded = await seedSerial(pageA, 'RESERVE');

    const customers = await api(pageA, 'GET', '/customers?per_page=100');
    const customer = (customers.body.data?.data ?? customers.body.data ?? [])[0];
    const createOrder = async (page, suffix) => {
        const response = await api(page, 'POST', '/sales-orders', {
            warehouse_id: 2,
            customer_id: customer?.id ?? null,
            customer_name: customer?.name ?? `${prefix} Customer`,
            order_date: new Date().toISOString().slice(0, 10),
            notes: `${prefix}-${suffix}`,
            lines: [{ item_id: 25, ordered_qty: 1, unit_price: 20, tax_rate: 0 }],
        });
        expect(response.status).toBe(201);
        expect((await api(page, 'POST', `/sales-orders/${response.body.data.id}/confirm`)).status).toBe(200);
        return response.body.data.id;
    };

    const [orderA, orderB] = await Promise.all([createOrder(pageA, 'A'), createOrder(pageB, 'B')]);
    await Promise.all([pageA.goto(`/inventory/sales-orders/${orderA}`), pageB.goto(`/inventory/sales-orders/${orderB}`)]);
    await Promise.all([pageA.getByRole('button', { name: 'Reservations' }).click(), pageB.getByRole('button', { name: 'Reservations' }).click()]);
    await Promise.all([
        expect(pageA.getByText(seeded.text)).toBeVisible(),
        expect(pageB.getByText(seeded.text)).toBeVisible(),
    ]);
    await Promise.all([
        pageA.locator('label.serial-selector__opt').filter({ hasText: seeded.text }).click(),
        pageB.locator('label.serial-selector__opt').filter({ hasText: seeded.text }).click(),
    ]);

    await Promise.all([
        pageA.getByRole('button', { name: 'Reserve stock' }).click(),
        pageB.getByRole('button', { name: 'Reserve stock' }).click(),
    ]);
    await Promise.all([pageA.waitForTimeout(700), pageB.waitForTimeout(700)]);

    const [detailA, detailB] = await Promise.all([
        api(pageA, 'GET', `/sales-orders/${orderA}`),
        api(pageB, 'GET', `/sales-orders/${orderB}`),
    ]);
    const activeA = (detailA.body.data.sales_order.reservations ?? []).filter((row) => row.status === 'active' && Number(row.serial_id) === Number(seeded.id));
    const activeB = (detailB.body.data.sales_order.reservations ?? []).filter((row) => row.status === 'active' && Number(row.serial_id) === Number(seeded.id));
    expect(activeA.length + activeB.length).toBe(1);

    const winnerPage = activeA.length === 1 ? pageA : pageB;
    const loserPage = activeA.length === 1 ? pageB : pageA;
    const winnerOrder = activeA.length === 1 ? orderA : orderB;
    const loserOrder = activeA.length === 1 ? orderB : orderA;
    await winnerPage.reload();
    await winnerPage.getByRole('button', { name: 'Release reservation' }).click();
    await expect(winnerPage.getByText('Reservation released.')).toBeVisible();

    await loserPage.reload();
    await loserPage.getByRole('button', { name: 'Reservations' }).click();
    await loserPage.locator('label.serial-selector__opt').filter({ hasText: seeded.text }).click();
    await loserPage.getByRole('button', { name: 'Reserve stock' }).click();
    await expect(loserPage.getByText('Stock reserved.')).toBeVisible();

    const finalLoser = await api(loserPage, 'GET', `/sales-orders/${loserOrder}`);
    expect(finalLoser.body.data.sales_order.reservations.filter((row) => row.status === 'active' && Number(row.serial_id) === Number(seeded.id))).toHaveLength(1);
    await api(loserPage, 'POST', `/sales-orders/${loserOrder}/release-reservation`);
    await Promise.all([
        api(pageA, 'POST', `/sales-orders/${winnerOrder}/cancel`),
        api(pageB, 'POST', `/sales-orders/${loserOrder}/cancel`),
    ]);
    await Promise.all([contextA.close(), contextB.close()]);
});

test('two visible contexts create only one serial pick, pack, shipment and outbound movement', async ({ browser }) => {
    test.setTimeout(120_000);
    const contextA = await browser.newContext();
    const contextB = await browser.newContext();
    const pageA = await contextA.newPage();
    const pageB = await contextB.newPage();
    await Promise.all([login(pageA), login(pageB)]);
    const seeded = await seedSerial(pageA, 'DOWNSTREAM');

    const customers = await api(pageA, 'GET', '/customers?per_page=100');
    const customer = (customers.body.data?.data ?? customers.body.data ?? [])[0];
    const order = await api(pageA, 'POST', '/sales-orders', {
        warehouse_id: 2, customer_id: customer?.id ?? null, customer_name: customer?.name ?? `${prefix} Customer`,
        order_date: new Date().toISOString().slice(0, 10), notes: `${prefix}-DOWNSTREAM`,
        lines: [{ item_id: 25, ordered_qty: 1, unit_price: 20, tax_rate: 0 }],
    });
    expect(order.status).toBe(201);
    const orderId = order.body.data.id;
    expect((await api(pageA, 'POST', `/sales-orders/${orderId}/confirm`)).status).toBe(200);

    await pageA.goto(`/inventory/sales-orders/${orderId}`);
    await pageA.getByRole('button', { name: 'Reservations' }).click();
    await pageA.locator('label.serial-selector__opt').filter({ hasText: seeded.text }).click();
    await pageA.getByRole('button', { name: 'Reserve stock' }).click();
    await expect(pageA.getByText('Stock reserved.')).toBeVisible();

    await Promise.all([pageA.goto(`/inventory/sales-orders/${orderId}`), pageB.goto(`/inventory/sales-orders/${orderId}`)]);
    await Promise.all([
        pageA.getByRole('button', { name: 'Create pick list' }).click(),
        pageB.getByRole('button', { name: 'Create pick list' }).click(),
    ]);
    await Promise.all([pageA.waitForTimeout(500), pageB.waitForTimeout(500)]);
    const pickUrls = [pageA.url(), pageB.url()].filter((url) => /\/inventory\/pick-lists\/\d+$/.test(url));
    expect(pickUrls).toHaveLength(1);
    const pickId = Number(pickUrls[0].match(/(\d+)$/)[1]);

    await Promise.all([pageA.goto(`/inventory/pick-lists/${pickId}`), pageB.goto(`/inventory/pick-lists/${pickId}`)]);
    await pageA.getByRole('button', { name: 'Mark picked' }).click();
    await expect(pageA.getByText('Pick list marked picked.')).toBeVisible();
    await pageB.reload();
    await Promise.all([
        pageA.getByRole('button', { name: 'Create pack' }).click(),
        pageB.getByRole('button', { name: 'Create pack' }).click(),
    ]);
    await Promise.all([pageA.waitForTimeout(500), pageB.waitForTimeout(500)]);
    const packUrls = [pageA.url(), pageB.url()].filter((url) => /\/inventory\/packs\/\d+$/.test(url));
    expect(packUrls).toHaveLength(1);
    const packId = Number(packUrls[0].match(/(\d+)$/)[1]);

    await Promise.all([pageA.goto(`/inventory/packs/${packId}`), pageB.goto(`/inventory/packs/${packId}`)]);
    await pageA.getByRole('button', { name: 'Mark packed' }).click();
    await expect(pageA.getByText('Pack marked packed.')).toBeVisible();
    await Promise.all([pageA.goto(`/inventory/sales-orders/${orderId}`), pageB.goto(`/inventory/sales-orders/${orderId}`)]);
    await Promise.all([
        pageA.getByRole('button', { name: 'Create shipment' }).click(),
        pageB.getByRole('button', { name: 'Create shipment' }).click(),
    ]);
    await Promise.all([pageA.waitForTimeout(500), pageB.waitForTimeout(500)]);
    const shipmentUrls = [pageA.url(), pageB.url()].filter((url) => /\/inventory\/shipments\/\d+$/.test(url));
    expect(shipmentUrls).toHaveLength(1);
    const shipmentId = Number(shipmentUrls[0].match(/(\d+)$/)[1]);

    await Promise.all([pageA.goto(`/inventory/shipments/${shipmentId}`), pageB.goto(`/inventory/shipments/${shipmentId}`)]);
    for (const page of [pageA, pageB]) {
        const option = page.locator('label.serial-selector__opt').filter({ hasText: seeded.text });
        if (await option.count()) await option.click();
        const save = page.getByRole('button', { name: 'Save lot/serial' });
        if (await save.isVisible()) await save.click();
    }
    await Promise.all([pageA.reload(), pageB.reload()]);
    await Promise.all([
        pageA.getByRole('button', { name: 'Post shipment' }).click().then(() => pageA.locator('.modal').getByRole('button', { name: /Post/i }).click()),
        pageB.getByRole('button', { name: 'Post shipment' }).click().then(() => pageB.locator('.modal').getByRole('button', { name: /Post/i }).click()),
    ]);
    await Promise.all([pageA.waitForTimeout(700), pageB.waitForTimeout(700)]);

    const shipment = await api(pageA, 'GET', `/shipments/${shipmentId}`);
    expect(shipment.body.data.shipment.status).toBe('posted');
    expect(shipment.body.data.ledger).toHaveLength(1);
    const events = await api(pageA, 'GET', `/integration/solabooks/events?event_type=shipment.posted&per_page=100`);
    const rows = events.body.data?.data ?? events.body.data ?? [];
    const matchingEvents = rows.filter((event) => Number(event.aggregate_id) === shipmentId);
    expect(matchingEvents).toHaveLength(1);
    const delivered = await api(pageA, 'POST', `/integration/solabooks/events/${matchingEvents[0].id}/retry`);
    expect(delivered.status).toBe(200);
    const externalId = delivered.body.data.external_document_id;
    expect(externalId).toBeTruthy();
    const replay = await api(pageA, 'POST', `/integration/solabooks/events/${matchingEvents[0].id}/retry`);
    expect(replay.status).toBe(200);
    expect(replay.body.data.external_document_id).toBe(externalId);
    const serial = await api(pageA, 'GET', `/serials/${seeded.id}`);
    expect(serial.body.data.serial.status).toBe('sold');

    await pageA.goto('/sso/finance/redirect?organization_id=29');
    await pageA.goto(`/finance/entries/${externalId}`);
    await expect(pageA.locator('body')).toContainText(/SolaStock shipment\.posted/i);
    await expect(pageA.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);

    await Promise.all([contextA.close(), contextB.close()]);
});
