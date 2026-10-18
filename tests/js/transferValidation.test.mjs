import test from 'node:test';import assert from 'node:assert/strict';import{transferValidation as validate}from'../../resources/js/solastock/services/transferValidation.mjs';
const t=(k,_fallback,params)=>k+(params?':'+params.line:'');const header={from_warehouse_id:1,to_warehouse_id:2};const line={item_id:1,quantity:'0.0002',lot_id:null,serial_ids:[]};const tracking={tracksLot:()=>false,tracksSerial:()=>false};
test('missing destination is explicit',()=>assert.equal(validate({...header,to_warehouse_id:null},[line],tracking,true,t).to_warehouse_id,'transfers.form.destinationRequired'));
test('same warehouse blocked',()=>assert.equal(validate({...header,to_warehouse_id:1},[line],tracking,true,t).to_warehouse_id,'transfers.form.warehousesDiffer'));
test('valid fractional stock quantity allowed',()=>assert.deepEqual(validate(header,[line],tracking,true,t),{}));
test('invalid selected row is never silently omitted',()=>assert('lines.1.quantity'in validate(header,[line,{...line,quantity:'0'}],tracking,false,t)));
test('blank row asks for item',()=>assert('lines.0.item_id'in validate(header,[{}],tracking,false,t)));
test('lot required only for posting',()=>{const tr={...tracking,tracksLot:()=>true};assert('lines.0.lot_id'in validate(header,[line],tr,true,t));assert.deepEqual(validate(header,[line],tr,false,t),{});});
test('serial required and multiple serials cannot be truncated',()=>{const tr={...tracking,tracksSerial:()=>true};assert('lines.0.serial_id'in validate(header,[line],tr,true,t));assert('lines.0.serial_id'in validate(header,[{...line,serial_ids:[1,2]}],tr,true,t));assert.deepEqual(validate(header,[{...line,quantity:'',serial_ids:[1]}],tr,true,t),{});});
