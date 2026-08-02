import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.use({ trace: 'off', screenshot: 'off', video: 'off' });
const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');

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

test('owner creates standard, zero and exempt taxes visibly and maps them to Finance', async ({ page }) => {
    await login(page);
    const existingCodes = await page.evaluate(async () => {
        const response = await fetch('/inventory/api/v1/settings');
        const payload = await response.json();
        const taxes = payload.data?.settings?.taxes ?? [];
        const unique = [...new Map(taxes.map((tax) => [String(tax.code).trim().toUpperCase(), { ...tax, code: String(tax.code).trim().toUpperCase() }])).values()];
        if (unique.length !== taxes.length) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const saved = await fetch('/inventory/api/v1/settings/taxes', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ taxes: unique }),
            });
            if (!saved.ok) throw new Error(`Tax cleanup failed with HTTP ${saved.status}`);
        }
        return unique.map((tax) => tax.code);
    });
    await page.goto('/inventory/settings');
    const panel = page.locator('.panel').filter({ hasText: 'Tax administration' });
    const definitions = [
        ['QA12-STD16', 'QA Phase 12 Standard VAT', '16', 'standard'],
        ['QA12-ZERO', 'QA Phase 12 Zero Rated', '0', 'zero'],
        ['QA12-EXEMPT', 'QA Phase 12 Exempt', '0', 'exempt'],
    ];
    for (const [code, name, rate, treatment] of definitions) {
        if (existingCodes.includes(code)) continue;
        const form = panel.locator('form').first();
        await form.getByLabel('Code', { exact: true }).fill(code);
        await form.getByLabel('Name', { exact: true }).fill(name);
        await form.getByRole('spinbutton', { name: 'Rate', exact: true }).fill(rate);
        await form.getByLabel('Treatment').selectOption(treatment);
        await form.getByRole('button', { name: 'Add tax' }).click();
        await expect(panel.getByLabel(`Tax name ${code}`)).toHaveValue(name);
    }
    await panel.getByRole('button', { name: 'Save tax settings' }).click();
    await expect(page.getByText('Tax settings saved.')).toBeVisible();
    await page.reload();
    for (const [code, name] of definitions) await expect(panel.getByLabel(`Tax name ${code}`)).toHaveValue(name);

    await page.goto('/inventory/settings/solabooks');
    await page.getByRole('button', { name: 'Account mappings' }).click();
    for (const [type, values] of Object.entries({
        accounts_receivable: ['699', '1200', 'Accounts Receivable (AR)'],
        sales_revenue: ['768', '4101', 'Sales – Products'],
    })) {
        const row = page.locator('tr').filter({ hasText: type.replaceAll('_', ' ') });
        const inputs = row.locator('input');
        await inputs.nth(0).fill(values[0]);
        await inputs.nth(1).fill(values[1]);
        await inputs.nth(2).fill(values[2]);
    }
    await page.getByRole('button', { name: 'Save mappings' }).click();
    await expect(page.getByText('Account mappings saved.')).toBeVisible();

    await page.getByRole('button', { name: 'Tax mappings' }).click();
    const mappingValues = {
        'QA12-STD16': ['7', 'VAT_STD_B', '647', '648'],
        'QA12-ZERO': ['8', 'VAT_ZR_B', '', ''],
        'QA12-EXEMPT': ['9', 'VAT_EX_B', '', ''],
    };
    for (const [code, values] of Object.entries(mappingValues)) {
        const row = page.locator('tr').filter({ hasText: code });
        const inputs = row.locator('input');
        for (let index = 0; index < values.length; index++) await inputs.nth(index).fill(values[index]);
        await row.locator('select').selectOption('mapped');
    }
    await page.getByRole('button', { name: 'Save tax mappings' }).click();
    await expect(page.getByText('Tax mappings saved.')).toBeVisible();
    const status = await page.evaluate(async () => (await fetch('/inventory/api/v1/integration/solabooks/status')).json());
    expect(status.data.mapping_completeness_pct).toBe(100);
    expect(status.data.tax_mapping_completeness_pct).toBe(100);
});
