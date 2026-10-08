import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Exercise the production bundle against a local, deterministic API contract.
// Backend currency, FX, stock and isolation assertions live in Phase3WorkflowContractTest.
for (const locale of ['en', 'ar']) {
    test(`GRN ${locale}: localized FX error highlights date and retry reuses the draft`, async ({ page }) => {
        const calls = { create: 0, update: 0, post: 0 };
        const message = locale === 'ar' ? 'لا يوجد سعر صرف صالح في المالية للعملة USD بتاريخ 2026-10-06.' : 'No valid Finance exchange rate is available for USD on 2026-10-06.';
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        const entry = manifest['resources/js/solastock/app.jsx'];
        const po = { id: 1, po_number: 'PO-FX', warehouse_id: 1, supplier_id: null };
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (!['localhost', '127.0.0.1'].includes(url.hostname)) return route.abort();
            if (url.pathname.includes('/build/')) {
                const asset = path.join('public', url.pathname.replace('/inventory/', ''));
                return route.fulfill({ body: fs.readFileSync(asset), contentType: asset.endsWith('.js') ? 'text/javascript' : 'text/css' });
            }
            if (url.pathname.includes('/api/v1/')) {
                let data = [];
                const endpoint = url.pathname.split('/api/v1')[1];
                if (endpoint === '/tenant/status') data = { mode: 'live', state: 'live_ready', data_state: 'real', can_access: true, authenticated: true, organization_id: 1 };
                else if (endpoint === '/meta') data = { permissions: ['inventory.receive_goods', 'inventory.manage_adjustments', 'inventory.view_stock'], settings: {}, lookups: { units: [] } };
                else if (endpoint === '/tenant/organizations') data = [{ id: 1, name: 'GRN regression' }];
                else if (endpoint === '/purchase-orders/1/grn-draft') data = { purchase_order: po, lines: [{ item_id: 1, purchase_order_line_id: 1, received_qty: '2', unit_cost: '10' }] };
                else if (endpoint === '/purchase-orders/1') data = { purchase_order: po };
                else if (endpoint.startsWith('/items')) data = [{ id: 1, name: 'Test item', sku: 'GRN', tracking_type: 'none' }];
                else if (endpoint.startsWith('/warehouses')) data = [{ id: 1, name: 'Test warehouse', code: 'WH' }];
                else if (endpoint === '/goods-receipts' && route.request().method() === 'POST') { calls.create++; data = { id: 10 }; }
                else if (endpoint === '/goods-receipts/10' && route.request().method() === 'PUT') { calls.update++; data = { id: 10 }; }
                else if (endpoint === '/goods-receipts/10/post') {
                    calls.post++;
                    if (calls.post === 1) return route.fulfill({ status: 422, json: { success: false, error: { code: 'validation_failed', message, errors: { receipt_date: [message] } } } });
                    data = { id: 10, status: 'posted' };
                } else if (endpoint === '/goods-receipts/10') data = { grn: { id: 10, status: 'posted', grn_number: 'GRN-TEST', lines: [] }, ledger: [] };
                return route.fulfill({ json: { success: true, data } });
            }
            return route.fulfill({ contentType: 'text/html', body: `<html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}"><body><div id="solastock-root"></div><script>window.SOLASTOCK_LOCALE={locale:'${locale}'};window.SOLASTOCK_BASE_PATH='/inventory';</script><script type="module" src="/inventory/build/${entry.file}"></script>${(entry.css || []).map(css => `<link rel="stylesheet" href="/inventory/build/${css}">`).join('')}</body></html>` });
        });
        await page.goto('/inventory/goods-receipts/from-po/1');
        const trigger = page.locator('.doc-actions .btn--primary');
        await expect(trigger).toBeEnabled();
        const post = async () => {
            await trigger.click();
            const dialog = page.locator('dialog[open], .sf-inline');
            await expect(dialog).toBeVisible();
            await dialog.locator('button').last().click();
        };
        await post();
        await expect(page.locator('input[type=date]')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('.field-error')).toContainText(message);
        await expect(trigger).toBeEnabled();
        await page.locator('input[type=date]').fill('2026-10-07');
        await post();
        await expect(page).toHaveURL(/goods-receipts\/10$/);
        expect(calls).toEqual({ create: 1, update: 1, post: 2 });
    });
}
