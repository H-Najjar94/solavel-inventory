import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.use({ trace: 'off', screenshot: 'off', video: 'off' });

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');

function ownerPassword() {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith('qa.owner@solavel.test'));
    return line.match(/password:\s*(\S+)/)[1];
}

test('QA owner launches organization 29 in Finance through Central SSO', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    await page.goto('/sso/finance/redirect?organization_id=29');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).not.toContain('access-unavailable');
    expect(page.url()).toMatch(/\/finance\//);
    await expect(page.locator('body')).toContainText(/Solavel QA Org|SolaBooks|Finance/i);

    for (const path of ['/finance/accounts', '/finance/entries', '/finance/reports/trial-balance', '/finance/reports/balance-sheet', '/finance/reports/profit-and-loss', '/finance/reports/vat-detail-by-rate']) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBeLessThan(400);
        expect(page.url(), path).not.toContain('access-unavailable');
        await expect(page.locator('body'), path).not.toContainText(/Server Error|Whoops|not authorized/i);
    }
});

test('QA owner completes the canonical Finance setup wizard for the linked QA organization', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
    await page.goto('/sso/finance/redirect?organization_id=29');
    await expect(page).toHaveURL(/\/finance\//);

    await page.goto('/finance/orgs/5/setup/step-1');
    if (page.url().includes('/finance/orgs/5/setup/step-1')) {
        await page.locator('.ts-wrapper').nth(0).click();
        await page.locator('.ts-dropdown .option[data-value="1"]:visible').click();
        await page.locator('.ts-wrapper').nth(1).click();
        await page.locator('.ts-dropdown .option[data-value="71"]:visible').click();
        await page.locator('form').filter({ has: page.locator('select[name="country_id"]') }).locator('button[type="submit"]').click();
        await expect(page).toHaveURL(/\/finance\/orgs\/5\/setup\/step-2/);
    }

    if (page.url().includes('/finance/orgs/5/setup/step-2')) {
        await page.locator('form').filter({ has: page.locator('select[name="fiscal_year_end_month"]') }).locator('button[type="submit"]').first().click();
        await expect(page).toHaveURL(/\/finance\/orgs\/5\/setup\/step-3/);
    }

    if (page.url().includes('/finance/orgs/5/setup/step-3')) {
        await page.getByRole('button', { name: /Next: Chart of Accounts/i }).click();
        await expect(page).toHaveURL(/\/finance\/orgs\/5\/setup\/step-4/);
    }

    if (page.url().includes('/finance/orgs/5/setup/step-4')) {
        await page.getByRole('button', { name: /Provision|Finish/i }).click();
        await expect(page).toHaveURL(/\/finance(?:\/|$)/);
    }

    await page.goto('/finance/accounts');
    await expect(page.locator('body')).toContainText(/Inventory|Revenue|Cost of Goods|Accounts Payable/i);
});

test('QA owner creates a scoped Finance key and connects Inventory without exposing it', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    const status = await page.evaluate(async () => (await fetch('/inventory/api/v1/integration/solabooks/status')).json());
    if (!status.data.delivery_configured || status.data.solabooks_organization_id !== 5) {
        await page.goto('/sso/finance/redirect?organization_id=29');
        await expect(page).toHaveURL(/\/finance\//);
        const created = await page.evaluate(async () => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/finance/settings/api-access/keys', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    name: `QA-SOLASTOCK-PHASE11-${Date.now()}`,
                    scopes: ['journal_entries.get', 'journal_entries.post', 'reports.balance_sheet.get', 'reports.trial_balance.get'],
                }),
            });
            if (!response.ok) throw new Error(`Finance key creation failed with HTTP ${response.status}`);
            return response.json();
        });
        expect(created.key).toMatch(/^solabooks_/);

        await page.goto('/inventory/settings/solabooks');
        await page.getByLabel('SolaBooks organization ID').fill('5');
        await page.getByLabel('Central client ID').fill('990010');
        await page.getByLabel('SolaBooks API key').fill(created.key);
        await page.getByLabel('Mode').selectOption('connected_pending_mapping');
        await page.getByRole('button', { name: 'Save connection' }).click();
        await expect(page.getByText('SolaBooks connection saved.')).toBeVisible();
    }

    const after = await page.evaluate(async () => (await fetch('/inventory/api/v1/integration/solabooks/status')).json());
    expect(after.data.delivery_configured).toBe(true);
    expect(after.data.solabooks_organization_id).toBe(5);
    const serialized = JSON.stringify(after);
    expect(serialized).not.toContain('"api_key"');
    expect(serialized).not.toContain('api_key_encrypted');
    expect(serialized).not.toContain('"secret"');
});

test('QA owner maps every required Inventory concept to visible QA Finance accounts', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    const mappings = {
        inventory_asset: ['700', '1301', 'Inventory – Goods'],
        cogs: ['779', '5001', 'COGS – Products'],
        adjustment_gain: ['776', '4303', 'Inventory Adjustment Gain'],
        adjustment_loss: ['802', '6804', 'Inventory Shrinkage / Adjustment Loss'],
        grni: ['734', '2100', 'Accounts Payable (AP)'],
        landed_cost_clearing: ['715', '1580', 'Asset Clearing'],
        transfer_clearing: ['715', '1580', 'Asset Clearing'],
        opening_offset: ['767', '3501', 'Opening Balance Equity'],
        sales_returns: ['769', '4102', 'Sales Returns (Contra)'],
        purchase_returns: ['781', '5103', 'Purchase Discounts (Contra)'],
        accounts_receivable: ['699', '1200', 'Accounts Receivable (AR)'],
        sales_revenue: ['768', '4101', 'Sales – Products'],
    };

    await page.goto('/inventory/settings/solabooks');
    await page.getByRole('button', { name: 'Account mappings' }).click();
    for (const [type, values] of Object.entries(mappings)) {
        const row = page.locator('tr').filter({ hasText: type.replaceAll('_', ' ') });
        const inputs = row.locator('input');
        await inputs.nth(0).fill(values[0]);
        await inputs.nth(1).fill(values[1]);
        await inputs.nth(2).fill(values[2]);
    }
    await page.getByRole('button', { name: 'Save mappings' }).click();
    await expect(page.getByText('Account mappings saved.')).toBeVisible();

    const status = await page.evaluate(async () => (await fetch('/inventory/api/v1/integration/solabooks/status')).json());
    expect(status.data.mapping_completeness_pct).toBe(100);

    await page.getByRole('button', { name: 'Status' }).click();
    await page.getByLabel('Mode').selectOption('active');
    await page.getByRole('button', { name: 'Save connection' }).click();
    await expect(page.getByText('SolaBooks connection saved.')).toBeVisible();
    const active = await page.evaluate(async () => (await fetch('/inventory/api/v1/integration/solabooks/status')).json());
    expect(active.data.mode).toBe('active');
    expect(active.data.delivery_configured).toBe(true);
});

test('QA owner delivers a new mapped adjustment to Finance and retry stays idempotent', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    await page.goto('/inventory/adjustments/new');
    await page.getByLabel('Document number').fill(`QA11-ACC-${Date.now()}`);
    await page.getByLabel('Warehouse').selectOption('1');
    await page.getByLabel('Reason code').selectOption({ index: 1 });
    await page.getByLabel('Notes').fill('Phase 11 accounting delivery proof');
    await page.locator('.panel select').nth(1).selectOption('1');
    await page.locator('.panel input[type="number"]').first().fill('1');
    await page.locator('.panel input[type="number"]').last().fill('1');
    await page.getByRole('button', { name: 'Save & post' }).click();
    await expect(page.getByText('Adjustment posted.')).toBeVisible();

    await page.goto('/inventory/integrations/solabooks/events');
    await page.locator('.toolbar select').nth(0).selectOption('pending');
    await page.locator('.toolbar select').nth(1).selectOption('adjustment.posted');
    const row = page.locator('tbody tr').first();
    await expect(row).toContainText('adjustment.posted');
    const documentText = (await row.locator('td').nth(2).innerText()).trim();
    await row.getByRole('button', { name: 'View' }).click();
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByText('Retry attempted.')).toBeVisible();

    await page.locator('.toolbar select').nth(0).selectOption('sent');
    const sent = page.locator('tbody tr').filter({ hasText: documentText }).first();
    await expect(sent).toContainText('sent');
    await sent.getByRole('button', { name: 'View' }).click();
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByText('Retry attempted.')).toBeVisible();
    await expect(page.locator('.modal')).toHaveCount(0);
});

test('the delivered adjustment is visible as a balanced journal in the linked Finance organization', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(ownerPassword());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    const event = await page.evaluate(async () => {
        const payload = await (await fetch('/inventory/api/v1/integration/solabooks/events?status=sent&event_type=adjustment.posted&per_page=10')).json();
        const rows = payload.data?.data ?? payload.data ?? [];
        return rows.find((row) => row.external_document_id);
    });
    expect(event.external_document_id).toBeTruthy();
    expect(event.idempotency_key).toMatch(/^solabooks:adjustment\.posted:/);

    await page.goto('/sso/finance/redirect?organization_id=29');
    await expect(page).toHaveURL(/\/finance\//);
    const response = await page.goto(`/finance/entries/${event.external_document_id}`);
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('body')).toContainText(event.aggregate_number);
    await expect(page.locator('body')).toContainText(/SolaStock adjustment\.posted/i);
    await expect(page.locator('body')).toContainText(/Debit|Credit/i);
    await expect(page.locator('body')).not.toContainText(/out of balance|Server Error|Whoops/i);
});
