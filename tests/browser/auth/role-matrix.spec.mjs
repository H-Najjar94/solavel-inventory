import { test, expect } from '@playwright/test';
import fs from 'node:fs';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    if (!line) throw new Error(`Missing protected QA credential for ${email}`);
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(passwordFor(email));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

test('owner and viewer reach the live QA inventory workspace', async ({ page }) => {
    for (const email of ['qa.owner@solavel.test', 'qa.viewer@solavel.test']) {
        await login(page, email);
        await expect(page.getByText('Solavel QA Org', {exact: true})).toBeVisible();
        const meta = await page.evaluate(async () => (await fetch('/inventory/api/v1/meta')).json());
        expect(meta.success).toBe(true);
        expect(meta.data.tenant_mode).toBe('live');
        await page.reload();
        await expect(page).toHaveURL(/\/inventory\/dashboard/);
        await page.goto('/sso/logout?to=%2Finventory%2Flogin');
    }
});

test('a Central-authenticated user without Inventory assignment is denied at SSO', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.noinventory@solavel.test');
    await page.locator('input[type="password"]').fill(passwordFor('qa.noinventory@solavel.test'));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/sso\/inventory/);
    await expect(page.getByText(/Access Denied/i)).toBeVisible();
});

test('live inventory shell is usable at mobile and tablet viewports', async ({ browser }) => {
    for (const viewport of [{width: 390, height: 844}, {width: 1024, height: 1366}]) {
        const context = await browser.newContext({viewport});
        const page = await context.newPage();
        await login(page, 'qa.owner@solavel.test');
        await expect(page.getByText('Solavel QA Org', {exact: true})).toBeVisible();
        await expect(page.locator('body')).not.toContainText('Error 500');
        await context.close();
    }
});
