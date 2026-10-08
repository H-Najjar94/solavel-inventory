import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
function boundary() {
    const calls=[];
    const source=readFileSync(new URL('../../resources/js/solastock/services/api.js',import.meta.url),'utf8')
        .replace(/^import .*;$/gm,'').replace('export const api =','globalThis.api =');
    const context={URL,AbortController,FormData,File:class {},setTimeout,clearTimeout,
        document:{querySelector:()=>({getAttribute:()=> 'synthetic-csrf'})},
        window:{SOLASTOCK_BASE_PATH:'/inventory',SOLASTOCK_LOCALE:{locale:'ar'},location:{origin:'https://stock.example.invalid'}},
        feedback:{run:(_key,execute)=>execute(),failure:()=>{}},feedbackText:key=>key,statusRoute:()=>null,
        fetch:async(url,options)=>{calls.push({url,options});return {ok:true,status:200,json:async()=>({success:true,data:{id:7}})};}};
    vm.createContext(context);vm.runInContext(source,context);
    return {api:context.api,calls};
}
test('native shipment posting preserves explicit traceability override choices in actual request',async()=>{
    const {api,calls}=boundary();
    await api.postShipment(7,{allow_expired_lot:true,allow_quarantined_lot:false});
    assert.equal(calls.length,1);assert.equal(calls[0].url,'https://stock.example.invalid/inventory/api/v1/shipments/7/post');
    assert.equal(calls[0].options.method,'POST');
    assert.deepEqual(JSON.parse(calls[0].options.body),{allow_expired_lot:true,allow_quarantined_lot:false});
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'],'synthetic-csrf');
    assert.equal(calls[0].options.headers['Accept-Language'],'ar');
    assert.equal(calls[0].options.credentials,'same-origin');
});
test('normal native shipment posting does not fabricate an override',async()=>{
    const {api,calls}=boundary();await api.postShipment(7);
    assert.deepEqual(JSON.parse(calls[0].options.body),{});
});
