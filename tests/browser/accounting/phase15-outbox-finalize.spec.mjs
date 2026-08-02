import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(420_000);
test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });

const credentialsFile = process.env.SOLASTOCK_STAGING_CREDENTIALS;
if (!credentialsFile) throw new Error('SOLASTOCK_STAGING_CREDENTIALS is required; production browser credentials are prohibited.');
const evidenceFile = '/tmp/solastock-practical-verification-20260719/19-phase15-final/outbox-final.json';
const accountingTypes = new Set([
    'opening_stock.posted', 'opening_stock.reversed', 'adjustment.posted', 'adjustment.reversed',
    'grn.posted', 'grn.reversed', 'stock_count.posted', 'shipment.posted', 'sales_return.posted',
]);

function password() {
    const line = fs.readFileSync(credentialsFile, 'utf8').split(/\r?\n/).find((value) => value.startsWith('qa.owner@solavel.test'));
    return line.match(/password:\s*(\S+)/)[1];
}

async function api(page, method, path) {
    return page.evaluate(async ({ method, path }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/inventory/api/v1' + path, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        });
        return { status: response.status, body: await response.json().catch(() => null) };
    }, { method, path });
}

async function allEvents(page, status) {
    const result = [];
    for (let current = 1; ; current += 1) {
        const response = await api(page, 'GET', '/integration/solabooks/events?status=' + status + '&per_page=100&page=' + current);
        expect(response.status).toBe(200);
        result.push(...(response.body.data?.data ?? response.body.data ?? []));
        if (current >= Number(response.body.meta?.last_page ?? 1)) return result;
    }
}

test('all recoverable accounting events are signed, delivered once, and the outbox closes cleanly', async ({ page }) => {
    await page.goto('/inventory/login');
    await page.locator('input[type="email"]').fill('qa.owner@solavel.test');
    await page.locator('input[type="password"]').fill(password());
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/inventory\/dashboard/);

    const pending = await allEvents(page, 'pending');
    const failed = await allEvents(page, 'failed');
    const candidates = [...pending, ...failed]
        .filter((event, index, events) => events.findIndex((candidate) => candidate.id === event.id) === index)
        .sort((left, right) => Number(left.id) - Number(right.id));
    const delivered = [];
    for (const event of candidates) {
        expect(accountingTypes.has(event.event_type), 'unexpected recoverable event ' + event.event_type).toBeTruthy();
        expect(event.mapping_status, 'event ' + event.id).toBe('complete');
        const response = await api(page, 'POST', '/integration/solabooks/events/' + event.id + '/retry');
        expect(response.status, 'event ' + event.id + ': ' + JSON.stringify(response.body?.error ?? null)).toBe(200);
        expect(response.body.data.status).toBe('sent');
        expect(response.body.data.external_document_id).toBeTruthy();
        delivered.push({
            id: event.id,
            event_type: event.event_type,
            aggregate_id: event.aggregate_id,
            external_document_id: response.body.data.external_document_id,
        });
    }

    for (const sample of Object.values(Object.groupBy(delivered, (event) => event.event_type)).map((events) => events[0])) {
        const replay = await api(page, 'POST', '/integration/solabooks/events/' + sample.id + '/retry');
        expect(replay.status).toBe(200);
        expect(replay.body.data.external_document_id).toBe(sample.external_document_id);
    }

    const endingPending = await allEvents(page, 'pending');
    const endingFailed = await allEvents(page, 'failed');
    const ignored = await allEvents(page, 'ignored');
    const phase15Ignored = ignored.filter((event) => String(event.aggregate_number ?? '').startsWith('P15-'));
    expect(endingPending).toHaveLength(0);
    expect(endingFailed).toHaveLength(0);
    expect(phase15Ignored.every((event) => !accountingTypes.has(event.event_type))).toBeTruthy();

    fs.writeFileSync(evidenceFile, JSON.stringify({
        captured_at: new Date().toISOString(),
        starting_pending: pending.length,
        starting_failed: failed.length,
        delivered,
        replayed_event_types: [...new Set(delivered.map((event) => event.event_type))],
        phase15_ignored: phase15Ignored.map((event) => ({ id: event.id, event_type: event.event_type, status: event.status })),
        ending_pending: endingPending.length,
        ending_failed: endingFailed.length,
    }, null, 2) + '\n');
});
