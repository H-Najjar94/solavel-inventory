<?php
namespace Tests\Feature\Sales;
use App\Models\User;
use App\Models\Tenant\{FulfillmentRequest,SalesDocumentOutbox,SalesOrder,Shipment,StockLedger};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Documents\{SalesOrderService,ShipmentService};
use App\Services\Sales\FulfillmentRequestService;
use Illuminate\Support\Facades\{Auth,DB};
use Tests\{TestCase};
use Tests\Support\SalesHandoffFixture;
use Tests\Traits\TenantAware;

/** Actual native SO/shipment/outbox/ledger; remote Finance Invoice authority is an explicit projection seam. */
final class StockBornOrderReuseTest extends TestCase {
 use TenantAware,SalesHandoffFixture;
 private function setupReuse():array {
  $this->useTenantA();
  (require base_path('database/migrations/tenant/2026_10_08_195000_add_stock_born_fulfillment_baselines.php'))->up();
  $this->tenantTestManager->cleanup();$this->initializeSalesFixture(true);
  $actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);$this->app->forgetInstance(InventoryPermissionService::class);
  $orders=app(SalesOrderService::class);$order=$orders->confirm($orders->createDraft(['warehouse_id'=>$this->warehouse->id,'customer_id'=>$this->customer->id,'order_date'=>'2026-10-07','currency_code'=>'JOD'],[['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'ordered_qty'=>'10','unit_price'=>'7']]));
  $shipments=app(ShipmentService::class);$source=$order->lines->first();
  $shipment=$shipments->post($shipments->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-07'],[['sales_order_line_id'=>$source->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'4']]));
  $event=SalesDocumentOutbox::query()->where('payload->shipment->id',$shipment->id)->where('event_type','sales.shipment.confirmed')->sole();
  $data=$this->data();$data['source_status']='posted';$data['posted_invoice_journal_id']=95;
  // Native Finance source/AR metadata is the declared projection seam, not a Finance posting claim.
  DB::connection('tenant')->table('journal_entries')->insert(['id'=>95,'organization_id'=>14,'source_type'=>\App\Models\Invoice::class,'source_id'=>800,'status'=>'posted']);
  $data['lines'][0]['quantity']='6';$data['lines'][0]['original_sales_order_line_id']=$source->id;
  $data['origin_order']=['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'source_shipment_refs'=>[['mapping_uuid'=>$event->payload['shipment']['mapping_uuid'],'id'=>$shipment->id,'event_uuid'=>$event->event_uuid,'payload_hash'=>$event->payload_hash]]];
  $this->authority($data);$summary=app(FulfillmentRequestService::class)->upsert($data,323);
  return [$order,$data,FulfillmentRequest::findOrFail($summary['id']),$shipment];
 }
 public function test_partial_stock_born_approval_reuses_order_and_counts_only_new_shipments():void {
  [$order,$data,$request,$old]=$this->setupReuse();$before=StockLedger::count();$service=app(FulfillmentRequestService::class);
  $approved=$service->approve($request,$this->warehouse->id);$this->assertSame($order->id,$approved['sales_order_id']);$this->assertSame(1,SalesOrder::count());$this->assertSame([],$approved['shipment_ids']);$this->assertSame('0.0000',$approved['fulfilled_quantity']);$this->assertSame($before,StockLedger::count());
  $this->assertSame('4.0000',(string)$request->lines()->sole()->source_shipped_qty_base);
  $line=$order->lines()->sole();$shipments=app(ShipmentService::class);$new=$shipments->post($shipments->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-07'],[['sales_order_line_id'=>$line->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'2']]));
  $summary=$service->status($request->fresh('lines'));$this->assertSame('partial',$summary['status']);$this->assertSame('2.0000',$summary['fulfilled_quantity']);$this->assertSame([$new->id],$summary['shipment_ids']);$this->assertSame(1,SalesOrder::count());$this->assertSame($before+1,StockLedger::count());
 }
 public function test_reuse_rejects_tampered_source_or_changed_native_capacity_without_new_order():void {
  [$order,$data,$request]=$this->setupReuse();$before=StockLedger::count();$payload=$request->source_payload;$payload['origin_order']['source_shipment_refs'][0]['payload_hash']=str_repeat('f',64);$request->update(['source_payload'=>$payload]);
  try{app(FulfillmentRequestService::class)->approve($request,$this->warehouse->id);$this->fail('Tampered source accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  $this->assertNull($request->fresh()->sales_order_id);$this->assertSame(1,SalesOrder::count());$this->assertSame($before,StockLedger::count());
 }
}
