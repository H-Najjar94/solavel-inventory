<?php
namespace Tests\Feature\Integration;
use App\Models\Tenant\{IntegrationOrganizationMapping,ReceivingRequest,Supplier,Unit};
use App\Services\Purchasing\PurchasingNotificationPublisher;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/** Native durable lease/dedupe rows; Central HTTP is an explicitly isolated remote seam. */
final class PurchasingNotificationOutboxTest extends TestCase {
 use TenantAware;
 private function fixture():array {
  $this->useTenantA();$org=(int)app(OrganizationContext::class)->idOrFail();$uuid=(string)Str::uuid();IntegrationOrganizationMapping::create(['mapping_uuid'=>$uuid,'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>DB::connection('tenant')->getDatabaseName(),'integration'=>'solabooks','contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD']);
  $supplier=Supplier::create(['code'=>'NOTIF-'.Str::random(8),'name'=>'Notification supplier','is_active'=>true]);
  $rr=ReceivingRequest::create(['organization_mapping_uuid'=>$uuid,'finance_organization_id'=>14,'request_uuid'=>(string)Str::uuid(),'source_bill_id'=>800,'source_bill_number'=>'QA-NOTIFICATION-800','source_revision'=>str_repeat('a',64),'supplier_id'=>$supplier->id,'currency_code'=>'JOD','status'=>'pending','source_payload'=>[]]);config(['solavel_sync.secret'=>str_repeat('n',40),'sso.central_app_url'=>'https://central.test']);return[$org,$rr];
 }
 private function queue(ReceivingRequest $rr):void{(new \ReflectionMethod(PurchasingNotificationPublisher::class,'queue'))->invoke(app(PurchasingNotificationPublisher::class),(int)$rr->organization_id,(int)$rr->id);}
 public function test_semantic_replay_keeps_one_durable_event_without_document_effects():void {
  [, $rr]=$this->fixture();$this->queue($rr);$this->queue($rr);$this->assertSame(1,DB::connection('tenant')->table('purchasing_notification_outbox')->count());$this->assertSame(0,DB::connection('tenant')->table('stock_ledger')->count());$this->assertSame(0,DB::connection('tenant')->table('goods_receipts')->count());$rr->update(['status'=>'cancelled']);$this->queue($rr);$this->assertSame(2,DB::connection('tenant')->table('purchasing_notification_outbox')->count());
 }
 public function test_timeout_after_dispatch_retries_and_expired_lease_recovers_without_resetting_financial_outbox():void {
  [$org,$rr]=$this->fixture();$this->queue($rr);$thread=(string)Str::uuid();$sent=0;Http::fake(function($request)use(&$sent,$org,$rr,$thread){$sent++;if($sent===1)throw new \Illuminate\Http\Client\ConnectionException('Timeout after remote commit');return Http::response(['organization_id'=>$org,'request_id'=>$rr->id,'thread_id'=>$thread],200);});$publisher=app(PurchasingNotificationPublisher::class);$publisher->process(1);$row=DB::connection('tenant')->table('purchasing_notification_outbox')->sole();$this->assertSame('pending',$row->state);$this->assertSame('central_publish_unavailable',$row->last_error);$this->assertSame(1,(int)$row->attempts);
  DB::connection('tenant')->table('purchasing_notification_outbox')->where('id',$row->id)->update(['state'=>'processing','lease_token'=>(string)Str::uuid(),'lease_until'=>now()->subSeconds(1),'retry_at'=>now()->subSeconds(1)]);$publisher->process(1);$row=DB::connection('tenant')->table('purchasing_notification_outbox')->sole();$this->assertSame('sent',$row->state);$this->assertSame($thread,$row->notification_thread_id);$this->assertSame(2,(int)$row->attempts);$this->assertSame(0,$publisher->process(1));$this->assertSame(2,$sent);$this->assertSame(0,DB::connection('tenant')->table('integration_outbox_events')->count());Http::assertSent(fn($r)=>$r->hasHeader('X-Solavel-App','inventory')&&$r['organization_id']===$org&&!isset($r['recipients']));
 }
 public function test_unexpired_claim_is_not_delivered_by_second_worker():void {
  [, $rr]=$this->fixture();$this->queue($rr);DB::connection('tenant')->table('purchasing_notification_outbox')->update(['state'=>'processing','lease_token'=>(string)Str::uuid(),'lease_until'=>now()->addMinute()]);Http::fake();$this->assertSame(0,app(PurchasingNotificationPublisher::class)->process(1));Http::assertNothingSent();
 }
}
