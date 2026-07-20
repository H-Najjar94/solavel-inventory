import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
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

test('owner changes and restores a live inventory setting through the UI', async ({ page }) => {
    await login(page);
    const initial = await page.evaluate(async () => (await fetch('/inventory/api/v1/settings')).json());
    const original = initial.data.settings?.default_costing_method ?? 'average';
    const changed = original === 'fifo' ? 'average' : 'fifo';
    await page.goto('/inventory/settings');
    await page.getByLabel('Default costing method').selectOption(changed);
    const saveResponse = page.waitForResponse((response) => response.url().includes('/inventory/api/v1/settings') && response.request().method() === 'PUT');
    await page.getByRole('button', { name: 'Save policy' }).click();
    expect((await saveResponse).status()).toBe(200);
    await expect(page.getByText('Settings saved.')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Default costing method')).toHaveValue(changed);
    await page.getByLabel('Default costing method').selectOption(original);
    await page.getByRole('button', { name: 'Save policy' }).click();
    await page.reload();
    await expect(page.getByLabel('Default costing method')).toHaveValue(original);
});

test('item edit remains usable at mobile, tablet, and desktop viewports', async ({ browser }) => {
    for (const viewport of [{ width: 390, height: 844 }, { width: 1024, height: 1366 }, { width: 1440, height: 1000 }]) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        await login(page);
        const items = await page.evaluate(async () => (await fetch('/inventory/api/v1/items?search=QA-SOLASTOCK-PRACTICAL-20260719-&per_page=1')).json());
        const item = items.data?.data?.[0] ?? items.data?.[0];
        expect(item?.id).toBeTruthy();
        await page.goto(`/inventory/items/${item.id}/edit`);
        const description = `responsive-verified-${viewport.width}`;
        await page.getByLabel('Description').fill(description);
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page).toHaveURL(new RegExp(`/inventory/items/${item.id}$`));
        await page.reload();
        await page.getByText('Details', { exact: true }).click();
        await expect(page.getByText(description)).toBeVisible();
        await context.close();
    }
});

test('owner creates and removes an organization tax through the UI', async ({ page }) => {
    await login(page);
    await page.goto('/inventory/settings');
    const taxPanel = page.locator('.panel').filter({ hasText: 'Tax administration' });
    await taxPanel.getByLabel('Code').fill('QA-VAT-20260719');
    await taxPanel.getByLabel('Name').fill('QA Standard VAT');
    await taxPanel.getByRole('spinbutton', { name: 'Rate', exact: true }).fill('15');
    await taxPanel.getByRole('button', { name: 'Add tax' }).click();
    await expect(taxPanel.getByText('QA Standard VAT')).toBeVisible();
    await taxPanel.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.')).toBeVisible();
    await page.reload();
    await expect(taxPanel.getByText('QA Standard VAT')).toBeVisible();
    const response = await page.evaluate(async () => (await fetch('/inventory/api/v1/settings')).json());
    expect(response.data.settings.taxes.some((tax) => tax.code === 'QA-VAT-20260719')).toBe(true);
    await taxPanel.getByRole('row', { name: /QA-VAT-20260719/ }).getByRole('button', { name: 'Remove' }).click();
    await taxPanel.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.')).toBeVisible();
});
