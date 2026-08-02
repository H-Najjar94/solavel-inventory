import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.use({ trace: 'off', screenshot: 'off', video: 'off' });
const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const evidenceDir = '/tmp/solastock-practical-verification-20260719/17-phase13-final';

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

async function getJson(page, path) {
    const response = await page.request.get(`/inventory/api/v1${path}`);
    expect(response.ok(), path).toBeTruthy();
    return response.json();
}

async function getAllEvents(page, status = null) {
    const rows = [];
    for (let current = 1; ; current += 1) {
        const statusQuery = status ? `status=${status}&` : '';
        const payload = await getJson(page, `/integration/solabooks/events?${statusQuery}per_page=100&page=${current}`);
        rows.push(...(payload.data?.data ?? payload.data ?? []));
        if (current >= Number(payload.meta?.last_page ?? 1)) return rows;
    }
}

test('exact outbox totals and the 16 percent tax mapping are unambiguous', async ({ page }) => {
    await login(page);
    const allEvents = await getAllEvents(page);
    const grouped = Object.groupBy(allEvents, (event) => event.status);
    const statuses = Object.fromEntries(['pending', 'processing', 'sent', 'failed', 'ignored']
        .map((status) => [status, grouped[status]?.length ?? 0]));
    expect(Object.values(statuses).reduce((sum, count) => sum + count, 0)).toBe(allEvents.length);
    const statusEvents = Object.fromEntries(['pending', 'sent', 'failed', 'ignored']
        .map((status) => [status, grouped[status] ?? []]));
    const byType = Object.fromEntries(Object.entries(statusEvents).map(([status, events]) => [status,
        Object.fromEntries(Object.entries(Object.groupBy(events, (event) => event.event_type))
            .map(([type, typed]) => [type, { count: typed.length, ids: typed.map((event) => event.id) }]))]));

    const settings = await getJson(page, '/settings');
    const standard = settings.data.settings.taxes.find((tax) => tax.code === 'QA12-STD16');
    expect(standard).toMatchObject({ treatment: 'standard', active: true, purchase: true, sales: true });
    expect(Number(standard.rate)).toBe(16);

    const mappings = await getJson(page, '/integration/solabooks/mappings/taxes');
    const rows = mappings.data?.mappings ?? [];
    const mapping = rows.find((row) => row.tax_code === 'QA12-STD16');
    expect(mapping).toBeTruthy();
    expect(mapping.solabooks_tax_code).toBe('VAT_STD_B');
    expect(String(mapping.input_tax_account_id)).toBe('647');
    expect(String(mapping.output_tax_account_id)).toBe('648');

    const historical = {};
    for (const id of [178, 181, 188, 200, 212]) {
        const payload = await getJson(page, `/integration/solabooks/events/${id}`);
        historical[id] = { event_type: payload.data.event_type, status: payload.data.status };
        expect(payload.data.status).toBe('ignored');
    }

    fs.mkdirSync(evidenceDir, { recursive: true });
    fs.writeFileSync(`${evidenceDir}/state-reconciliation.json`, `${JSON.stringify({
        captured_at: new Date().toISOString(),
        outbox: { statuses, total: allEvents.length, by_type: byType },
        phase12_manual_reclassifications: historical,
        phase12_tax: {
            name: standard.name,
            code: standard.code,
            treatment: standard.treatment,
            rate_percent: Number(standard.rate),
            purchase: standard.purchase,
            sales: standard.sales,
            finance_tax_code: mapping.solabooks_tax_code,
            input_vat_account_id: String(mapping.input_tax_account_id),
            output_vat_account_id: String(mapping.output_tax_account_id),
        },
        migration_2026_07_20_132000: 'status-only update from pending/failed to ignored for six non-accounting event types; no rows archived or deleted',
    }, null, 2)}\n`);
});
