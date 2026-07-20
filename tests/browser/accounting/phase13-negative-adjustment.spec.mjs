import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(90_000);
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE13-20260720-${Date.now()}`;

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

test('visible negative adjustment reduces FIFO value and posts one balanced Finance loss journal', async ({ page }) => {
    await login(page);
    const candidate = await page.evaluate(async () => {
        const [balancesPayload, itemsPayload] = await Promise.all([
            fetch('/inventory/api/v1/balances?per_page=100').then((response) => response.json()),
            fetch('/inventory/api/v1/items?per_page=200&is_active=true').then((response) => response.json()),
        ]);
        const balances = balancesPayload.data?.data ?? balancesPayload.data ?? [];
        const items = itemsPayload.data?.data ?? itemsPayload.data ?? [];
        const allowed = new Set(items.filter((item) => !item.tracking_type || item.tracking_type === 'none').map((item) => Number(item.id)));
        return balances.find((row) => allowed.has(Number(row.item_id)) && Number(row.on_hand_qty) >= 2);
    });
    expect(candidate?.item_id).toBeTruthy();
    const openingQty = Number(candidate.on_hand_qty);

    await page.goto('/inventory/adjustments/new');
    await page.getByLabel('Document number').fill(`${prefix}-NEG`);
    await page.getByLabel('Warehouse').selectOption(String(candidate.warehouse_id));
    const reason = page.getByLabel('Reason code');
    if (await reason.evaluate((element) => element.tagName === 'SELECT')) await reason.selectOption({ index: 1 });
    else await reason.fill('damage');
    await page.getByLabel('Notes').fill('Phase 13 negative-adjustment accounting proof');
    const lines = page.locator('.panel').filter({ hasText: 'Lines' });
    await lines.locator('select').nth(0).selectOption('decrease');
    await lines.locator('select').nth(1).selectOption(String(candidate.item_id));
    await lines.locator('input[type="number"]').first().fill('1');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Adjustment posted.')).toBeVisible();
    await expect(page).toHaveURL(/\/inventory\/adjustments\/\d+/);
    const adjustmentId = Number(page.url().match(/(\d+)$/)[1]);
    const adjustmentNumber = (await page.locator('h1').innerText()).trim();

    const detail = await api(page, 'GET', `/adjustments/${adjustmentId}`);
    expect(detail.status).toBe(200);
    expect(detail.body.data.ledger).toHaveLength(1);
    const ledger = detail.body.data.ledger[0];
    expect(ledger.direction).toBe('out');
    expect(Number(ledger.quantity)).toBe(1);
    const inventoryCost = Math.abs(Number(ledger.total_cost));
    expect(inventoryCost).toBeGreaterThan(0);
    const balance = await api(page, 'GET', `/balances?warehouse_id=${candidate.warehouse_id}&item_id=${candidate.item_id}`);
    const balanceRows = balance.body.data?.data ?? balance.body.data ?? [];
    expect(Number(balanceRows.find((row) => Number(row.item_id) === Number(candidate.item_id)).on_hand_qty)).toBe(openingQty - 1);

    const events = await api(page, 'GET', '/integration/solabooks/events?status=pending&event_type=adjustment.posted&per_page=100');
    const eventRows = events.body.data?.data ?? events.body.data ?? [];
    const event = eventRows.find((row) => Number(row.aggregate_id) === adjustmentId);
    expect(event?.id).toBeTruthy();
    const delivered = await api(page, 'POST', `/integration/solabooks/events/${event.id}/retry`);
    expect(delivered.status).toBe(200);
    const externalId = delivered.body.data.external_document_id;
    expect(externalId).toBeTruthy();
    const replay = await api(page, 'POST', `/integration/solabooks/events/${event.id}/retry`);
    expect(replay.body.data.external_document_id).toBe(externalId);

    await page.goto('/sso/finance/redirect?organization_id=29');
    await page.goto(`/finance/entries/${externalId}`);
    await expect(page.locator('body')).toContainText(adjustmentNumber);
    await expect(page.locator('body')).toContainText(/Inventory Shrinkage|Adjustment Loss|6804/i);
    await expect(page.locator('body')).toContainText(/Inventory|1301/i);
    await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);
});
