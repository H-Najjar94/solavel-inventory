<?php
namespace Tests\Feature\Integration;
use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationSetting,Supplier,IntegrationMasterDataMapping};
use App\Services\Integration\{ContinuousPartySync,PartySyncLedger};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;
final class ContinuousPartySyncTest extends TestCase {
 use TenantAware;
 private $mapping;
 protected function setUp():void {
  parent::setUp();$this->useTenantA();
  DB::connection('tenant')->table('organizations')->insert(['id'=>14,'central_org_id'=>TenantTestManager::ORG_A,'setup_status'=>'complete']);
  $this->mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'tenant_database_identity'=>DB::connection('tenant')->getDatabaseName(),'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','verified_at'=>now()]);
  IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14]);
 }
 private function source(int $id=901,string $name='New canonical supplier',?string $created=null):void {
  DB::connection('tenant')->table('suppliers')->insert(['id'=>$id,'organization_id'=>14,'name'=>$name,'is_active'=>true,'created_at'=>$created??now(),'updated_at'=>now()]);
 }
 private function ensure(int $id=901):array {return app(ContinuousPartySync::class)->materialize($this->mapping,'finance','supplier',$id);}
 public function test_new_supplier_has_one_native_counterpart_and_one_verified_identity_on_replay():void {
  $this->source();$one=$this->ensure();$two=$this->ensure();
  $this->assertSame('synced',$one['status']);$this->assertSame($one['target_id'],$two['target_id']);$this->assertSame($one['mapping_uuid'],$two['mapping_uuid']);
  $this->assertSame(1,Supplier::query()->count());$this->assertSame(1,IntegrationMasterDataMapping::query()->count());
 }
 public function test_source_name_updates_without_overwriting_intentional_target_edit():void {
  $this->source();$r=$this->ensure();$db=DB::connection('tenant');
  $db->table('suppliers')->where('id',901)->update(['name'=>'Updated source']);$this->ensure();
  $this->assertSame('Updated source',Supplier::findOrFail($r['target_id'])->name);
  Supplier::findOrFail($r['target_id'])->update(['name'=>'Warehouse local override']);
  $db->table('suppliers')->where('id',901)->update(['name'=>'Later source']);$last=$this->ensure();
  $this->assertSame('Warehouse local override',Supplier::findOrFail($r['target_id'])->name);$this->assertTrue($last['field_overrides']['name']);
 }
 public function test_same_name_never_creates_an_implicit_identity():void {
  $this->source();Supplier::create(['code'=>'LOCAL','name'=>'New canonical supplier','is_active'=>true]);
  $r=$this->ensure();$this->assertSame('intervention',$r['status']);$this->assertSame('party_identity_review_required',$r['reason']);$this->assertSame(0,IntegrationMasterDataMapping::query()->count());
 }
 public function test_pause_after_preflight_prevents_counterpart_creation():void {
  $this->source();DB::connection('tenant')->table('integration_settings')->where('organization_id',TenantTestManager::ORG_A)->update(['mode'=>'paused']);
  $r=$this->ensure();$this->assertSame('held',$r['status']);$this->assertSame(0,Supplier::query()->count());
 }
 public function test_mapping_identity_change_after_preflight_prevents_write():void {
  $this->source();DB::connection('tenant')->table('integration_organization_mappings')->where('id',$this->mapping->id)->update(['central_client_id'=>8]);
  $this->assertSame('held',$this->ensure()['status']);$this->assertSame(0,Supplier::query()->count());
 }
 public function test_inactive_source_archives_existing_mapping_without_deleting_target():void {
  $this->source();$r=$this->ensure();DB::connection('tenant')->table('suppliers')->where('id',901)->update(['is_active'=>false]);
  $this->assertSame('held',$this->ensure()['status']);$this->assertTrue(IntegrationMasterDataMapping::query()->sole()->solabooks_archived);$this->assertNotNull(Supplier::find($r['target_id']));
 }
 public function test_reconcile_excludes_historical_unmapped_sources_but_includes_new_imports():void {
  $this->source(901,'Historical excluded','2020-01-01');$this->source(902,'Fresh imported');
  app(PartySyncLedger::class)->reconcile($this->mapping);
  $rows=DB::connection('tenant')->table('integration_party_sync_states')->where('source_app','finance')->pluck('source_id')->all();
  $this->assertSame([902],array_map('intval',$rows));
 }
 public function test_explicit_dependency_can_record_historical_supplier():void {
  $this->source(901,'Historical wanted','2020-01-01');$this->assertSame('synced',$this->ensure()['status']);
 }
 public function test_standalone_supplier_records_no_finance_work_without_connection():void {
  DB::connection('tenant')->table('integration_organization_mappings')->delete();Supplier::create(['code'=>'SOLO','name'=>'Standalone','is_active'=>true]);
  $this->assertSame(0,DB::connection('tenant')->table('integration_party_sync_states')->count());
 }
 public function test_changed_source_revision_requires_retry_without_writing_target():void {
  $this->source();$revision=app(PartySyncLedger::class)->revision(app(PartySyncLedger::class)->fields($this->mapping,'finance','supplier',901));
  DB::connection('tenant')->table('suppliers')->where('id',901)->update(['name'=>'Changed concurrently']);
  $r=app(ContinuousPartySync::class)->materialize($this->mapping,'finance','supplier',901,$revision);
  $this->assertSame('pending',$r['status']);$this->assertSame('party_source_changed',$r['reason']);$this->assertSame(0,Supplier::query()->count());
 }
 public function test_signed_cancel_proof_creates_a_replayable_tombstone_without_supplier_or_receipt():void {
  $id=(string)Str::uuid();$revision=str_repeat('c',64);
  $data=['request_uuid'=>$id,'source_bill_id'=>71,'source_revision'=>$revision,'expected_revision'=>null];
  $authority=['command'=>'cancel','request_uuid'=>$id,'command_source_revision'=>$revision,'expected_revision'=>null,'source_bill_id'=>71,'permission'=>'edit_draft'];
  $service=app(\App\Services\Purchasing\ReceivingRequestService::class);
  $one=$service->cancelAuthorized($data,$authority);$two=$service->cancelAuthorized($data,$authority);
  $this->assertSame('cancelled',$one['status']);$this->assertSame($one,$two);
  $this->assertSame(1,DB::connection('tenant')->table('purchasing_receiving_cancellations')->count());
  $this->assertSame(0,DB::connection('tenant')->table('purchasing_receiving_requests')->count());
  $this->assertSame(0,DB::connection('tenant')->table('goods_receipts')->count());
  try{$service->upsert($data);$this->fail('Delayed upsert recreated a cancelled request.');}
  catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('request_uuid',$e->errors());}
 }
 public function test_cancel_tombstone_rejects_an_authority_for_a_different_bill():void {
  $id=(string)Str::uuid();$revision=str_repeat('c',64);
  try{app(\App\Services\Purchasing\ReceivingRequestService::class)->cancelAuthorized(['request_uuid'=>$id,'source_bill_id'=>71,'source_revision'=>$revision],['command'=>'cancel','request_uuid'=>$id,'command_source_revision'=>$revision,'source_bill_id'=>72]);$this->fail('Foreign Bill proof accepted.');}
  catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
  $this->assertSame(0,DB::connection('tenant')->table('purchasing_receiving_cancellations')->count());
 }

}
