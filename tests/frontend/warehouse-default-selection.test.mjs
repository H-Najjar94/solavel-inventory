import test from 'node:test';import assert from 'node:assert/strict';
import {eligibleDefaultWarehouse as choose,defaultWarehouseDecision as decide} from '../../resources/js/solastock/components/warehouseDefaultSelection.mjs';
const list=[{id:1,is_active:true,is_default:true},{id:2,is_active:true,is_default:false}];
const base={enabled:true,disabled:false,loaded:true,value:null,attempted:false,list};
test('native active default selected only from authorized list',()=>{assert.equal(choose(list),1);assert.equal(choose(list,9),null);assert.equal(choose([]),null);assert.equal(choose([{id:1,is_active:false,is_default:true}]),null);});
test('does not infer first warehouse or choose ambiguous defaults',()=>{assert.equal(choose([{id:1,is_active:true}]),null);assert.equal(choose([...list,{id:3,is_default:true}]),null);});
test('opt in only; disabled, loading and existing explicit source selection preserved',()=>{for(const patch of [{enabled:false},{disabled:true},{loaded:false},{value:2}])assert.equal(decide({...base,...patch}).selected,null);assert.equal(decide(base).selected,1);});
test('manual choice or clear persists through refresh after one attempt',()=>{const first=decide(base);assert.equal(first.attempted,true);assert.equal(decide({...base,attempted:first.attempted,value:null}).selected,null);assert.equal(decide({...base,attempted:true,value:2}).selected,null);});
test('new context may choose its own native default without carrying old choice',()=>{assert.equal(decide({...base,list:[{id:7,is_default:true,is_active:1}]}).selected,7);assert.equal(decide({...base,list:[]}).selected,null);});
