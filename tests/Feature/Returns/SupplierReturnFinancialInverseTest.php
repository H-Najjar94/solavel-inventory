<?php
namespace Tests\Feature\Returns;

use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationOutboxEvent,StockBalance,StockLedger,Supplier,SupplierReturn,Unit};
use App\Services\Access\{WarehouseAccessService,CentralAppAccess,InventoryPermissionService};
use App\Models\User;
use App\Services\Documents\{GoodsReceiptService,SupplierReturnService};
use App\Services\Integration\{IntegrationOutboxService,SolaStockJournalContract};
use App\Services\Returns\SupplierReturnFinancialReversalGuard;
use App\Services\Sales\SupplierReturnDocumentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Auth,Crypt,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native Stock GRN/OUT/inverse and real financial-proof queries. Finance tables and delivery are explicit isolated projections/seams. */
final class SupplierReturnFinancialInverseTest extends TestCase
{
 use TenantAware { tearDown as protected tearDownTenant; }
 private object $warehouse;
 private object $unit;
 private object $item;
 private object $supplier;

 protected function setUp():void
 {
  parent::setUp();\Carbon\Carbon::setTestNow('2026-10-08 12:00:00');$this->useTenantA();$this->prepareFinancialProjectionSchema();
  // Projection DDL is setup only; restore reserved-schema rollback before native documents.
  $this->tenantTestManager->cleanup();$this->setUpTenantAware();$this->useTenantA();
  $scope=$this->createStub(WarehouseAccessService::class);$scope->method('scope')->willReturnCallback(fn($q)=>$q);$this->app->instance(WarehouseAccessService::class,$scope);
  // Keep real Stock permission resolution: the canonical access boundary grants
  // a specific Stock manager role, rather than bypassing the returns gate.
  config(['inventory.demo_tenant.enabled'=>false]);
  $actor=new User;$actor->id=323;$actor->central_user_id=323;
  Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $this->mock(CentralAppAccess::class)->shouldReceive('decision')->with(323,(int)app(\App\Tenancy\OrganizationContext::class)->idOrFail(),'inventory')->andReturn(['allowed'=>true,'owner'=>false,'roles'=>['stock_manager']]);
  $this->app->forgetInstance(InventoryPermissionService::class);
  $this->assertTrue(app(InventoryPermissionService::class)->can($actor,'inventory.manage_returns'));
  $this->warehouse=F::warehouse();$this->unit=Unit::create(['code'=>'FIN-RETURN-EACH','name'=>'Each','kind'=>'count','is_active'=>true]);
  $this->item=F::averageItem(['base_unit_id'=>$this->unit->id]);$this->supplier=Supplier::create(['code'=>'FIN-RETURN-SUP','name'=>'Synthetic physical inverse supplier','is_active'=>true]);
 }
 protected function tearDown():void {try{$this->tearDownTenant();}finally{\Carbon\Carbon::setTestNow();}}
 private function prepareFinancialProjectionSchema():void
 {
  $schema=DB::connection('tenant')->getSchemaBuilder();
  foreach([
   'finance_supplier_return_reversals'=>function(Blueprint$t){$t->id();foreach(['organization_id','supplier_return_id','stock_return_id','stock_reversal_id','original_out_journal_id','inverse_import_journal_id','debit_note_id','voided_note_journal_id']as$c)$t->unsignedBigInteger($c);foreach(['organization_mapping_uuid','return_mapping_uuid','original_event_uuid']as$c)$t->uuid($c);$t->string('source_key',191);$t->char('source_hash',64);$t->longText('payload');$t->timestamps();},
   'debit_notes'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('bill_id');$t->unsignedBigInteger('journal_entry_id');$t->string('status');},
   'finance_supplier_returns'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('return_mapping_uuid');foreach(['stock_return_id','bill_id','debit_note_id','bill_journal_id','receipt_journal_id','stock_return_journal_id']as$c)$t->unsignedBigInteger($c);$t->unsignedBigInteger('bridge_journal_id')->nullable();$t->char('source_hash',64);$t->longText('payload');},
   'finance_supplier_return_lines'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('supplier_return_id');$t->decimal('entered_quantity',20,8);$t->unsignedBigInteger('debit_note_line_id');$t->decimal('actual_out_base',24,6);},
   'finance_supplier_return_credit_allocations'=>function(Blueprint$t){$t->id();foreach(['organization_id','debit_note_id','supplier_return_id','supplier_return_line_id','journal_entry_id','bill_id','bill_journal_id','debit_note_line_id']as$c)$t->unsignedBigInteger($c);$t->decimal('quantity',20,8);$t->decimal('actual_out_base',24,6);$t->string('state');$t->string('branch');},
   'action_logs'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('user_id');$t->string('controller');$t->string('method');$t->json('data');},
   'debit_allocations'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('debit_note_id');},
   'supplier_refunds'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('debit_note_id');$t->string('status');},
  ]as$table=>$definition)if(!$schema->hasTable($table))$schema->create($table,$definition);
  foreach(['finance_supplier_returns'=>['reviewed_bill_journal_id'=>fn(Blueprint$t)=>$t->unsignedBigInteger('reviewed_bill_journal_id')->nullable()],
   'finance_supplier_return_credit_allocations'=>['snapshot'=>fn(Blueprint$t)=>$t->longText('snapshot')->nullable()]]as$table=>$columns)
   foreach($columns as$column=>$definition)if(!$schema->hasColumn($table,$column))$schema->table($table,$definition);
  foreach(['source_key'=>fn(Blueprint$t)=>$t->string('source_key')->nullable(),'void_reason'=>fn(Blueprint$t)=>$t->string('void_reason')->nullable(),'voided_by'=>fn(Blueprint$t)=>$t->unsignedBigInteger('voided_by')->nullable()]as$column=>$definition)
   if(!$schema->hasColumn('journal_entries',$column))$schema->table('journal_entries',$definition);
 }
 private function source():array
 {
  $grns=app(GoodsReceiptService::class);$draft=$grns->createDraft(['supplier_id'=>$this->supplier->id,'warehouse_id'=>$this->warehouse->id,'receipt_date'=>'2026-10-07','currency_code'=>'JOD'],[['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'received_qty'=>'10','accepted_qty'=>'10','unit_cost'=>'5']]);
  $receipt=$grns->post($draft)->fresh('lines');$service=app(SupplierReturnService::class);
  $return=$service->post($service->createDraft(['goods_receipt_id'=>$receipt->id,'return_date'=>'2026-10-07','reason'=>'Synthetic matched physical return'],[['goods_receipt_line_id'=>$receipt->lines->sole()->id,'entered_qty'=>'2']]));
  $db=DB::connection('tenant');$org=(int)$return->organization_id;
  $mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>$db->getDatabaseName(),'integration'=>'solabooks','contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD']);
  $life=(string)Str::uuid();$db->table('integration_document_lifecycle_mappings')->insert(['mapping_uuid'=>$life,'organization_mapping_uuid'=>$mapping->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>$db->getDatabaseName(),'source_application'=>'solastock','source_document_type'=>'supplier_return','source_document_id'=>(string)$return->id,'document_version'=>'phase3.v1','lifecycle_status'=>'posted','base_currency_code'=>'JOD','created_at'=>now(),'updated_at'=>now()]);
  $event=IntegrationOutboxEvent::create(['organization_id'=>$org,'event_uuid'=>(string)Str::uuid(),'integration'=>'solabooks','event_type'=>'supplier_return.posted','aggregate_type'=>'SupplierReturn','aggregate_id'=>$return->id,'idempotency_key'=>'synthetic-native-return:'.$return->id,'payload'=>[],'status'=>'sent','mapping_status'=>'complete','attempts'=>1,'occurred_at'=>now()]);
  $payload=['identity'=>['central_client_id'=>7,'central_organization_id'=>$org,'finance_organization_id'=>14,'inventory_organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid],'return'=>['document_uuid'=>$return->return_uuid,'receipt_id'=>$receipt->id,'journal_idempotency_key'=>$event->idempotency_key]];
  $db->table('bills')->insert(['id'=>21,'organization_id'=>14,'supplier_id'=>8,'status'=>'posted','journal_entry_id'=>31]);$db->table('debit_notes')->insert(['id'=>22,'organization_id'=>14,'bill_id'=>21,'journal_entry_id'=>34,'status'=>'posted']);
  foreach([31=>['AP','App\\Models\\Bill',21],32=>['API','Synthetic GRN',1],33=>['API','Synthetic OUT',1],34=>['NOTE','App\\Models\\DebitNote',22]]as$id=>[$source,$type,$sourceId])$db->table('journal_entries')->insert(['id'=>$id,'organization_id'=>14,'source'=>$source,'source_type'=>$type,'source_id'=>$sourceId,'source_key'=>$id===33?'external-api:'.hash('sha256',$event->idempotency_key):'synthetic:'.$id,'status'=>'posted','posted_at'=>now()]);
  $db->table('finance_supplier_returns')->insert(['id'=>23,'organization_id'=>14,'organization_mapping_uuid'=>$mapping->mapping_uuid,'return_mapping_uuid'=>$life,'stock_return_id'=>$return->id,'bill_id'=>21,'debit_note_id'=>22,'bill_journal_id'=>31,'receipt_journal_id'=>32,'stock_return_journal_id'=>33,'source_hash'=>hash('sha256',SolaStockJournalContract::canonicalJson($payload)),'payload'=>json_encode($payload)]);
  $db->table('finance_supplier_return_lines')->insert(['id'=>24,'organization_id'=>14,'supplier_return_id'=>23,'entered_quantity'=>'2','debit_note_line_id'=>26,'actual_out_base'=>'10']);
  $db->table('finance_supplier_return_credit_allocations')->insert(['organization_id'=>14,'debit_note_id'=>22,'supplier_return_id'=>23,'supplier_return_line_id'=>24,'journal_entry_id'=>34,'bill_id'=>21,'bill_journal_id'=>31,'debit_note_line_id'=>26,'quantity'=>'2','actual_out_base'=>'10','state'=>'posted','branch'=>'matched_physical']);
  return compact('db','org','mapping','return','receipt','service');
 }
 private function voidCredit($db,bool$audit=true):void
 {
  $db->table('debit_notes')->where('id',22)->update(['status'=>'void']);$db->table('journal_entries')->where('id',34)->update(['status'=>'voided','voided_at'=>now(),'voided_by'=>337,'void_reason'=>'debit-note-void']);$db->table('finance_supplier_return_credit_allocations')->where('debit_note_id',22)->update(['state'=>'voided']);
  if($audit)$db->table('action_logs')->insert(['user_id'=>337,'controller'=>'ReversalEngine','method'=>'voidDebitNote','data'=>json_encode(['document_type'=>'App\\Models\\DebitNote','document_id'=>22])]);
 }
 private function refused($service,$return):void
 {
  $before=StockLedger::count();try{app(SupplierReturnFinancialReversalGuard::class)->lockAndAssert($return);$this->fail('An unproven inverse was accepted.');}catch(ValidationException$e){$this->assertArrayHasKey('integration',$e->errors());}
  $this->assertSame($before,StockLedger::count());$this->assertSame('posted',$return->fresh()->status);
 }
 public function test_missing_returns_authority_refuses_before_physical_inverse():void
 {
  extract($this->source());$this->voidCredit($db);$before=StockLedger::count();
  $denied=new User;$denied->id=901;$denied->central_user_id=901;
  Auth::setUser($denied);request()->setUserResolver(fn()=>$denied);
  $this->mock(CentralAppAccess::class)->shouldReceive('decision')->with(901,$org,'inventory')->andReturn(['allowed'=>false,'owner'=>false,'roles'=>[]]);
  $this->app->forgetInstance(InventoryPermissionService::class);
  $this->assertFalse(app(InventoryPermissionService::class)->can($denied,'inventory.manage_returns'));
  try{$service->reverse($return,'Synthetic unauthorized inverse');$this->fail('Missing returns authority was accepted.');}
  catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame(403,$e->getStatusCode());}
  $this->assertSame($before,StockLedger::count());$this->assertSame('posted',$return->fresh()->status);
 }
 public function test_active_credit_and_missing_native_void_audit_refuse_before_physical_inverse():void
 {
  extract($this->source());$this->refused($service,$return);$this->voidCredit($db,false);$this->refused($service,$return);
 }
 #[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
 public function test_genuine_void_proof_allows_native_physical_inverse_once_and_preserves_original_receipt():void
 {
  extract($this->source());$fixture=new \Tests\Support\CommittedTenantFixture($this->tenantTestManager);
  try{
   $this->voidCredit($db);$this->prepareNativeReadiness($mapping,$return,$receipt);$fixture->commit();
   $this->assertSame(0,$db->transactionLevel());
   $service=app(SupplierReturnService::class);$before=StockLedger::count();$inverse=$service->reverse($return,'Synthetic genuine credit void');$after=StockLedger::count();
   $this->assertSame($before+1,$after);$this->assertSame('supplier_return',$inverse->source_type);$this->assertSame('reversed',$return->fresh()->status);$this->assertSame('posted',$receipt->fresh()->status);
   $balance=StockBalance::query()->where('item_id',$this->item->id)->sole();$this->assertEquals(10,(float)$balance->on_hand_qty);$this->assertEquals(50,(float)$balance->total_value);
   $this->assertSame(1,IntegrationOutboxEvent::where('event_type','supplier_return.reversed')->where('aggregate_id',$inverse->id)->count());
   $this->assertSame(1,\App\Models\Tenant\PurchasingDocumentOutbox::where('event_type','purchasing.return.reversed')->count());
   $this->assertSame($inverse->id,$service->reverse($return->fresh(),'Repeated same native inverse')->id);$this->assertSame($after,StockLedger::count());
   $this->assertSame(1,\App\Models\Tenant\PurchasingDocumentOutbox::where('event_type','purchasing.return.reversed')->count());
  }finally{$fixture->restore();}
 }
 private function prepareNativeReadiness($mapping,$return,$receipt):void
 {
  $org=(int)$mapping->solastock_organization_id;$db=DB::connection('tenant');
  $db->table('organizations')->insert(['id'=>14,'central_org_id'=>$org,'setup_status'=>'complete','finance_setup_completed_at'=>now()]);
  \App\Models\Tenant\IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14,'meta'=>[
   'client_id'=>7,'central_organization_id'=>$org,'api_key_encrypted'=>Crypt::encryptString('private-returns-key'),
   'signing_key_id'=>'private-return-proof','signing_secret_encrypted'=>Crypt::encryptString('private-return-proof-secret-at-least-thirty-two-bytes'),'signing_protocol_version'=>'v1',
   'transport_enabled'=>false,'transport_enabled_workflows'=>['supplier_return.posted','supplier_return.reversed','grn.posted'],
   'finance_currency_contract'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],'money_scale'=>2,'rate_scale'=>8,'inventory_valuation_basis'=>\App\Services\Integration\FinanceBaseValuation::BASIS]]]);
  foreach(['inventory_asset'=>100,'supplier_return_clearing'=>200]as$role=>$id){
   $db->table('accounts')->insert(['id'=>$id,'organization_id'=>14,'name'=>'Synthetic projected '.$role,'type'=>'asset','is_active'=>true,'is_postable'=>true]);
   \App\Models\Tenant\IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>$role,'solabooks_account_id'=>$id,'status'=>'verified']);
  }
  // Canonical capability reads the actual dedicated private Central database, not TenantManager's mysql alias.
  $central=config('database.connections.mysql');$central['database']=$this->tenantTestManager->centralDatabase();
  config(['database.connections.return_fixture_central'=>$central,'tenancy.central_connection'=>'return_fixture_central']);DB::purge('return_fixture_central');
  \App\Tenancy\TenancySafetyGuard::assertCentralAndTenantDiffer($central['database'],$db->getDatabaseName());
  $state=['client_id'=>7,'organization_id'=>$org,'integration_capabilities'=>['connection_activation_delivery_entitled'=>true],'applications'=>[
   'finance'=>['accessible'=>true,'commercially_entitled'=>true],'inventory'=>['accessible'=>true,'commercially_entitled'=>true]]];
  DB::connection('return_fixture_central')->table('entitlement_state_snapshots')->updateOrInsert(['organization_id'=>$org],['underlying_subscription_state'=>'active','effective_access_state'=>'active','state_hash'=>hash('sha256',json_encode($state)),'state_payload'=>json_encode($state),'evaluated_at'=>now(),'last_changed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  foreach(['finance','inventory']as$slug)app(\App\Services\Entitlements\EntitlementsCache::class)->storeProjectSnapshot(7,$slug,['accessible'=>true,'commercially_entitled'=>true,'subscription_status'=>'active','access_until'=>now()->addMonth()->toIso8601String()],'private-native-return',now());
  $this->assertTrue(app(\App\Services\Integration\FinanceInventoryCapability::class)->allows(7,$org));
  app(\App\Services\Integration\ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
  app(\App\Services\Integration\FinanceOnboardingReadiness::class)->assertComplete($org);
  app(\App\Services\Integration\OrganizationAccountRequirements::class)->assertOperationReady($org,'supplier_return.posted');
  \App\Models\Tenant\IntegrationDocumentLifecycleMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$mapping->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>$db->getDatabaseName(),'source_application'=>'solastock','source_document_type'=>'goods_receipt','source_document_id'=>(string)$receipt->id,'document_version'=>'phase3.v1','lifecycle_status'=>'posted','base_currency_code'=>'JOD']);
  app(IntegrationOutboxService::class)->record('grn.posted',$receipt,'goods_receipt',$receipt->grn_number,$receipt->receipt_date->format('Y-m-d'));
  $this->assertNotNull(app(\App\Services\Purchasing\ReceiptHandoffService::class)->record($receipt));
  config(['services.solabooks.journal_entries_url'=>'https://finance.example.invalid/api/v1/journal-entries']);
  Http::preventStrayRequests();Http::fake(['https://finance.example.invalid/api/v1/purchasing/returns/capabilities'=>function($request)use($mapping,$return,$receipt,$org){
   $payload=$request->data();$this->assertSame(323,$payload['actor_id']);$this->assertSame($return->id,$payload['source_return_id']);$this->assertSame($receipt->id,$payload['source_receipt_id']);
   $this->assertSame($mapping->mapping_uuid,$payload['identity']['organization_mapping_uuid']);
   return Http::response(['data'=>['allowed'=>true,'contract_version'=>'supplier-return.v1','organization_mapping_uuid'=>$mapping->mapping_uuid,'finance_organization_id'=>14,'inventory_organization_id'=>$org,'central_organization_id'=>$org,'actor_id'=>323,'source_return_id'=>$return->id,'source_receipt_id'=>$receipt->id,'supported_branches'=>['unbilled','matched_physical','bridged_unmatched'],'base_currency_code'=>'JOD','consumer_schemas'=>[186,190,193],'operations'=>['supplier_return.posted','supplier_return.reversed']]],200);
  }]);
 }
 public function test_posted_refund_and_prior_unbilled_bridge_remain_closed_after_void():void
 {
  extract($this->source());$this->voidCredit($db);$db->table('supplier_refunds')->insert(['organization_id'=>14,'debit_note_id'=>22,'status'=>'posted']);$this->refused($service,$return);
  $db->table('supplier_refunds')->where('debit_note_id',22)->delete();$db->table('finance_supplier_returns')->where('id',23)->update(['bridge_journal_id'=>32]);$this->refused($service,$return);
 }
 public function test_revoked_mapping_or_foreign_return_projection_cannot_unlock_inverse():void
 {
  extract($this->source());$this->voidCredit($db);$db->table('integration_organization_mappings')->where('id',$mapping->id)->update(['activation_state'=>'paused']);$this->refused($service,$return);
  $db->table('integration_organization_mappings')->where('id',$mapping->id)->update(['activation_state'=>'active']);$db->table('finance_supplier_returns')->where('id',23)->update(['organization_mapping_uuid'=>(string)Str::uuid()]);$this->refused($service,$return);
 }
}
