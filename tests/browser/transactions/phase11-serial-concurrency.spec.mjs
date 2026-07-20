import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE11-20260720-${Date.now()}`;
const serialText = 'QA6-SERIAL-002';

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

test('two browser contexts cannot reserve the same last available serial', async ({ browser }) => {
    const contextA = await browser.newContext();
    const contextB = await browser.newContext();
    const pageA = await contextA.newPage();
    const pageB = await contextB.newPage();
    await Promise.all([login(pageA), login(pageB)]);

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
        expect(pageA.getByText(serialText)).toBeVisible(),
        expect(pageB.getByText(serialText)).toBeVisible(),
    ]);
    await Promise.all([
        pageA.locator('label.serial-selector__opt').filter({ hasText: serialText }).click(),
        pageB.locator('label.serial-selector__opt').filter({ hasText: serialText }).click(),
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
    const activeA = (detailA.body.data.sales_order.reservations ?? []).filter((row) => row.status === 'active' && row.serial_id === 2);
    const activeB = (detailB.body.data.sales_order.reservations ?? []).filter((row) => row.status === 'active' && row.serial_id === 2);
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
    await loserPage.locator('label.serial-selector__opt').filter({ hasText: serialText }).click();
    await loserPage.getByRole('button', { name: 'Reserve stock' }).click();
    await expect(loserPage.getByText('Stock reserved.')).toBeVisible();

    const finalLoser = await api(loserPage, 'GET', `/sales-orders/${loserOrder}`);
    expect(finalLoser.body.data.sales_order.reservations.filter((row) => row.status === 'active' && row.serial_id === 2)).toHaveLength(1);
    await api(loserPage, 'POST', `/sales-orders/${loserOrder}/release-reservation`);
    await Promise.all([
        api(pageA, 'POST', `/sales-orders/${winnerOrder}/cancel`),
        api(pageB, 'POST', `/sales-orders/${loserOrder}/cancel`),
    ]);
    await Promise.all([contextA.close(), contextB.close()]);
});
