import test from'node:test';import assert from'node:assert/strict';import{routeAccess,pageRequest}from'../../resources/js/solastock/services/uiAccess.mjs';import{visibleNav}from'../../resources/js/solastock/router/nav.js';
const meta={ui_access:{routes:[{method:'GET',pattern:'/recalls',allowed:false,reason:'feature_not_in_plan'},{method:'GET',pattern:'/recalls/{recall}',allowed:false,reason:'permission_denied'},{method:'POST',pattern:'/suppliers',allowed:false},{method:'PUT',pattern:'/suppliers/{supplier}',allowed:false},{method:'GET',pattern:'/lots',allowed:false}]}};
test('plan-blocked recall hidden despite role permission',()=>assert(!visibleNav(['inventory.view_traceability'],meta).some(n=>n.key==='recalls')));
test('missing role hidden even when plan grants route',()=>assert(!visibleNav([],{ui_access:{routes:[]}}).some(n=>n.key==='recalls')));
test('direct links and details denied',()=>{assert.equal(routeAccess('/recalls',meta).allowed,false);assert.equal(routeAccess('/recalls/8',meta).reason,'permission_denied');});
test('creation and editing use mutation gates',()=>{assert.equal(routeAccess('/suppliers/new',meta).allowed,false);assert.equal(routeAccess('/suppliers/8/edit',meta).allowed,false);});
test('traceability routes resolve to their API paths',()=>assert.equal(routeAccess('/traceability/lots?expiring=1',meta).allowed,false));
test('allowed unrelated stock page remains accessible',()=>assert.equal(routeAccess('/balances',meta).allowed,true));
test('URI translation does not confuse create and read',()=>{assert.deepEqual(pageRequest('/transfers/new'),{method:'POST',path:'/transfers'});assert.deepEqual(pageRequest('/transfers/1/edit'),{method:'PUT',path:'/transfers/1'});});
