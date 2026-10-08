import test from 'node:test';
import assert from 'node:assert/strict';
import {notificationPanelPosition} from '../../resources/js/solastock/components/notificationPanelPosition.mjs';
test('actual Arabic mobile bell stays fully within the 390px viewport',()=>{
 const p=notificationPanelPosition({left:191.65625,right:233.65625,bottom:51},{width:390,height:900},true);
 assert.equal(p.width,340);assert.equal(p.left,36);assert(p.left+p.width<=376);assert(p.top+p.maxHeight<=886);
});
test('both directions clamp anchors near either edge and preserve desktop width',()=>{
 for(const rtl of [true,false])for(const left of [0,190,370]){
  const p=notificationPanelPosition({left,right:left+42,bottom:51},{width:390,height:900},rtl);
  assert(p.left>=14);assert(p.left+p.width<=376);
 }
 const p=notificationPanelPosition({left:1000,right:1042,bottom:51},{width:1440,height:900},false);
 assert.equal(p.left,702);assert.equal(p.width,340);
});
test('resize and low viewport leave usable panel inside the viewport',()=>{
 const p=notificationPanelPosition({left:190,right:232,bottom:51},{width:320,height:300},true);
 assert.equal(p.width,292);assert.equal(p.left,14);assert(p.top+p.maxHeight<=286);
});
