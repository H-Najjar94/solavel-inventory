import test from 'node:test';
import assert from 'node:assert/strict';
import { decisionFieldErrors, actionableDecisionError } from '../../resources/js/solastock/services/decisionErrors.js';
test('localized field messages retain exact fingerprint and omit unrelated keys', () => {
    for (const message of ['Choose the specific record.', 'اختر السجل المحدد.']) {
        assert.deepEqual(decisionFieldErrors({payload:{errors:{'decisions.abc':[message], cutoff_at:['invalid'], 'decisions.':[], 'decisions.empty':null}}}), {abc:message});
    }
});
test('machine codes and missing errors cannot become visible field messages', () => {
    assert.deepEqual(decisionFieldErrors({code:'mapping_decision_requires_exact_records'}), {});
    assert.deepEqual(decisionFieldErrors(null), {});
});
test('target exact actionable row across section and pagination', () => {
    const rows=Array.from({length:30},(_,i)=>({fingerprint:`row${i}`,solabooks_record_ids:[i+1]}));
    assert.deepEqual(actionableDecisionError({row28:'Choose record'}, [['units',[]],['items',rows]],25),{section:'items',fingerprint:'row28',page:2});
});
test('excluded diagnostic errors do not highlight a different record', () => {
    assert.equal(actionableDecisionError({diagnostic:'Choose record'},[['warehouses',[{fingerprint:'real',solastock_record_ids:[1]},{fingerprint:'diagnostic',solastock_record_ids:[],solabooks_record_ids:[]}]]],25),null);
});
