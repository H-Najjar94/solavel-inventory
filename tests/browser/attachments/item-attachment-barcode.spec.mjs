import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = 'QA-SOLASTOCK-PRACTICAL-20260719-';

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

const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

test('authenticated item attachment lifecycle and barcode label output', async ({ page, browser }) => {
    await login(page);
    const name = `${prefix}Attachment ${Date.now()}`;
    const sku = `${prefix}SKU-${Date.now()}`;
    const barcode = `890${Date.now()}`;

    await page.goto('/inventory/items/new');
    await page.getByLabel('Item name').fill(name);
    await page.getByLabel('SKU').fill(sku);
    await page.getByLabel('Barcode').fill(barcode);
    await page.getByRole('button', { name: 'Create item' }).click();
    await expect(page).toHaveURL(/\/inventory\/items\/\d+/);

    const itemUrl = page.url();
    await page.getByRole('button', { name: 'Media' }).click();
    const chooser = page.locator('input[type="file"]').nth(1);
    await chooser.setInputFiles({ name: 'qa-photo.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByText('qa-photo.png')).toBeVisible();
    await chooser.setInputFiles({ name: 'مواصفة.pdf', mimeType: 'application/pdf', buffer: pdf });
    await expect(page.getByText('مواصفة.pdf')).toBeVisible();

    await page.reload();
    await page.getByRole('button', { name: 'Media' }).click();
    await expect(page.getByText('qa-photo.png')).toBeVisible();
    await expect(page.getByText('مواصفة.pdf')).toBeVisible();

    const downloadLinks = page.locator('a').filter({ hasText: /qa-photo|مواصفة/ });
    expect(await downloadLinks.count()).toBe(2);
    const href = await page.locator('tr').filter({ hasText: 'qa-photo.png' }).locator('a').getAttribute('href');
    const remainingHref = await page.locator('tr').filter({ hasText: 'مواصفة.pdf' }).locator('a').getAttribute('href');
    expect(href).toMatch(/item-attachments/);
    const response = await page.request.get(new URL(href, page.url()).toString());
    expect(response.ok()).toBeTruthy();
    await page.locator('tr').filter({ hasText: 'qa-photo.png' }).getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('qa-photo.png')).not.toBeVisible();
    const removed = await page.request.get(new URL(`${href}?qa_cache_bust=${Date.now()}`, page.url()).toString());
    expect(removed.status()).toBe(404);

    await page.getByRole('button', { name: 'Barcodes' }).click();
    await page.getByPlaceholder('Scan or type barcode').fill(barcode);
    await page.getByRole('button', { name: 'Add barcode' }).click();
    await expect(page.getByRole('cell', { name: barcode })).toBeVisible();

    const labelResponse = await page.request.get(`${new URL(itemUrl).origin}/inventory/api/v1/items/${itemUrl.split('/').pop()}/labels`);
    expect(labelResponse.ok()).toBeTruthy();
    const labelJson = await labelResponse.json();
    expect(labelJson.success).toBe(true);
    expect(JSON.stringify(labelJson)).toContain(sku);
    const barcodeSvg = labelJson.data.labels.find((label) => label.barcode === barcode).barcode_svg;
    expect(barcodeSvg).toContain('<svg');
    const pngPath = `/tmp/solastock-barcode-${Date.now()}.png`;
    try {
        execFileSync('convert', ['svg:-', `png:${pngPath}`], { input: barcodeSvg });
        const decoded = execFileSync('zbarimg', ['--quiet', pngPath], { encoding: 'utf8' }).trim();
    expect(decoded).toContain(barcode);
    } finally {
        fs.rmSync(pngPath, { force: true });
    }

    const viewerContext = await browser.newContext();
    const viewerPage = await viewerContext.newPage();
    await viewerPage.goto('/inventory/login');
    await viewerPage.locator('input[type="email"]').fill('qa.viewer@solavel.test');
    await viewerPage.locator('input[type="password"]').fill(passwordFor('qa.viewer@solavel.test'));
    await viewerPage.locator('button[type="submit"]').click();
    await expect(viewerPage).toHaveURL(/\/inventory\/dashboard/);
    const viewerMeta = await viewerPage.evaluate(async () => (await fetch('/inventory/api/v1/meta')).json());
    expect(viewerMeta.data.permissions).not.toContain('inventory.manage_items');
    const viewerAttachment = await viewerPage.request.get(new URL(remainingHref, itemUrl).toString());
    expect(viewerAttachment.ok()).toBeTruthy();
    const viewerDeleteStatus = await viewerPage.evaluate(async (url) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        return (await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })).status;
    }, new URL(remainingHref, itemUrl).toString());
    expect(viewerDeleteStatus).toBe(403);
    await viewerContext.close();
});
