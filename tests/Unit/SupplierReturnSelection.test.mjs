import test from 'node:test';
import assert from 'node:assert/strict';
import {returnLines} from '../../resources/js/solastock/domain/supplierReturnSelection.mjs';
const rows=[{goods_receipt_line_id:11,source_stock_ledger_id:22,remaining_entered_quantity:'2.5000'},{goods_receipt_line_id:11,source_stock_ledger_id:23,remaining_entered_quantity:'1'}];
test('split lots keep exact physical provenance and only selected entered quantities',()=>assert.deepEqual(returnLines(rows,{22:'1.2500',23:'0'}),[{goods_receipt_line_id:11,source_stock_ledger_id:22,entered_qty:'1.2500'}]));
test('over-return, exponent and excessive precision cannot become draft lines',()=>{for(const value of ['2.6','1e0','1.00001','-1','NaN'])assert.throws(()=>returnLines(rows,{22:value}));});
test('unselected rows do not create physical return claims',()=>assert.deepEqual(returnLines(rows,{}),[]));
