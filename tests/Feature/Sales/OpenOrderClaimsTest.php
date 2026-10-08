<?php
namespace Tests\Feature\Sales;
use App\Models\User;
use App\Models\Tenant\{Customer,FulfillmentRequest,SalesOrder,Shipment,StockLedger};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Documents\{SalesOrderService,ShipmentService};
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Sales\{FulfillmentRequestService,OpenOrderClaims};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/**
 * Batch 7: an invoice explicitly claims an open, unshipped Stock-born order instead of creating
 * a second one. Native order/shipment/valuation; signed Finance authorization is the remote seam.
 */
final class OpenOrderClaimsTest extends TestCase
{
 use TenantAware, \Tests\Support\SalesHandoffFixture;
 private array $intents=[];
 private function fixture():SalesOrder {
  $this->initializeSalesFixture(true);config(['inventory.demo_tenant.enabled'=>false]);$actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);$this->app->forgetInstance(InventoryPermissionService::class);
  return $this->order($this->customer->id,'4');
 }
 private function order(int $customer,string $qty):SalesOrder {
  $native=app(SalesOrderService::class);
  return $native->confirm($native->createDraft(['warehouse_id'=>$this->warehouse->id,'customer_id'=>$customer,'currency_code'=>'JOD'],[['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'ordered_qty'=>$qty,'unit_price'=>'7']]));
 }
 /** Finance invoice $invoice claims $order for $qty. */
 private function claim(SalesOrder $order,int $invoice=800,string $qty='4',?string $revision=null):array {
  $d=$this->data();$d['request_uuid']=(string)Str::uuid();$d['source_invoice_id']=$invoice;$d['source_invoice_number']='QA-'.$invoice;$d['lines'][0]['source_line_id']=(string)($invoice+1);
  $d['lines'][0]['quantity']=$qty;$d['lines'][0]['original_sales_order_line_id']=$order->lines->sole()->id;
  $d['origin_order']=['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'order_revision'=>$revision??app(OpenOrderClaims::class)->revision($order),'source_order_claim'=>true];
  $this->intent($d);return $d;
 }
 private function intent(array $d,string $command='upsert'):void {
  DB::connection('tenant')->table('invoices')->updateOrInsert(['id'=>$d['source_invoice_id']],['organization_id'=>14]);
  DB::connection('tenant')->table('finance_sales_requests')->updateOrInsert(['organization_id'=>14,'invoice_id'=>$d['source_invoice_id']],['request_uuid'=>$d['request_uuid'],'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'source_revision'=>$d['source_revision'],'command'=>$command,'invoice_journal_id'=>null]);
  $this->intents[$d['source_invoice_id']]=$d;
  $intents=&$this->intents;
  $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->andReturnUsing(function($actor,$invoice,$permission,$review=[])use(&$intents){
   $d=$intents[$invoice];$canonical=$d;unset($canonical['expected_revision']);
   if(($review['command']??null)==='cancel')return['allowed'=>true,'command'=>'cancel','request_uuid'=>$d['request_uuid'],'command_source_revision'=>$d['source_revision'],'expected_revision'=>$review['expected_revision']??null];
   return['allowed'=>true,'request_revision'=>$d['source_revision'],'fulfillment_payload'=>$canonical,'customer_external_id'=>$d['customer_external_id']];
  });
 }
 private function refused(callable $call,string $reason):void {
  try{$call();$this->fail('Claim accepted: '.$reason);}catch(ValidationException $e){$this->assertArrayHasKey('origin_order',$e->errors());$this->assertSame($reason,$e->response->getData(true)['claim']['reason']);}
 }
 public function test_open_orders_read_lists_only_unshipped_stock_born_orders_of_the_invoice_customer():void {
  $order=$this->fixture();$other=Customer::create(['code'=>'QA-OTHER-CUST','name'=>'Other customer','is_active'=>true]);$this->order($other->id,'2');
  $shipped=$this->order($this->customer->id,'1');$shipping=app(ShipmentService::class);
  $shipping->post($shipping->createDraft(['sales_order_id'=>$shipped->id,'warehouse_id'=>$this->warehouse->id,'shipment_number'=>'QA-SHIPPED-'.Str::uuid()],[['sales_order_line_id'=>$shipped->lines->sole()->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'1']]));
  $d=$this->data();$this->intent($d);$before=[SalesOrder::count(),FulfillmentRequest::count(),StockLedger::count()];
  $read=app(FulfillmentRequestService::class)->openOrders($d,323);
  $this->assertTrue($read['customer_mapped']);$this->assertCount(1,$read['orders']);$found=$read['orders'][0];
  $this->assertSame($order->id,$found['sales_order_id']);$this->assertSame(app(OpenOrderClaims::class)->revision($order),$found['order_revision']);$this->assertTrue($found['mapped']);
  $this->assertSame(701,$found['lines'][0]['item_external_id']);$this->assertSame(702,$found['lines'][0]['unit_external_id']);$this->assertSame(0,bccomp('4',$found['lines'][0]['unshipped'],4));
  $this->assertSame($before,[SalesOrder::count(),FulfillmentRequest::count(),StockLedger::count()]);
 }
 public function test_claimed_order_is_reused_on_approval_and_no_second_order_is_created():void {
  $order=$this->fixture();$d=$this->claim($order);$service=app(FulfillmentRequestService::class);$before=StockLedger::count();
  $service->upsert($d,323);$this->assertSame(1,SalesOrder::count());
  $approved=$service->approve(FulfillmentRequest::sole(),$this->warehouse->id);
  $this->assertSame($order->id,$approved['sales_order_id']);$this->assertSame(1,SalesOrder::count());$this->assertSame($before,StockLedger::count());
  $line=FulfillmentRequest::sole()->lines()->sole();$this->assertSame($order->lines->sole()->id,$line->sales_order_line_id);$this->assertSame(0,bccomp('0',(string)$line->source_shipped_qty_base,4));
  $shipping=app(ShipmentService::class);$shipping->post($shipping->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'shipment_number'=>'QA-CLAIM-'.Str::uuid()],[['sales_order_line_id'=>$order->lines->sole()->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'4']]));
  $status=$service->status(FulfillmentRequest::sole());$this->assertSame('complete',$status['status']);$this->assertSame(0,bccomp('4',$status['fulfilled_quantity'],8));$this->assertSame(1,SalesOrder::count());
 }
 public function test_a_second_invoice_cannot_claim_an_order_already_claimed():void {
  $order=$this->fixture();$service=app(FulfillmentRequestService::class);$service->upsert($this->claim($order),323);
  $second=$this->claim($order,900);
  $this->refused(fn()=>$service->upsert($second,323),'order_claim_taken');
  $this->assertSame(1,FulfillmentRequest::count());$this->assertSame(1,SalesOrder::count());
  // A held order is no longer offered to other invoices.
  $this->assertSame([],app(FulfillmentRequestService::class)->openOrders($second,323)['orders']);
 }
 public function test_partial_coverage_and_stale_revision_are_refused_truthfully():void {
  $order=$this->fixture();$service=app(FulfillmentRequestService::class);
  $this->refused(fn()=>$service->upsert($this->claim($order,800,'3'),323),'order_claim_partial');
  $this->refused(fn()=>$service->upsert($this->claim($order,800,'4',str_repeat('0',64)),323),'order_claim_changed');
  $this->assertSame(0,FulfillmentRequest::count());$this->assertSame(1,SalesOrder::count());
 }
 public function test_native_shipment_of_a_claimed_order_waits_for_request_approval():void {
  $order=$this->fixture();app(FulfillmentRequestService::class)->upsert($this->claim($order),323);$before=StockLedger::count();$shipping=app(ShipmentService::class);
  $draft=$shipping->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$this->warehouse->id,'shipment_number'=>'QA-EARLY-'.Str::uuid()],[['sales_order_line_id'=>$order->lines->sole()->id,'item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'4']]);
  try{$shipping->post($draft);$this->fail('Claimed order shipped before approval');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  $this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::where('status','posted')->count());
 }
 public function test_cancelling_an_undispatched_claim_releases_the_original_order_unchanged():void {
  $order=$this->fixture();$d=$this->claim($order);$service=app(FulfillmentRequestService::class);$service->upsert($d,323);$service->approve(FulfillmentRequest::sole(),$this->warehouse->id);
  $cancel=$d+['expected_revision'=>$d['source_revision']];$this->intent($cancel,'cancel');
  $result=$service->cancel($cancel,323);
  $this->assertSame('cancelled',$result['status']);$this->assertNull(FulfillmentRequest::sole()->sales_order_id);
  $this->assertSame('confirmed',$order->fresh()->status);$this->assertSame(1,SalesOrder::count());
  $read=$this->data();$read['source_invoice_id']=901;$this->intent($read);$this->assertCount(1,$service->openOrders($read,323)['orders']);
 }
}
