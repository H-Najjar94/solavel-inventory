import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
    canonicalDecision,
    createSerializedMutationQueue,
    mayAcceptCanonicalDraft,
    unwrapCanonicalDraft,
} from '../../resources/js/solastock/services/wizardDraftSync.js';

const identity = { client_id: 2, central_organization_id: 3, finance_organization_id: 1, solastock_organization_id: 3 };
const draft = (lock, decisions = []) => ({ run_uuid: 'draft-a', identity, lock_version: lock, decisions });
const decision = (fingerprint, action, version = 1) => ({ candidate_fingerprint: fingerprint, action, decision_version: version });

test('connection settings imports its localized source text helper', async () => {
    const source = await readFile(new URL('../../resources/js/solastock/pages/IntegrationSettingsPage.jsx', import.meta.url), 'utf8');
    assert.match(source, /import\s*\{\s*sourceText\s*\}\s*from\s*['"]\.\.\/i18n\/locales\.js['"]/);
});

test('wizard state failures are translated instead of exposing internal codes', async () => {
    const source = await readFile(new URL('../../resources/js/solastock/services/api.js', import.meta.url), 'utf8');
    assert.match(source, /wizard_run_not_editable/);
    assert.match(source, /connection setup has moved to another step/);
    assert.match(source, /finance_source_sku_required_for_creation/);
    assert.match(source, /item code will be created automatically/);
});

test('canonical response is unwrapped and retains the confirmed decision', () => {
    const confirmed = unwrapCanonicalDraft({ success: true, data: draft(2, [decision('a', 'approve_exact_binding')]) });
    assert.equal(canonicalDecision(confirmed, 'a').action, 'approve_exact_binding');
});

test('status polling and stale GET cannot remove a newer confirmed decision', () => {
    const current = draft(2, [decision('a', 'approve_exact_binding')]);
    assert.equal(mayAcceptCanonicalDraft(current, draft(1), 'draft-a'), false);
    assert.equal(mayAcceptCanonicalDraft(current, draft(2), 'draft-a'), false);
    assert.equal(mayAcceptCanonicalDraft(current, draft(3, [decision('a', 'approve_exact_binding')]), 'draft-a'), true);
});

test('another draft or organization can never overwrite local state', () => {
    assert.equal(mayAcceptCanonicalDraft(draft(2), { ...draft(3), run_uuid: 'draft-b' }, 'draft-a'), false);
    assert.equal(mayAcceptCanonicalDraft(draft(2), { ...draft(3), identity: { ...identity, central_organization_id: 4 } }, 'draft-a'), false);
});

test('autosaves are serialized and rapid decisions on different records are preserved', async () => {
    const queue = createSerializedMutationQueue();
    const order = [];
    let resolveFirst;
    const first = queue.enqueue(async () => {
        order.push('first-start');
        await new Promise((resolve) => { resolveFirst = resolve; });
        order.push('first-end');
        return draft(2, [decision('a', 'approve_exact_binding')]);
    });
    const second = queue.enqueue(async () => {
        order.push('second-start');
        return draft(3, [decision('a', 'approve_exact_binding'), decision('b', 'create_solastock_record')]);
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(order, ['first-start']);
    resolveFirst();
    await Promise.all([first, second]);
    assert.deepEqual(order, ['first-start', 'first-end', 'second-start']);
    assert.equal(canonicalDecision(await second, 'b').action, 'create_solastock_record');
});

test('failed queued save does not prevent accessible retry', async () => {
    const queue = createSerializedMutationQueue();
    await assert.rejects(queue.enqueue(async () => { throw new Error('validation'); }));
    const retried = await queue.enqueue(async () => draft(2, [decision('a', 'approve_exact_binding')]));
    assert.equal(canonicalDecision(retried, 'a').action, 'approve_exact_binding');
});
