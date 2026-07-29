import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { dictionaries } from '../../../resources/js/solastock/i18n/index.js';

const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';

function passwordFor(email) {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/)
        .find((value) => value.startsWith(email));
    if (!line) throw new Error(`Missing protected QA credential for ${email}`);
    return line.match(/password:\s*(\S+)/)[1];
}

async function login(page) {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(passwordFor('qa.owner@solavel.test'));
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);
}

const routes = [
    ['dashboard', 'dashboard.title'],
    ['items', 'items'],
    ['warehouses', 'warehouses'],
    ['balances', 'currentStock'],
    ['ledger', 'ledger.title'],
    ['adjustments', 'adjustments.title'],
    ['transfers', 'transfers.title'],
    ['counts', 'counts.title'],
    ['scanner', 'scanner.title'],
    ['purchase-orders', 'receiving.po.list.title'],
    ['goods-receipts', 'receiving.grn.list.title'],
    ['sales-orders', 'salesOrders.common.title'],
    ['pick-lists', 'fulfillment.picking.title'],
    ['packs', 'fulfillment.packing.title'],
    ['shipments', 'fulfillment.shipments.title'],
    ['sales-returns', 'returns.list.title'],
    ['traceability', 'traceabilityPages.hub.title'],
    ['recalls', 'recalls.common.title'],
    ['reports', 'reports.title'],
    ['settings', 'settings.title'],
];

async function verifyRoutes(page, locale) {
    const dir = locale === 'ar' ? 'rtl' : 'ltr';
    for (const [route, key] of routes) {
        await page.goto(`/inventory/${route}`);
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.locator('html')).toHaveAttribute('dir', dir);
        await expect(page.locator('h1').first()).toContainText(dictionaries[locale][key]);
        await expect(page.locator('body')).not.toContainText(/\b(?:common|document|reports|settings|items)\.[a-z][a-zA-Z0-9_.-]+\b/);
        await expect(page.locator('body')).not.toContainText('Error 500');
    }
}

test('production serves complete English and Arabic route localization with persistence', async ({ page, context }) => {
    test.setTimeout(180_000);
    await login(page);

    await page.goto('/inventory/dashboard?locale=ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('.topbar')).not.toContainText('Real data');
    await expect(page.locator('.topbar')).not.toContainText('Live tenant');

    const second = await context.newPage();
    await second.goto('/inventory/items');
    await expect(second.locator('html')).toHaveAttribute('lang', 'ar');
    await second.close();

    await verifyRoutes(page, 'ar');

    await page.goto('/inventory/dashboard?locale=en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await page.reload();
    await verifyRoutes(page, 'en');
});

test('Arabic shell and representative workflows remain usable at mobile and tablet widths', async ({ browser }) => {
    test.setTimeout(120_000);
    for (const viewport of [{ width: 390, height: 844 }, { width: 1024, height: 1366 }]) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        await login(page);
        await page.goto('/inventory/dashboard?locale=ar');
        for (const route of ['items', 'warehouses', 'transfers', 'reports']) {
            await page.goto(`/inventory/${route}`);
            await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
            await expect(page.locator('.app')).toBeVisible();
            await expect(page.locator('body')).not.toContainText('Error 500');
        }
        await context.close();
    }
});

test('forms, details, exports, SSO handoff and organization switching preserve localization', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page);
    await page.goto('/inventory/dashboard?locale=ar');

    const forms = [
        'items/new', 'warehouses/new', 'opening-stock/new', 'adjustments/new',
        'transfers/new', 'counts/new', 'purchase-orders/new', 'goods-receipts/new',
        'sales-orders/new', 'sales-returns/new', 'recalls/new',
    ];
    for (const route of forms) {
        await page.goto(`/inventory/${route}?locale=ar`);
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('body')).not.toContainText(/\b(?:common|document|validation|status)\.[a-z][a-zA-Z0-9_.-]+\b/);
    }

    const detailSources = [
        ['items', 'items'], ['warehouses', 'warehouses'], ['adjustments', 'adjustments'],
        ['transfers', 'transfers'], ['counts', 'counts'], ['purchase-orders', 'purchase-orders'],
        ['goods-receipts', 'goods-receipts'], ['sales-orders', 'sales-orders'],
        ['sales-returns', 'sales-returns'], ['traceability/lots', 'lots'],
        ['traceability/serials', 'serials'], ['recalls', 'recalls'],
    ];
    for (const [route, endpoint] of detailSources) {
        const response = await page.request.get(`/inventory/api/v1/${endpoint}?per_page=1`);
        expect(response.ok(), `${endpoint} list`).toBeTruthy();
        const json = await response.json();
        const payload = json.data?.data ?? json.data ?? [];
        const row = Array.isArray(payload) ? payload[0] : null;
        if (!row?.id) continue;
        await page.goto(`/inventory/${route}/${row.id}?locale=ar`);
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('body')).not.toContainText('Error 500');
    }

    const print = await page.request.get('/inventory/api/v1/reports/inventory-valuation/export?format=pdf', {
        headers: { 'X-Locale': 'ar' },
    });
    expect(print.ok()).toBeTruthy();
    const printBody = await print.text();
    expect(printBody).toContain('<html lang="ar" dir="rtl">');
    expect(printBody).toContain('Noto Sans Arabic');
    expect(printBody).toContain('تقييم المخزون');

    await page.goto('/sso/inventory?to=%2Finventory%2Fdashboard%3Flocale%3Dar');
    await expect(page).toHaveURL(/\/inventory\/dashboard\?locale=ar/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');

    const organizations = await page.request.get('/inventory/api/v1/tenant/organizations');
    if (organizations.ok()) {
        const organizationData = (await organizations.json()).data;
        const rows = organizationData.organizations ?? [];
        const current = rows.find((row) => Number(row.id) === Number(organizationData.current));
        const other = rows.find((row) => Number(row.id) !== Number(organizationData.current));
        if (current && other) {
            await page.request.post('/inventory/api/v1/tenant/select-org', { data: { organization_id: other.id } });
            await page.goto('/inventory/dashboard');
            await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
            await page.request.post('/inventory/api/v1/tenant/select-org', { data: { organization_id: current.id } });
        }
    }
});
