import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const prefix = `QA-SOLASTOCK-PHASE7-${Date.now()}`;

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

test('owner posts a transfer, adjustment, and stock count through visible forms', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page);

    await page.goto('/inventory/transfers/new');
    await page.locator('.form-grid input').first().fill(`${prefix}-TRANSFER`);
    await page.locator('.form-grid select').nth(0).selectOption('1');
    await page.locator('.form-grid select').nth(1).selectOption('2');
    await page.locator('.panel select').first().selectOption('1');
    await page.locator('.panel input[type="number"]').first().fill('1');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Transfer posted.')).toBeVisible();
    await expect(page).toHaveURL(/\/inventory\/transfers\/\d+/);

    await page.goto('/inventory/adjustments/new');
    await page.getByLabel('Document number').fill(`${prefix}-ADJUSTMENT`);
    await page.getByLabel('Warehouse').selectOption('1');
    const reason = page.getByLabel('Reason code');
    if (await reason.evaluate((element) => element.tagName === 'SELECT')) await reason.selectOption({ index: 1 });
    else await reason.fill('found');
    await page.getByLabel('Notes').fill('QA7 browser verification');
    await page.locator('.panel select').nth(1).selectOption('1');
    await page.locator('.panel input[type="number"]').first().fill('1');
    await page.locator('.panel input[type="number"]').last().fill('3');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Adjustment posted.')).toBeVisible();
    await expect(page).toHaveURL(/\/inventory\/adjustments\/\d+/);

    await page.goto('/inventory/counts/new');
    await page.locator('.form-grid select').nth(1).selectOption('1');
    await page.getByRole('button', { name: 'Prefill expected from stock' }).click();
    await expect(page.locator('.panel input.input--num').first()).toBeVisible();
    const countRows = page.locator('tbody tr');
    expect(await countRows.count()).toBeGreaterThan(0);
    await countRows.evaluateAll((rows) => {
        for (const row of rows) {
            const input = row.querySelector('input[type="number"]');
            if (! input) continue;

            input.value = row.cells[3]?.textContent?.match(/-?[\d.]+/)?.[0] ?? '0';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    const postCount = page.getByRole('button', { name: 'Save & post variance' });
    await expect(postCount).toBeEnabled();
    await postCount.scrollIntoViewIfNeeded();
    await postCount.click();
    await expect(page.getByText('Count posted — variance adjustment created.')).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveURL(/\/inventory\/counts\/\d+/);
});
