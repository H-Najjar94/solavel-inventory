import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(120_000);
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const suffix = Date.now();

function password(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith(email));
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page, email = 'qa.owner@solavel.test') {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password(email));
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

test('owner mutates, persists, audits and restores every policy setting', async ({ page }) => {
    await login(page);
    const before = (await api(page, 'GET', '/settings')).body.data.settings;
    const changed = {
        costing: before.default_costing_method === 'fifo' ? 'average' : 'fifo',
        picking: before.picking_policy === 'manual' ? 'fifo' : 'manual',
        negative: !before.allow_negative_stock,
        tolerance: (Number(before.value_tolerance ?? 0.01) + 0.001).toFixed(4),
        expiry: Number(before.expiry_warning_days ?? 30) + 1,
    };
    await page.goto('/inventory/settings');
    await page.getByLabel('Default costing method').selectOption(changed.costing);
    await page.getByLabel('Picking policy').selectOption(changed.picking);
    if (changed.negative) await page.getByLabel('Allow when policy permits it').check();
    else await page.getByLabel('Allow when policy permits it').uncheck();
    await page.getByLabel('Value reconciliation tolerance').fill(changed.tolerance);
    await page.getByLabel('Expiry warning days').fill(String(changed.expiry));
    await page.getByLabel('Expiry warning days').blur();
    await page.waitForTimeout(50);
    await page.getByRole('button', { name: 'Save policy' }).click();
    await expect(page.getByText('Settings saved.').last()).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Default costing method')).toHaveValue(changed.costing);
    await expect(page.getByLabel('Picking policy')).toHaveValue(changed.picking);
    await expect(page.getByLabel('Expiry warning days')).toHaveValue(String(changed.expiry));
    const persisted = (await api(page, 'GET', '/settings')).body.data.settings;
    expect(persisted.default_costing_method).toBe(changed.costing);
    expect(persisted.picking_policy).toBe(changed.picking);
    expect(persisted.allow_negative_stock).toBe(changed.negative);
    const audit = await api(page, 'GET', '/audit-logs?entity_type=inventory_settings&per_page=100');
    expect((audit.body.data?.data ?? audit.body.data ?? []).some((row) => row.action === 'inventory.settings.updated')).toBeTruthy();

    await page.context().clearCookies();
    await login(page);
    await page.goto('/inventory/settings');
    await expect(page.getByLabel('Default costing method')).toHaveValue(changed.costing);
    await page.getByLabel('Default costing method').selectOption(before.default_costing_method ?? 'average');
    await page.getByLabel('Picking policy').selectOption(before.picking_policy ?? 'manual');
    if (before.allow_negative_stock) await page.getByLabel('Allow when policy permits it').check();
    else await page.getByLabel('Allow when policy permits it').uncheck();
    await page.getByLabel('Value reconciliation tolerance').fill(String(before.value_tolerance ?? '0.0100'));
    await page.getByLabel('Expiry warning days').fill(String(before.expiry_warning_days ?? 30));
    await page.getByLabel('Expiry warning days').blur();
    await page.waitForTimeout(50);
    await page.getByRole('button', { name: 'Save policy' }).click();
    await expect(page.getByText('Settings saved.').last()).toBeVisible();
    const restored = (await api(page, 'GET', '/settings')).body.data.settings;
    expect(restored.default_costing_method).toBe(before.default_costing_method);
    expect(restored.picking_policy).toBe(before.picking_policy);
    expect(restored.allow_negative_stock).toBe(before.allow_negative_stock);
    expect(Number(restored.expiry_warning_days)).toBe(Number(before.expiry_warning_days));
    expect((await api(page, 'PUT', '/settings', { expiry_warning_days: -1 })).status).toBe(422);
});

test('owner exercises all exposed master-data administration controls and scopes remain protected', async ({ browser }) => {
    const ownerContext = await browser.newContext();
    const managerContext = await browser.newContext();
    const viewerContext = await browser.newContext();
    const page = await ownerContext.newPage();
    const manager = await managerContext.newPage();
    const viewer = await viewerContext.newPage();
    await Promise.all([login(page), login(manager, 'qa.manager@solavel.test'), login(viewer, 'qa.viewer@solavel.test')]);
    await page.goto('/inventory/settings');

    const categoryName = `QA-SOLASTOCK-PHASE13-20260720-CATEGORY-${suffix}`;
    const categoryCard = page.locator('.card').filter({ hasText: 'Category hierarchy' });
    await categoryCard.getByLabel('Name').fill(categoryName);
    await categoryCard.getByRole('button', { name: 'Add category' }).click();
    await expect(page.getByText('Category saved.')).toBeVisible();
    const categorySettings = (await api(page, 'GET', '/settings')).body.data;
    const category = categorySettings.categories.find((row) => row.name === categoryName);
    expect((await api(page, 'PUT', `/settings/categories/${category.id}`, { name: categoryName, is_active: false })).status).toBe(200);

    const brandName = `QA-SOLASTOCK-PHASE13-20260720-BRAND-${suffix}`;
    const brandCard = page.locator('.card').filter({ hasText: 'Brand' });
    await brandCard.getByLabel('Name').fill(brandName);
    await brandCard.getByRole('button', { name: 'Add brand' }).click();
    await expect(page.getByText('Brand saved.')).toBeVisible();
    const brandSettings = (await api(page, 'GET', '/settings')).body.data;
    const brand = brandSettings.brands.find((row) => row.name === brandName);
    expect((await api(page, 'PUT', `/settings/brands/${brand.id}`, { name: brandName, is_active: false })).status).toBe(200);

    const conversion = page.locator('.card').filter({ hasText: 'Unit conversion' });
    const fromOptions = await conversion.getByLabel('From unit').locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
    if (fromOptions.length >= 2) {
        await conversion.getByLabel('From unit').selectOption(fromOptions[0]);
        await conversion.getByLabel('To unit').selectOption(fromOptions[1]);
        await conversion.getByLabel('Factor').fill('2');
        await conversion.getByRole('button', { name: 'Save conversion' }).click();
        await expect(page.getByText('Unit conversion saved.')).toBeVisible();
    }

    const reorder = page.locator('.panel').filter({ hasText: 'Reorder Planning' });
    await reorder.getByLabel('Item').selectOption({ index: 1 });
    await reorder.locator('label').filter({ hasText: /^\s*Warehouse/ }).locator('select').selectOption('1');
    await reorder.getByLabel('Reorder point').fill('3');
    await reorder.getByLabel('Reorder quantity').fill('5');
    await reorder.getByLabel('Minimum stock').fill('1');
    await reorder.getByLabel('Maximum stock').fill('20');
    await reorder.getByLabel('Safety stock').fill('2');
    await reorder.getByRole('button', { name: 'Calculate safety stock' }).click();
    await expect(page.getByText('Safety stock calculated.')).toBeVisible();
    await reorder.getByRole('button', { name: 'Save reorder rule' }).click();
    await expect(page.getByText('Warehouse reorder rule saved.')).toBeVisible();

    const reasons = page.locator('.panel').filter({ hasText: 'Adjustment Reasons' });
    await reasons.getByLabel('Code').fill(`p13_${suffix}`);
    await reasons.getByLabel('Label').fill('Phase 13 verification');
    await reasons.getByRole('button', { name: 'Save reason' }).click();
    await expect(page.getByText('Adjustment reason code saved.')).toBeVisible();

    const currency = page.locator('.panel').filter({ hasText: 'Currency Rates' });
    await currency.getByLabel('Currency').fill('P13');
    await currency.getByLabel('Rate to base').fill('1.25');
    await currency.getByRole('button', { name: 'Save rate' }).click();
    await expect(page.getByText('Currency rate saved.')).toBeVisible();

    const customRoles = page.locator('.panel').filter({ hasText: 'Custom Roles' });
    await customRoles.getByLabel('Role name').fill(`Phase 13 Audit ${suffix}`);
    await customRoles.getByLabel('Role key').fill(`phase13_audit_${suffix}`);
    await customRoles.locator('.card input[type="checkbox"]').first().check();
    await customRoles.getByRole('button', { name: 'Save role' }).click();
    await expect(page.getByText('Custom role saved.')).toBeVisible();

    expect((await api(manager, 'PUT', '/settings', { picking_policy: 'manual' })).status).toBe(403);
    expect((await api(viewer, 'PUT', '/settings', { picking_policy: 'manual' })).status).toBe(403);
    expect((await api(viewer, 'GET', '/settings')).status).toBe(200);
    const integration = (await api(page, 'GET', '/integration/solabooks/status')).body.data;
    expect(integration.mode).toBe('active');
    expect(integration.delivery_configured).toBeTruthy();
    await Promise.all([ownerContext.close(), managerContext.close(), viewerContext.close()]);
});
