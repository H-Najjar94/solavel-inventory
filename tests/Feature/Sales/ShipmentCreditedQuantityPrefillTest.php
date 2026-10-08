<?php
namespace Tests\Feature\Sales;
use App\Models\Tenant\{Unit,UnitConversion,Item,Warehouse,SalesOrder,StockLedger};
use App\Services\Documents\ShipmentService;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/** Native persisted source projection only; actual financial-credit transport is qualified separately. */
final class ShipmentCreditedQuantityPrefillTest extends TestCase
{
 use TenantAware;
 private function order(string $ordered,string $shipped,string $cancelled,string $factor='1'):SalesOrder {
  $this->useTenantA();
  $unit=Unit::create(['code'=>'PREFILL-EACH','name'=>'Prefill each','kind'=>'count','is_active'=>true]);
  $item=Item::create(['sku'=>'PREFILL-CREDIT','name'=>'Quantity projection','item_type'=>'inventory','tracking_type'=>'none','base_unit_id'=>$unit->id,'is_active'=>true]);
  $warehouse=Warehouse::create(['code'=>'PREFILL-WH','name'=>'Projection warehouse','type'=>'warehouse','is_active'=>true]);
  $order=SalesOrder::create(['order_number'=>'PREFILL-QA','order_date'=>'2026-10-08','warehouse_id'=>$warehouse->id,'status'=>'confirmed']);
  $entered=$unit;
  if(bccomp($factor,'1',8)!==0){
   $entered=Unit::create(['code'=>'PREFILL-BOX','name'=>'Prefill box','kind'=>'count','is_active'=>true]);
   UnitConversion::create(['item_id'=>$item->id,'from_unit_id'=>$entered->id,'to_unit_id'=>$unit->id,'factor'=>$factor]);
  }
  $normalized=app(\App\Services\Catalog\UnitConversionResolver::class)->normalizeLine(['item_id'=>$item->id,
   'entered_unit_id'=>$entered->id,'entered_qty'=>bcdiv($ordered,$factor,4)],'ordered_qty');
  $order->lines()->create(['organization_id'=>$order->organization_id,'shipped_qty'=>$shipped,'cancelled_qty'=>$cancelled,'unit_price'=>'1']+$normalized);
  return $order;
 }
 public function test_partial_credited_quantity_is_excluded_and_converted_without_movements():void {
  $order=$this->order('10','3','2','2');$before=StockLedger::count();$lines=app(ShipmentService::class)->fromSalesOrder($order);
  $this->assertCount(1,$lines);$this->assertSame('2.5000',$lines[0]['quantity']);
  $this->assertSame($before,StockLedger::count());$this->assertSame('2.0000',(string)$order->lines()->sole()->cancelled_qty);
 }
 public function test_fully_dispatched_or_credited_source_has_no_prefilled_dispatch():void {
  $order=$this->order('10','6','4');$this->assertSame([],app(ShipmentService::class)->fromSalesOrder($order));
 }
 public function test_ordinary_uncancelled_source_prefill_remains_correct():void {
  $order=$this->order('10','3','0');$lines=app(ShipmentService::class)->fromSalesOrder($order);$this->assertSame('7.0000',$lines[0]['quantity']);
 }
}
