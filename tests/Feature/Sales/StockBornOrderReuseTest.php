<?php
namespace Tests\Feature\Sales;
use App\Models\User;
use App\Models\Tenant\{FulfillmentRequest,SalesDocumentOutbox,SalesOrder,Shipment,StockLedger};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Documents\{SalesOrderService,ShipmentService};
use App\Services\Sales\{FulfillmentRequestService,StockBornOrderReuse};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/** Native order/shipment/valuation, explicitly isolated remote authorization seam. */
final class StockBornOrderReuseTest extends TestCase
{
 use TenantAware, \Tests\Support\SalesHandoffFixture;
 private function fixture():array {
  $this->initializeSalesFixture(true);config(['inventory.demo_tenant.enabled'=>false]);$actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);$this->app->forgetInstance(InventoryPermissionService::class);
  $native=app(SalesOrderService::class);$order=$native->confirm($native->createDraft(['warehouse_id'=>$this->warehouse->id,'customer_id'=>$this->customer->id,'currency_code'=>'JOD'],[['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'ordered_qty'=>'4','unit_price'=>'7']]));$source=$order->lines->sole();
  $shipping=app(ShipmentService::class);$shipment=$shipping->post($shipping->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'shipment_number'=>'QA-SOURCE-'.Str::uuid()],[['sales_order_line_id'=>$source->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'2']]));
  $event=SalesDocumentOutbox::where('event_type','sales.shipment.confirmed')->sole();$data=$this->data();$data['lines'][0]['quantity']='2';$data['lines'][0]['original_sales_order_line_id']=$source->id;
  $data['origin_order']=['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'source_shipment_refs'=>[['id'=>$shipment->id,'mapping_uuid'=>data_get($event->payload,'shipment.mapping_uuid'),'event_uuid'=>$event->event_uuid,'payload_hash'=>$event->payload_hash]]];$this->authority($data);
  app(FulfillmentRequestService::class)->upsert($data,323);return[$order,$source,$shipment,$data];
 }
 public function test_native_original_order_is_reused_and_old_shipments_are_not_counted_again():void {
  [$order,$source,$first]=$this->fixture();$service=app(FulfillmentRequestService::class);$before=StockLedger::count();$approved=$service->approve(FulfillmentRequest::sole(),$this->warehouse->id);
  $this->assertSame($order->id,$approved['sales_order_id']);$this->assertSame(1,SalesOrder::count());$this->assertSame($before,StockLedger::count());$this->assertSame([],$approved['shipment_ids']);
  $line=FulfillmentRequest::sole()->lines()->sole();$this->assertSame(0,bccomp('2',(string)$line->source_shipped_qty_base,4));$this->assertSame($source->id,$line->sales_order_line_id);
  $shipping=app(ShipmentService::class);$second=$shipping->post($shipping->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'shipment_number'=>'QA-FINAL-'.Str::uuid()],[['sales_order_line_id'=>$source->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'2']]));
  $status=$service->status(FulfillmentRequest::sole());$this->assertSame('complete',$status['status']);$this->assertSame(0,bccomp('2',$status['fulfilled_quantity'],8));$this->assertSame([$second->id],$status['shipment_ids']);$this->assertSame(2,Shipment::count());$this->assertSame(1,SalesOrder::count());
  $count=StockLedger::count();$shipping->post($second);$this->assertSame($count,StockLedger::count());$this->assertSame(0,bccomp('4',(string)$source->fresh()->shipped_qty,4));
 }
 public function test_unverified_source_event_cannot_adopt_order_or_change_stock():void {
  [$order,,,$data]=$this->fixture();$request=FulfillmentRequest::sole();$payload=$request->source_payload;$payload['origin_order']['source_shipment_refs'][0]['payload_hash']=str_repeat('0',64);$request->update(['source_payload'=>$payload]);$before=StockLedger::count();
  try{DB::connection('tenant')->transaction(fn()=>app(StockBornOrderReuse::class)->lockedOrder($request,$this->warehouse->id));$this->fail('Forged source accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$this->assertSame(409,$error->getStatusCode());}
  $this->assertNull($request->fresh()->sales_order_id);$this->assertSame(1,SalesOrder::count());$this->assertSame($before,StockLedger::count());
 }
}
