<?php
namespace Tests\Feature\Sales;
use App\Models\Tenant\{Customer,FulfillmentRequest,IntegrationMasterDataMapping,IntegrationOrganizationMapping,Item,SalesOrder,StockLedger,Unit};
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Sales\FulfillmentRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;
final class FulfillmentHandoffTest extends TestCase {
 use TenantAware;use \Tests\Support\SalesHandoffFixture;
 protected function setUp():void {parent::setUp();$this->initializeSalesFixture();}
 public function test_invoice_handoff_and_replay_create_only_one_pending_request_without_order_or_stock():void {$d=$this->data();$this->authority($d);$service=app(FulfillmentRequestService::class);$one=$service->upsert($d,323);$two=$service->upsert($d,323);$this->assertSame($one['id'],$two['id']);$this->assertSame('pending',$one['status']);$this->assertNull($one['warehouse_id']);$this->assertNull($one['sales_order_id']);$this->assertSame(1,FulfillmentRequest::query()->count());$this->assertSame(0,SalesOrder::query()->count());$this->assertSame(0,StockLedger::query()->count());$this->assertSame('801',$one['lines'][0]['source_line_id']);}
 public function test_authenticated_invoice_payload_cannot_be_changed_by_signed_caller():void {$d=$this->data();$canonical=$d;$canonical['customer_external_id']=999;$this->authority($d,['fulfillment_payload'=>$canonical]);try{app(FulfillmentRequestService::class)->upsert($d,323);$this->fail('Forged customer accepted');}catch(HttpException$e){$this->assertSame(403,$e->getStatusCode());}$this->assertSame(0,FulfillmentRequest::query()->count());}
 public function test_missing_unit_mapping_is_actionable_and_resumes_same_uuid_after_review():void {$d=$this->data();$this->authority($d);$pair=IntegrationMasterDataMapping::query()->where('entity_type','unit')->sole();$pair->update(['status'=>'pending']);try{app(FulfillmentRequestService::class)->upsert($d,323);$this->fail('Missing unit accepted');}catch(ValidationException$e){$this->assertArrayHasKey('lines.0.unit_external_id',$e->errors());$this->assertSame('unit',$e->response->getData(true)['dependency']['entity_type']);$this->assertSame(702,$e->response->getData(true)['dependency']['source_id']);}$pair->update(['status'=>'verified']);$r=app(FulfillmentRequestService::class)->upsert($d,323);$this->assertSame($d['request_uuid'],$r['request_uuid']);$this->assertSame(0,StockLedger::query()->count());}
 public function test_competing_request_uuid_and_other_tenant_mapping_never_create_another_request():void {$d=$this->data();$this->authority($d);app(FulfillmentRequestService::class)->upsert($d,323);$new=array_replace($d,['request_uuid'=>(string)Str::uuid()]);$this->authority($new);try{app(FulfillmentRequestService::class)->upsert($new,323);$this->fail('Second request accepted');}catch(HttpException$e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame(1,FulfillmentRequest::query()->count());$this->useTenantB();$this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);app(FulfillmentRequestService::class)->upsert($new,323);}
 private function cancelAuthority(array $data, array $overrides=[]): void {
  $this->authority($data,[], 'cancel');
  $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'edit_draft',['command'=>'cancel','request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'expected_revision'=>$data['expected_revision']??null])->andReturn(array_replace(['allowed'=>true,'command'=>'cancel','request_uuid'=>$data['request_uuid'],'command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['expected_revision']??null],$overrides));
 }
 public function test_cancel_before_acceptance_is_a_durable_tombstone_without_a_fake_order_or_request(): void {
  $data=$this->data();$this->cancelAuthority($data);$service=app(FulfillmentRequestService::class);$one=$service->cancel($data,323);$two=$service->cancel($data,323);
  $this->assertSame($one,$two);$this->assertNull($one['id']);$this->assertSame('cancelled',$one['status']);$this->assertSame([],$one['lines']);$this->assertSame([],$one['shipment_ids']);
  $this->assertSame(1,DB::connection('tenant')->table('sales_fulfillment_cancellations')->count());$this->assertSame(0,FulfillmentRequest::count());$this->assertSame(0,SalesOrder::count());$this->assertSame(0,StockLedger::count());
  $this->authority($data);try{$service->upsert($data,323);$this->fail('Delayed old acceptance resurrected cancellation');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
 }
 public function test_cancellation_must_match_independently_authorized_intent_not_caller_claims(): void {
  $data=$this->data();$this->cancelAuthority($data,['command_source_revision'=>str_repeat('f',64)]);
  try{app(FulfillmentRequestService::class)->cancel($data,323);$this->fail('Unrelated cancellation proof accepted');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
  $this->assertSame(0,DB::connection('tenant')->table('sales_fulfillment_cancellations')->count());
 }
 public function test_existing_request_cancellation_preserves_identity_and_rejects_late_delivery(): void {
  $data=$this->data();$this->authority($data);$service=app(FulfillmentRequestService::class);$request=$service->upsert($data,323);
  $cancel=$data+['expected_revision'=>$data['source_revision']];$this->cancelAuthority($cancel);$result=$service->cancel($cancel,323);$this->assertSame($request['id'],$result['id']);$this->assertSame('cancelled',$result['status']);
  $this->authority($data);$replayed=$service->upsert($data,323);$this->assertSame('cancelled',$replayed['status']);$this->assertSame(0,StockLedger::count());$this->assertSame(0,SalesOrder::count());
 }
 public function test_cancellation_published_after_authorization_blocks_stale_request_admission(): void {
  $data=$this->data();$this->authority($data);DB::connection('tenant')->table('finance_sales_requests')->where('request_uuid',$data['request_uuid'])->update(['command'=>'cancel']);
  try{app(FulfillmentRequestService::class)->upsert($data,323);$this->fail('Stale callback published request after cancellation');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  $this->assertSame(0,FulfillmentRequest::count());$this->assertSame(0,StockLedger::count());
 }

 public function test_posted_invoice_request_requires_exact_native_active_journal_and_stale_callback_cannot_publish(): void {
  $data=$this->data();$data['source_status']='posted';$data['posted_invoice_journal_id']=99;
  DB::connection('tenant')->table('journal_entries')->insert(['id'=>99,'organization_id'=>14,'status'=>'posted','source_type'=>'App\\Models\\Invoice','source_id'=>800]);
  $this->authority($data);$service=app(FulfillmentRequestService::class);$service->upsert($data,323);
  DB::connection('tenant')->table('journal_entries')->where('id',99)->update(['voided_at'=>now()]);
  try{$service->upsert($data,323);$this->fail('Stale authorized journal published after native reversal');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  $this->assertSame(1,FulfillmentRequest::count());$this->assertSame(0,StockLedger::count());$this->assertSame(0,SalesOrder::count());
 }
 public function test_invoice_void_closure_requires_its_own_permission_and_exact_original_journal_proof(): void {
  $data=$this->data();$data['source_status']='posted';$data['posted_invoice_journal_id']=99;
  DB::connection('tenant')->table('journal_entries')->insert(['id'=>99,'organization_id'=>14,'status'=>'posted','source_type'=>'App\\Models\\Invoice','source_id'=>800]);
  $this->authority($data);$service=app(FulfillmentRequestService::class);$service->upsert($data,323);
  $cancel=$data+['expected_revision'=>$data['source_revision'],'close_permission'=>'void','closing_invoice_journal_id'=>99];$this->authority($cancel,[],'cancel');
  $reply=['command'=>'cancel','request_uuid'=>$data['request_uuid'],'command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision'],'closure_permission'=>'unpost','closing_invoice_journal_id'=>99];
  $review=['command'=>'cancel','request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision'],'closing_invoice_journal_id'=>99];
  $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'void',$review)->andReturn($reply);
  try{$service->cancel($cancel,323);$this->fail('Borrowed closure permission accepted');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
  $this->assertSame('pending',FulfillmentRequest::sole()->status);
  $reply['closure_permission']='void';$this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'void',$review)->andReturn($reply);
  $this->assertSame('cancelled',$service->cancel($cancel,323)['status']);$this->assertSame(0,StockLedger::count());
 }

}
