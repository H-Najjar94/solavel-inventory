import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(120_000);
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const prefix = `QA-SOLASTOCK-PHASE13-20260720-${Date.now()}`;
const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF');

function password(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password(email));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

async function openMedia(page, itemId) {
    await page.goto(`/inventory/items/${itemId}`);
    await page.getByRole('button', { name: 'Media' }).click();
    return page.locator('input[type="file"]').nth(1);
}

async function upload(page, chooser, file) {
    const uploadButton = page.getByRole('button', { name: 'Upload attachment' });
    await expect(uploadButton).toBeEnabled({ timeout: 20_000 });
    const [response] = await Promise.all([
        page.waitForResponse((candidate) => candidate.url().includes('/attachments') && candidate.request().method() === 'POST', { timeout: 30_000 }),
        chooser.setInputFiles(file),
    ]);
    await expect(uploadButton).toBeEnabled({ timeout: 20_000 });
    return response;
}

test('live attachment negative, header, retry, role and cleanup matrix', async ({ browser }) => {
    test.setTimeout(150_000);
    const ownerContext = await browser.newContext();
    const owner = await ownerContext.newPage();
    await login(owner, 'qa.owner@solavel.test');
    await owner.goto('/inventory/items/new');
    await owner.getByLabel('Item name').fill(`${prefix} Attachment Matrix`);
    await owner.getByLabel('SKU').fill(`${prefix}-ATT`);
    await owner.getByRole('button', { name: 'Create item' }).click();
    await expect(owner).toHaveURL(/\/inventory\/items\/\d+$/);
    const itemId = Number(owner.url().match(/(\d+)$/)[1]);
    const chooser = await openMedia(owner, itemId);

    const rejected = [
        { name: 'unsupported.txt', mimeType: 'text/plain', buffer: Buffer.from('not allowed') },
        { name: 'empty.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(0) },
        { name: 'mismatch.jpg', mimeType: 'image/jpeg', buffer: pdf },
        { name: 'executable.php', mimeType: 'application/x-php', buffer: Buffer.from('<?php echo 1;') },
        { name: `${'a'.repeat(192)}.pdf`, mimeType: 'application/pdf', buffer: pdf },
        { name: 'corrupt.pdf', mimeType: 'application/pdf', buffer: Buffer.from('not a pdf') },
        { name: 'active.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n/JavaScript /JS /Launch\n%%EOF') },
    ];
    for (const file of rejected) {
        const response = await upload(owner, chooser, file);
        expect(response.status(), file.name).toBe(422);
    }

    await chooser.setInputFiles({ name: 'oversized.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(10 * 1024 * 1024 + 1, 1) });
    await expect(owner.getByText('Attachment must be 10 MB or smaller.')).toBeVisible();
    let attachments = await owner.evaluate(async (id) => (await (await fetch(`/inventory/api/v1/items/${id}/attachments`)).json()).data, itemId);
    expect(attachments).toHaveLength(0);

    const names = ['دليل مكرر.pdf', 'دليل مكرر.pdf', 'ユニコード #1 (نهائي).pdf'];
    for (const name of names) expect((await upload(owner, chooser, { name, mimeType: 'application/pdf', buffer: pdf })).status()).toBe(201);
    attachments = await owner.evaluate(async (id) => (await (await fetch(`/inventory/api/v1/items/${id}/attachments`)).json()).data, itemId);
    expect(attachments).toHaveLength(3);
    expect(new Set(attachments.map((row) => row.id)).size).toBe(3);
    const target = attachments[0];
    const download = await owner.request.get(`/inventory/api/v1/item-attachments/${target.id}`);
    expect(download.ok()).toBeTruthy();
    expect(download.headers()['x-content-type-options']).toBe('nosniff');
    expect(download.headers()['content-security-policy']).toContain('sandbox');
    expect(download.headers()['content-disposition']).toMatch(/inline|attachment/i);
    expect(download.headers()['content-type']).toContain('application/pdf');
    expect((await owner.request.get('/inventory/api/v1/item-attachments/999999999')).status()).toBe(404);

    const managerContext = await browser.newContext();
    const manager = await managerContext.newPage();
    await login(manager, 'qa.manager@solavel.test');
    const managerChooser = await openMedia(manager, itemId);
    expect((await upload(manager, managerChooser, { name: 'manager.pdf', mimeType: 'application/pdf', buffer: pdf })).status()).toBe(201);
    const managerAttachment = (await manager.evaluate(async (id) => (await (await fetch(`/inventory/api/v1/items/${id}/attachments`)).json()).data, itemId)).find((row) => row.name === 'manager.pdf');
    const managerDelete = await manager.evaluate(async (id) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        return (await fetch(`/inventory/api/v1/item-attachments/${id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })).status;
    }, managerAttachment.id);
    expect(managerDelete).toBe(200);

    const viewerContext = await browser.newContext();
    const viewer = await viewerContext.newPage();
    await login(viewer, 'qa.viewer@solavel.test');
    expect((await viewer.request.get(`/inventory/api/v1/item-attachments/${target.id}`)).status()).toBe(200);
    const viewerMutation = await viewer.evaluate(async ({ itemId, attachmentId }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const form = new FormData();
        form.append('attachment', new Blob(['%PDF-1.4\n%%EOF'], { type: 'application/pdf' }), 'viewer.pdf');
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' };
        return {
            upload: (await fetch(`/inventory/api/v1/items/${itemId}/attachments`, { method: 'POST', credentials: 'same-origin', headers, body: form })).status,
            remove: (await fetch(`/inventory/api/v1/item-attachments/${attachmentId}`, { method: 'DELETE', credentials: 'same-origin', headers })).status,
        };
    }, { itemId, attachmentId: target.id });
    expect(viewerMutation).toEqual({ upload: 403, remove: 403 });

    for (const attachment of attachments) {
        const status = await owner.evaluate(async (id) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            return (await fetch(`/inventory/api/v1/item-attachments/${id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })).status;
        }, attachment.id);
        expect(status).toBe(200);
    }
    expect((await owner.request.get(`/inventory/api/v1/item-attachments/${target.id}?cache=${Date.now()}`)).status()).toBe(404);
    const repeatedDelete = await owner.evaluate(async (id) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        return (await fetch(`/inventory/api/v1/item-attachments/${id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })).status;
    }, target.id);
    expect(repeatedDelete).toBe(404);
    const audit = await owner.evaluate(async () => (await (await fetch('/inventory/api/v1/audit-logs?entity_type=item_attachment&per_page=100')).json()).data);
    expect(audit.some((row) => row.action === 'item.attachment.created')).toBeTruthy();
    expect(audit.some((row) => row.action === 'item.attachment.deleted')).toBeTruthy();
    await Promise.all([ownerContext.close(), managerContext.close(), viewerContext.close()]);
});
