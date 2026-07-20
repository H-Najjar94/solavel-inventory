import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });
test.setTimeout(90_000);
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE12-${Date.now()}`;

function ownerPassword() {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith('qa.owner@solavel.test'));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function firstNonTrackedItem(page) {
    return page.evaluate(async () => {
        const payload = await (await fetch('/inventory/api/v1/items?per_page=200&is_active=true')).json();
        const rows = payload.data?.data ?? payload.data ?? [];
        return rows.find((item) => !item.tracking_type || item.tracking_type === 'none')?.id;
    });
}

test('visible taxed purchase, receipt, fulfillment and Finance journal reconcile', async ({ page }) => {
    await login(page);
    const itemId = await firstNonTrackedItem(page);
    expect(itemId).toBeTruthy();

    await page.goto('/inventory/purchase-orders/new');
    await page.getByLabel('Warehouse').selectOption({ index: 1 });
    await page.getByLabel('Notes').fill(prefix);
    const poLines = page.locator('.panel').filter({ hasText: 'Lines' });
    await poLines.locator('select').first().selectOption(String(itemId));
    await poLines.locator('input[type="number"]').nth(0).fill('2');
    await poLines.locator('input[type="number"]').nth(1).fill('10');
    await page.getByLabel('Purchase tax line 1').selectOption('QA12-STD16');
    await expect(poLines).toContainText('23.20');
    await page.getByRole('button', { name: 'Create PO' }).click();
    await expect(page).toHaveURL(/\/inventory\/purchase-orders\/\d+/);
    const poUrl = page.url();
    await expect(page.locator('.kv')).toContainText('3.20');
    await expect(page.locator('.kv')).toContainText('23.20');
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await page.locator('.modal').getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText('PO approved.')).toBeVisible();
    await page.getByRole('button', { name: 'Create GRN' }).click();

    await page.getByLabel('GRN number').fill(`${prefix}-GRN`);
    await page.getByLabel('Notes').fill(prefix);
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('GRN posted — stock received.')).toBeVisible();
    await expect(page).toHaveURL(/\/inventory\/goods-receipts\/\d+/);
    const grnNumber = (await page.locator('h1').innerText()).trim();

    await page.goto('/inventory/sales-orders/new');
    await page.getByLabel('Order number').fill(`${prefix}-SO`);
    await page.getByLabel('Customer name').fill('QA Phase 12 Tax Customer');
    await page.getByLabel('Warehouse').selectOption({ index: 1 });
    await page.getByLabel('Notes').fill(prefix);
    const soLines = page.locator('.panel').filter({ hasText: 'Lines' });
    await soLines.locator('select').first().selectOption(String(itemId));
    await soLines.locator('input[type="number"]').nth(0).fill('1');
    await soLines.locator('input[type="number"]').nth(1).fill('20');
    await page.getByLabel('Sales tax line 1').selectOption('QA12-STD16');
    await expect(soLines).toContainText('23.20');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/sales-orders\/\d+/);
    await expect(page.locator('.kv')).toContainText('3.20');
    await expect(page.locator('.kv')).toContainText('23.20');
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Sales order confirmed.')).toBeVisible();
    await page.getByRole('button', { name: 'Reserve stock' }).click();
    await expect(page.getByText('Stock reserved.')).toBeVisible();
    await page.getByRole('button', { name: 'Create pick list' }).click();
    await expect(page).toHaveURL(/\/inventory\/pick-lists\/\d+/);
    await page.getByRole('button', { name: 'Mark picked' }).click();
    await expect(page.getByText('Pick list marked picked.')).toBeVisible();
    await page.getByRole('button', { name: 'Create pack' }).click();
    await expect(page).toHaveURL(/\/inventory\/packs\/\d+/);
    await page.getByRole('button', { name: 'Mark packed' }).click();
    await expect(page.getByText('Pack marked packed.')).toBeVisible();
    await page.getByRole('link', { name: 'Go to order to ship' }).click();
    await page.getByRole('button', { name: 'Create shipment' }).click();
    await expect(page).toHaveURL(/\/inventory\/shipments\/\d+/);
    const shipmentNumber = (await page.locator('h1').innerText()).trim();
    await page.getByRole('button', { name: 'Post shipment' }).click();
    await page.locator('.modal').getByRole('button', { name: /Post/i }).click();
    await expect(page.getByText('Shipment posted — stock shipped OUT.')).toBeVisible();

    await page.goto('/inventory/integrations/solabooks/events');
    await page.locator('.toolbar select').nth(0).selectOption('pending');
    await page.locator('.toolbar select').nth(1).selectOption('shipment.posted');
    const pendingEvent = await page.evaluate(async (number) => {
        const payload = await (await fetch('/inventory/api/v1/integration/solabooks/events?status=pending&event_type=shipment.posted&per_page=100')).json();
        const rows = payload.data?.data ?? payload.data ?? [];
        return rows.find((row) => row.aggregate_number === number);
    }, shipmentNumber);
    expect(pendingEvent?.id).toBeTruthy();
    const eventRow = page.locator('tbody tr').filter({ hasText: shipmentNumber }).first();
    await expect(eventRow).toContainText('shipment.posted');
    await eventRow.getByRole('button', { name: 'View' }).click();
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByText('Retry attempted.')).toBeVisible();

    const event = await page.evaluate(async (number) => {
        const payload = await (await fetch('/inventory/api/v1/integration/solabooks/events?status=sent&event_type=shipment.posted&per_page=100')).json();
        const rows = payload.data?.data ?? payload.data ?? [];
        return rows.find((row) => row.aggregate_number === number);
    }, shipmentNumber);
    expect(event?.external_document_id).toBeTruthy();

    await page.goto('/sso/finance/redirect?organization_id=29');
    await expect(page).toHaveURL(/\/finance\//);
    await page.goto(`/finance/entries/${event.external_document_id}`);
    await expect(page.locator('body')).toContainText(shipmentNumber);
    await expect(page.locator('body')).toContainText(/VAT Output|2201|VAT_STD_B/i);
    await expect(page.locator('body')).toContainText(/Accounts Receivable|Sales|COGS|Inventory/i);
    await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);

    await page.goto('/inventory/integrations/solabooks/events');
    await page.locator('.toolbar select').nth(0).selectOption('pending');
    await page.locator('.toolbar select').nth(1).selectOption('grn.posted');
    const grnRow = page.locator('tbody tr').filter({ hasText: grnNumber }).first();
    await expect(grnRow).toContainText('grn.posted');
    await grnRow.getByRole('button', { name: 'View' }).click();
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByText('Retry attempted.')).toBeVisible();
    const grnEvent = await page.evaluate(async (number) => {
        const payload = await (await fetch('/inventory/api/v1/integration/solabooks/events?status=sent&event_type=grn.posted&per_page=100')).json();
        const rows = payload.data?.data ?? payload.data ?? [];
        return rows.find((row) => row.aggregate_number === number);
    }, grnNumber);
    expect(grnEvent?.external_document_id).toBeTruthy();
    await page.goto('/sso/finance/redirect?organization_id=29');
    await page.goto(`/finance/entries/${grnEvent.external_document_id}`);
    await expect(page.locator('body')).toContainText(grnNumber);
    await expect(page.locator('body')).toContainText(/VAT Input|1703|VAT_STD_B/i);
    await expect(page.locator('body')).toContainText(/Inventory|Accounts Payable|GRNI/i);
    await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);

    expect(poUrl).toMatch(/\/purchase-orders\/\d+$/);
});
