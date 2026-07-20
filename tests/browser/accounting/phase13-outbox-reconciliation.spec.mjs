import { test, expect } from '@playwright/test';
import fs from 'node:fs';

test.setTimeout(300_000);
test.use({ trace: 'off', screenshot: 'off', video: 'off' });
const credentialsFile = '/home/hnajjar/.solavel-qa-credentials';
const evidenceFile = '/tmp/solastock-practical-verification-20260719/17-phase13-final/outbox-delivery-reconciliation.json';
const stateEvidenceFile = '/tmp/solastock-practical-verification-20260719/17-phase13-final/state-reconciliation.json';

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
    const rows = [];
    for (let current = 1; ; current += 1) {
        const response = await api(page, 'GET', '/integration/solabooks/events?status=' + status + '&per_page=100&page=' + current);
        expect(response.status).toBe(200);
        rows.push(...(response.body.data?.data ?? response.body.data ?? []));
        if (current >= Number(response.body.meta?.last_page ?? 1)) return rows;
    }
}

test('audited accounting backlog delivers once and replay remains idempotent', async ({ page }) => {
    await login(page);
    const pending = await allEvents(page, 'pending');
    const startingFailed = await allEvents(page, 'failed');
    const candidates = [...pending, ...startingFailed].filter((event, index, rows) => rows.findIndex((row) => row.id === event.id) === index);
    const accountingTypes = new Set(['opening_stock.posted', 'opening_stock.reversed', 'adjustment.posted', 'adjustment.reversed', 'grn.posted', 'stock_count.posted', 'shipment.posted', 'sales_return.posted']);
    for (const event of candidates) {
        expect(accountingTypes.has(event.event_type), 'unexpected pending type ' + event.event_type).toBeTruthy();
        expect(event.mapping_status, 'event ' + event.id).toBe('complete');
        if (['adjustment.posted', 'stock_count.posted'].includes(event.event_type)) {
            const detail = await api(page, 'GET', '/integration/solabooks/events/' + event.id);
            expect(detail.status).toBe(200);
            expect(Math.abs(Number(detail.body.data.payload.total_inventory_value_change)), 'zero event ' + event.id).toBeGreaterThan(0);
        }
    }

    const delivered = [];
    const failures = [];
    for (const event of candidates) {
        const result = await api(page, 'POST', '/integration/solabooks/events/' + event.id + '/retry');
        if (result.status !== 200) {
            failures.push({
                id: event.id,
                event_type: event.event_type,
                status: result.status,
                error: String(result.body?.message ?? result.body?.error?.message ?? result.body?.error ?? 'delivery failed').slice(0, 240),
            });
            continue;
        }
        expect(result.body.data.status, 'event ' + event.id).toBe('sent');
        expect(result.body.data.external_document_id, 'event ' + event.id).toBeTruthy();
        delivered.push({
            id: event.id,
            event_type: event.event_type,
            aggregate_id: event.aggregate_id,
            aggregate_number: event.aggregate_number,
            external_document_id: result.body.data.external_document_id,
        });
    }

    for (const event of Object.values(Object.groupBy(delivered, (row) => row.event_type)).map((rows) => rows[0])) {
        const replay = await api(page, 'POST', '/integration/solabooks/events/' + event.id + '/retry');
        expect(replay.status).toBe(200);
        expect(replay.body.data.external_document_id).toBe(event.external_document_id);
    }

    const endingPending = await allEvents(page, 'pending');
    const endingFailed = await allEvents(page, 'failed');
    const boundary = JSON.parse(fs.readFileSync(stateEvidenceFile, 'utf8'));
    const boundaryAt = new Date(boundary.captured_at).getTime();
    const deliveredSinceBoundary = (await allEvents(page, 'sent'))
        .filter((event) => event.sent_at && new Date(event.sent_at).getTime() >= boundaryAt)
        .map((event) => ({
            id: event.id,
            event_type: event.event_type,
            aggregate_id: event.aggregate_id,
            aggregate_number: event.aggregate_number,
            external_document_id: event.external_document_id,
        }));
    const boundaryReplayTypes = [];
    for (const event of Object.values(Object.groupBy(deliveredSinceBoundary, (row) => row.event_type)).map((rows) => rows[0])) {
        const replay = await api(page, 'POST', '/integration/solabooks/events/' + event.id + '/retry');
        expect(replay.status).toBe(200);
        expect(replay.body.data.external_document_id).toBe(event.external_document_id);
        boundaryReplayTypes.push(event.event_type);
    }
    fs.writeFileSync(evidenceFile, JSON.stringify({
        captured_at: new Date().toISOString(),
        starting_pending: pending.length,
        starting_failed: startingFailed.length,
        delivered_count: delivered.length,
        delivered,
        boundary_captured_at: boundary.captured_at,
        boundary_pending: boundary.outbox.statuses.pending,
        zero_value_reclassified: boundary.outbox.by_type.pending['stock_count.posted']?.count ?? 0,
        delivered_since_boundary_count: deliveredSinceBoundary.length,
        delivered_since_boundary: deliveredSinceBoundary,
        failures,
        ending_pending: endingPending.length,
        ending_failed: endingFailed.length,
        replayed_types: boundaryReplayTypes,
    }, null, 2) + '\n');
    expect(endingPending).toHaveLength(0);
    expect(endingFailed).toHaveLength(0);
    expect(failures, 'accounting delivery failures').toEqual([]);
});
