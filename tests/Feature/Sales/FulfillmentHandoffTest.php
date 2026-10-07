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
 use TenantAware;private $mapping;private $item;private $unit;private $customer;
 protected function setUp():void {parent::setUp();$this->useTenantA();$this->mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'tenant_database_identity'=>DB::connection('tenant')->getDatabaseName(),'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD','verified_at'=>now()]);$this->unit=Unit::create(['code'=>'SALE-EACH','name'=>'Each','kind'=>'count','is_active'=>true]);$this->item=F::averageItem(['base_unit_id'=>$this->unit->id]);$this->customer=Customer::create(['code'=>'QA-SALE-CUST','name'=>'QA customer','is_active'=>true]);foreach(['item'=>[$this->item->id,701],'unit'=>[$this->unit->id,702],'customer'=>[$this->customer->id,703]]as$type=>[$local,$remote])IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'entity_type'=>$type,'solastock_record_id'=>(string)$local,'solabooks_record_id'=>(string)$remote,'status'=>'verified']);}
 private function data():array{return['request_uuid'=>(string)Str::uuid(),'source_invoice_id'=>800,'source_invoice_number'=>'QA-800','source_revision'=>str_repeat('a',64),'source_status'=>'draft','pricing_mode'=>'exclusive','customer_external_id'=>703,'invoice_date'=>'2026-10-07','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1','exchange_rate_date'=>'2026-10-07','lines'=>[['source_line_id'=>'801','item_external_id'=>701,'unit_external_id'=>702,'quantity'=>'4','unit_price'=>'7']]];}
 private function authority(array$d,array$overrides=[],string $command='upsert'):void {
  $schema=DB::connection('tenant')->getSchemaBuilder();if(!$schema->hasTable('finance_sales_requests'))$schema->create('finance_sales_requests',function(\Illuminate\Database\Schema\Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->unsignedBigInteger('invoice_id');$t->unsignedBigInteger('invoice_journal_id')->nullable();$t->string('source_revision');$t->string('command');});
  DB::connection('tenant')->table('invoices')->updateOrInsert(['id'=>800],['organization_id'=>14]);
  DB::connection('tenant')->table('finance_sales_requests')->updateOrInsert(['organization_id'=>14,'invoice_id'=>800,'request_uuid'=>$d['request_uuid']],['organization_mapping_uuid'=>$this->mapping->mapping_uuid,'source_revision'=>$d['source_revision'],'command'=>$command]);
$canonical=$d;unset($canonical['expected_revision']);$this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'edit_draft')->andReturn(array_replace(['allowed'=>true,'request_revision'=>$d['source_revision'],'fulfillment_payload'=>$canonical],$overrides));}
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

}
