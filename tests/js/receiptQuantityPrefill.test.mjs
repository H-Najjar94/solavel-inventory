import test from 'node:test';import assert from 'node:assert/strict';
import {normalizeQty} from '../../resources/js/solastock/utils/quantity.mjs';
test('prefill quantities never exceed 6 dp and trim padding',()=>{
  assert.equal(normalizeQty('10.000000000000000000'),'10');
  assert.equal(normalizeQty('10.000000'),'10');
  assert.equal(normalizeQty('6.666667'),'6.666667');
  assert.equal(normalizeQty('2.500000000000000000'),'2.5');
  assert.equal(normalizeQty('0.1234567'),'0.123457');
  assert.equal(normalizeQty(''),'');assert.equal(normalizeQty(null),'');
  for(const v of ['10.000000000000000000','3.333333333333333333','1.5'])assert.match(normalizeQty(v),/^\d+(\.\d{1,6})?$/);
});
