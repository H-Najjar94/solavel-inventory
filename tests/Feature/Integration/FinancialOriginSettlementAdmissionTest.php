<?php
namespace Tests\Feature\Integration;

use App\Models\User;
use App\Models\Tenant\{FinancialOriginOutbox,GoodsReceipt,IntegrationAccountMapping,IntegrationSetting,InventoryUserWarehouse,StockLedger,Supplier};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\FinancialOrigins\{OriginDispatchService,OriginReceiptCostAuthority,OriginRequestService};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\{PurchaseCostAdjustmentPlanner,PurchaseCostAdjustmentService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Tests\Support\FinancialOriginFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Actual signed middleware/controller/Held dispatch; remote Finance/commercial/reserved tenant switch are explicit seams. */
final class FinancialOriginSettlementAdmissionTest extends TestCase
{
    use TenantAware,FinancialOriginFixture;

    private function actor(int $id,bool $owner=false):void
    {
        config(['inventory.demo_tenant.enabled'=>false]);$u=new User;$u->id=$id;Auth::setUser($u);request()->setUserResolver(fn()=>$u);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>$owner,'roles'=>$owner?[]:['warehouse_operator']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
    }
    private function fixture():array
    {
        $this->useTenantA();$db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        if(!$schema->hasTable('expenses'))$schema->create('expenses',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('vendor_id')->nullable();});
        if(!$schema->hasTable('finance_document_requests'))$schema->create('finance_document_requests',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        (require base_path('tests/Support/FinancialOriginCostProjectionSchema.php'))->up();
        (require base_path('database/migrations/tenant/2026_10_07_081000_create_purchase_valuation_holds.php'))->up();
        (require base_path('database/migrations/tenant/2026_10_07_188000_add_financial_origin_valuation_hold_identity.php'))->up();
        $add=function($table,$name,$callback)use($schema){if(!$schema->hasColumn($table,$name))$schema->table($table,fn($t)=>$callback($t,$name));};
        $add('expenses','journal_entry_id',fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        $add('expenses','posted_at',fn($t,$n)=>$t->timestamp($n)->nullable());
        $add('expenses','status',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','state',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','response',fn($t,$n)=>$t->json($n)->nullable());
        $add('journal_entries','source_key',fn($t,$n)=>$t->string($n)->nullable());
        $add('journal_entries','reverses_entry_id',fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        // DDL commits native fixture transactions; reacquire tenant context before all business fixture rows.
        $this->tenantTestManager->cleanup();$this->initializeOriginFixture(true);$db=DB::connection('tenant');
        $s=Supplier::create(['code'=>'QA-COST-EXPENSE','name'=>'QA cost Expense','is_active'=>true]);$this->master('supplier',$s->id,704);
        $db->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
        $a=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$a->id,300);
        $d=$this->typed('expense');$this->proof($d);$r=app(OriginRequestService::class)->upsert($d,323);
        $context=array_intersect_key($d,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$d['source_revision']];
        $this->actor(323,true);app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],323);
        InventoryUserWarehouse::create(['user_id'=>336,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);$this->actor(336);
        $setting=IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->meta=$meta;$setting->save();
        app(OriginDispatchService::class)->executeNative($context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07','lines'=>[['request_line_id'=>$r['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]],336);
        $db=DB::connection('tenant');$event=FinancialOriginOutbox::sole()->payload;$physical=$event['physical'];$line=$physical['lines'][0];$grn=GoodsReceipt::sole();$native=$grn->lines()->sole();
        $db->table('expenses')->where('id',850)->update(['journal_entry_id'=>95,'posted_at'=>now(),'status'=>'posted']);
        $db->table('journal_entries')->where('id',95)->update(['posted_at'=>now()]);
        $db->table('journal_entries')->insert(['id'=>96,'organization_id'=>14,'source_type'=>'App\\Models\\StockJournal','source_id'=>$grn->id,'source_key'=>'external-api:'.hash('sha256',$physical['journal_key']),'status'=>'posted','posted_at'=>now()]);
        $position=(string)Str::uuid();$operation=(string)Str::uuid();$ledger=StockLedger::findOrFail($line['stock_ledger_id']);
        $snapshot=['finance_money_scale'=>2,'source'=>['type'=>'expense','id'=>850,'journal_id'=>95,'line_id'=>851,'quantity'=>'4','net_amount'=>'40','nonrecoverable_tax_amount'=>'0','booked_acquisition_base'=>'40','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1'],
            'receipt'=>['mapping_uuid'=>$physical['mapping_uuid'],'id'=>$grn->id,'line_id'=>$native->id,'imported_journal_id'=>96,'journal_key'=>$physical['journal_key'],'journal_event_uuid'=>$physical['journal_event_uuid'],'journal_payload_hash'=>$physical['journal_payload_hash'],'quantity'=>'2','base_quantity'=>'2','unit_conversion_factor'=>(string)$native->unit_conversion_factor,'unit_conversion_hash'=>$native->unit_conversion_hash,'unit_cost'=>'7','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1','stock_ledger_id'=>$ledger->id,'stock_money_scale'=>2,'source_quantity_base'=>(string)$ledger->quantity,'source_value_base'=>(string)$ledger->total_cost,'original_receipt_base_amount'=>(string)$ledger->total_cost],
            'price'=>['desired_acquisition_at_receipt_base'=>'20','price_delta_base'=>'6','booked_fx_difference_base'=>'0']];
        $db->table('finance_document_positions')->insert(['organization_id'=>14,'request_uuid'=>$d['request_uuid'],'position_uuid'=>$position,'side'=>'purchase','source_document_type'=>'expense','source_document_id'=>850,'source_journal_id'=>95,'source_document_line_id'=>851,'inventory_item_id'=>701,'entered_unit_id'=>702,'quantity'=>'4','booked_net_base'=>'40','booked_acquisition_base'=>'40','holding_account_id'=>300,'destination_account_id'=>100,'snapshot'=>json_encode($snapshot['source'])]);
        $db->table('finance_document_matches')->insert(['organization_id'=>14,'request_uuid'=>$d['request_uuid'],'operation_uuid'=>$operation,'position_uuid'=>$position,'physical_mapping_uuid'=>$physical['mapping_uuid'],'physical_document_id'=>$grn->id,'physical_line_id'=>$native->id,'source_document_line_id'=>851,'physical_journal_id'=>96,'quantity'=>'2','base_quantity'=>'2','unit_conversion_factor'=>$native->unit_conversion_factor,'unit_conversion_hash'=>$native->unit_conversion_hash,'booked_base'=>'20','physical_cost_base'=>$ledger->total_cost,'state'=>'journal_pending','snapshot'=>json_encode($snapshot)]);
        $id=['source_document_id'=>850,'source_journal_id'=>95,'request_uuid'=>$d['request_uuid'],'source_revision'=>$d['source_revision'],'operation_uuid'=>$operation,'position_uuid'=>$position,'direction'=>'forward'];
        $proof=$id+['allowed'=>true,'schema_version'=>'financial-origin.v1','authority_kind'=>'posted_financial_origin_settlement','source_document_type'=>'expense','finance_organization_id'=>14,'central_organization_id'=>$this->mapping->central_organization_id,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'operation'=>'prepare','actor_id'=>0,'match_state'=>'journal_pending','snapshot_hash'=>SolaStockJournalContract::payloadHash($snapshot),'finance_money_scale'=>2,'source_document_line_id'=>851,'value_plan_hash'=>null];
        return [$id,$proof,$snapshot];
    }

    private function signed(array $body):\Illuminate\Testing\TestResponse
    {
        $json=json_encode($body,JSON_UNESCAPED_SLASHES);$timestamp=(string)time();$nonce=bin2hex(random_bytes(24));
        return $this->call('POST',\App\Services\InventoryWorkspace\WorkspaceSignature::PATH,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_WORKSPACE_TIMESTAMP'=>$timestamp,'HTTP_X_WORKSPACE_NONCE'=>$nonce,'HTTP_X_WORKSPACE_SIGNATURE'=>\App\Services\InventoryWorkspace\WorkspaceSignature::sign($json,$timestamp,$nonce,str_repeat('s',48))],$json);
    }
    private function admissionFixture():array
    {
        [$id,$proof]=$this->fixture();$database=DB::connection('tenant')->getDatabaseName();$org=$this->mapping->central_organization_id;
        config(['finance_workspace.secret'=>str_repeat('s',48),'cache.default'=>'array','tenancy.central_connection'=>'cost_central','database.connections.cost_central'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('cost_central');$schema=DB::connection('cost_central')->getSchemaBuilder();
        $schema->create('clients',function($t){$t->id();$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('organizations',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('users',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->string('name');$t->string('status')->nullable();$t->timestamp('deleted_at')->nullable();});
        $schema->create('user_organizations',function($t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('organization_id');$t->string('status')->nullable();});
        $schema->create('projects',function($t){$t->id();$t->string('slug');$t->boolean('is_active');});
        $schema->create('organization_projects',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('project_id');$t->boolean('is_active');});
        $central=DB::connection('cost_central');$central->table('clients')->insert(['id'=>7,'is_active'=>true]);$central->table('organizations')->insert(['id'=>$org,'client_id'=>7,'is_active'=>true]);
        $central->table('users')->insert(['id'=>335,'client_id'=>7,'name'=>'Finance-only native fixture actor','status'=>'active']);$central->table('user_organizations')->insert(['user_id'=>335,'organization_id'=>$org,'status'=>'active']);
        foreach(['finance'=>1,'inventory'=>2]as$slug=>$project){$central->table('projects')->insert(['id'=>$project,'slug'=>$slug,'is_active'=>true]);$central->table('organization_projects')->insert(['organization_id'=>$org,'project_id'=>$project,'is_active'=>true]);}
        $this->mock(\App\Services\Tenancy\TenantManager::class)->shouldReceive('resolveDatabaseName')->with(7)->andReturn($database)->getMock()->shouldReceive('useTenant')->with($org,$database)->andReturn($database);
        $this->mock(\App\Services\Integration\FinanceOnboardingReadiness::class)->shouldReceive('assertComplete')->andReturnNull();
        $this->mock(\App\Services\Integration\FinanceInventoryCapability::class)->shouldReceive('allows')->with(7,$org)->andReturnTrue();
        $this->mock(\App\Services\Entitlements\EntitlementsCache::class)->shouldReceive('getProjectSnapshot')->andReturn([]);
        $this->mock(\App\Services\Entitlements\EntitlementAccessDecision::class)->shouldReceive('decide')->andReturn(['reason'=>\App\Services\Entitlements\EntitlementAccessDecision::DENY_NOT_IN_PLAN]);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturnUsing(fn($actor,$organization,$slug)=>['allowed'=>$slug==='finance','owner'=>false,'roles'=>[]]);
        $this->mock(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOriginSettlement')->andReturnUsing(function($facts,$operation,$actor)use($proof,$org){
            $this->assertSame(['client_id'=>7,'organization_id'=>$org],request()->attributes->get('tenant_state'));
            return array_replace($proof,['operation'=>$operation,'actor_id'=>$actor]);
        });
        return ['client_id'=>7,'organization_id'=>$org,'finance_organization_id'=>14,'actor_id'=>0,'authority_kind'=>'posted_financial_origin_settlement','action'=>'financial-origin.settlement.prepare','data'=>$id];
    }
    public function test_actual_signed_service_admission_reaches_native_factory_and_rejects_foreign_identity_and_scope():void
    {
        $body=$this->admissionFixture();$ledgers=StockLedger::count();$quantity=\App\Models\Tenant\StockBalance::sole()->on_hand_qty;
        $response=$this->signed($body);$response->assertOk()->assertJsonPath('data.state','prepared');
        $this->assertSame(1,\App\Models\Tenant\PurchaseValuationHold::count());$this->assertSame($ledgers,StockLedger::count());$this->assertSame($quantity,\App\Models\Tenant\StockBalance::sole()->on_hand_qty);
        $this->assertFalse(request()->attributes->has('tenant_state'));
        $foreign=$body;$foreign['data']['source_document_id']=851;$this->signed($foreign)->assertStatus(403);
        $wrong=$body;$wrong['client_id']=8;$this->signed($wrong)->assertStatus(403);
        $wrong=$body;$wrong['organization_id']++;$this->signed($wrong)->assertStatus(403);
        $this->assertSame(1,\App\Models\Tenant\PurchaseValuationHold::count());$this->assertSame($ledgers,StockLedger::count());
    }
    public function test_signed_value_scope_never_grants_actor_zero_reverse_or_finance_only_physical_access():void
    {
        $body=$this->admissionFixture();$ledgers=StockLedger::count();
        $this->postJson(\App\Services\InventoryWorkspace\WorkspaceSignature::PATH,$body)->assertStatus(403);
        $bad=$body;$bad['authority_kind']='posted_purchase_settlement';$this->signed($bad)->assertStatus(403);
        $bad=$body;$bad['action']='financial-origin.settlement.reverse';$bad['data']['direction']='reverse';$this->signed($bad)->assertStatus(403);
        $bad=$body;$bad['action']='sales.fulfillment.execute';$this->signed($bad)->assertStatus(403);
        $bad=$body;$bad['actor_id']=335;$bad['action']='warehouses.store';$this->signed($bad)->assertStatus(403);
        $this->assertFalse(\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::covers('warehouses.store'));
        $this->assertFalse(\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::covers('sales.fulfillment.execute'));
        $this->assertSame(0,\App\Models\Tenant\PurchaseValuationHold::count());$this->assertSame($ledgers,StockLedger::count());
    }
}
