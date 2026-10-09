<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,GoodsReceipt,Shipment,StockLedger};
use App\Services\Access\CentralAppAccess;
use App\Services\FinancialOrigins\FinancialOriginCapabilities;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\Support\FinancialOriginFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native signed controller and schema introspection; private Central access/tenant switch remain explicit seams. */
final class FinancialOriginCapabilitiesTest extends TestCase
{
    use TenantAware, FinancialOriginFixture;

    private function fixture(): array
    {
        $this->initializeOriginFixture(true);
        $database = DB::connection('tenant')->getDatabaseName(); $org = $this->mapping->central_organization_id;
        config(['finance_workspace.secret'=>str_repeat('s',48), 'cache.default'=>'array', 'tenancy.central_connection'=>'capability_central',
            'database.connections.capability_central'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'integration_safety.financial_origin_expense_handoff_enabled'=>false,
            'integration_safety.financial_origin_cash_handoff_enabled'=>false]);
        DB::purge('capability_central'); $schema = Schema::connection('capability_central');
        $schema->create('clients',function($t){$t->id();$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('organizations',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('users',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->string('name');$t->string('status');$t->timestamp('deleted_at')->nullable();});
        $schema->create('user_organizations',function($t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('organization_id');$t->string('status');});
        $schema->create('projects',function($t){$t->id();$t->string('slug');$t->boolean('is_active');});
        $schema->create('organization_projects',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('project_id');$t->boolean('is_active');});
        $central=DB::connection('capability_central');
        $central->table('clients')->insert(['id'=>7,'is_active'=>true]);$central->table('organizations')->insert(['id'=>$org,'client_id'=>7,'is_active'=>true]);
        $central->table('users')->insert(['id'=>335,'client_id'=>7,'name'=>'Private Finance capability actor','status'=>'active']);
        $central->table('user_organizations')->insert(['user_id'=>335,'organization_id'=>$org,'status'=>'active']);
        foreach(['finance'=>1,'inventory'=>2]as$slug=>$project){$central->table('projects')->insert(['id'=>$project,'slug'=>$slug,'is_active'=>true]);$central->table('organization_projects')->insert(['organization_id'=>$org,'project_id'=>$project,'is_active'=>true]);}
        $this->mock(\App\Services\Tenancy\TenantManager::class)->shouldReceive('resolveDatabaseName')->with(7)->andReturn($database)->getMock()->shouldReceive('useTenant')->with($org,$database)->andReturn($database);
        $this->mock(\App\Services\Integration\FinanceOnboardingReadiness::class)->shouldReceive('assertComplete')->andReturnNull();
        $this->mock(\App\Services\Integration\FinanceInventoryCapability::class)->shouldReceive('allows')->with(7,$org)->andReturnTrue();
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturnUsing(fn($actor,$organization,$slug)=>['allowed'=>$slug==='finance','owner'=>false,'roles'=>[]]);
        return ['client_id'=>7,'organization_id'=>$org,'finance_organization_id'=>14,'actor_id'=>335,'action'=>'financial-origin.capabilities','data'=>['source_document_type'=>'expense']];
    }

    private function signed(array $body, bool $valid=true): \Illuminate\Testing\TestResponse
    {
        $json=json_encode($body,JSON_UNESCAPED_SLASHES);$timestamp=(string)time();$nonce=bin2hex(random_bytes(24));
        $response=$this->call('POST',WorkspaceSignature::PATH,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
            'HTTP_X_WORKSPACE_TIMESTAMP'=>$timestamp,'HTTP_X_WORKSPACE_NONCE'=>$nonce,'HTTP_X_WORKSPACE_SIGNATURE'=>
            $valid?WorkspaceSignature::sign($json,$timestamp,$nonce,str_repeat('s',48)):str_repeat('0',64)],$json);
        if ($response->status()===403) fwrite(STDERR,json_encode(['native_capability_status'=>403,'reason'=>$response->json('error.code') ?? $response->json('message') ?? 'not_exposed']).PHP_EOL);
        return $response;
    }

    public function test_signed_finance_only_probe_is_readonly_and_expense_requires_explicit_gate(): void
    {
        $body=$this->fixture();$before=[FinancialOriginRequest::count(),FinancialOriginCommand::count(),FinancialOriginOutbox::count(),GoodsReceipt::count(),Shipment::count(),StockLedger::count()];
        $this->signed($body)->assertOk()->assertJsonPath('data.schema_ready',true)->assertJsonPath('data.supported_source_document_types',[]);
        config(['integration_safety.financial_origin_expense_handoff_enabled'=>true]);
        $response=$this->signed($body)->assertOk()->assertJsonPath('data.supported_source_document_types',['expense']);
        $this->assertSame(['contract_version','supported_source_document_types','cash_schema_ready','cash_contract_version','schema_ready','finance_core_version','stock_core_version','organization_mapping_uuid','central_client_id','central_organization_id','finance_organization_id','solastock_organization_id'],array_keys($response->json('data')));
        $response->assertJsonPath('data.cash_schema_ready',true)->assertJsonPath('data.cash_contract_version',\App\Services\Integration\Cash219SchemaReadiness::VERSION);
        $response->assertJsonPath('data.contract_version',FinancialOriginCapabilities::CONTRACT)->assertJsonPath('data.finance_core_version',FinancialOriginCapabilities::CORE_VERSION);
        $this->assertSame($before,[FinancialOriginRequest::count(),FinancialOriginCommand::count(),FinancialOriginOutbox::count(),GoodsReceipt::count(),Shipment::count(),StockLedger::count()]);
        $body['data']['source_document_type']='sales_receipt';$this->signed($body)->assertOk()->assertJsonPath('data.supported_source_document_types',['expense']);
        config(['integration_safety.financial_origin_expense_handoff_enabled'=>false,
            'integration_safety.financial_origin_cash_handoff_enabled'=>false]);$this->signed($body)->assertOk()->assertJsonPath('data.supported_source_document_types',[]);
    }

    public function test_probe_rejects_actor_zero_foreign_identity_forged_signature_and_caller_economics(): void
    {
        $body=$this->fixture();$before=StockLedger::count();$wrong=$body;$wrong['actor_id']=0;$this->signed($wrong)->assertStatus(403);
        $wrong=$body;$wrong['finance_organization_id']=15;$this->signed($wrong)->assertStatus(403);
        $this->signed($body,false)->assertStatus(403);
        $wrong=$body;$wrong['data']['unit_price']='1';$this->signed($wrong)->assertStatus(422);
        $wrong=$body;$wrong['action']='financial-origin.dispatch.prepare';$this->signed($wrong)->assertStatus(403); // Metadata admission never grants Stock access.
        $this->assertSame(0,FinancialOriginRequest::count());$this->assertSame($before,StockLedger::count());
    }
    #[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
    public function test_missing_required_reverse_index_fails_closed_even_with_deployment_gate(): void
    {
        $body=$this->fixture();config(['integration_safety.financial_origin_expense_handoff_enabled'=>true]);
        $committedFixture=new \Tests\Support\CommittedTenantFixture($this->tenantTestManager);
        try { $committedFixture->commit();
        $this->signed($body)->assertOk()->assertJsonPath('data.schema_ready',true);
        Schema::connection('tenant')->table('finance_document_reverse_generations',fn($table)=>$table->dropUnique('fin_origin_reverse_uuid_unique'));
        $this->signed($body)->assertOk()->assertJsonPath('data.schema_ready',false)->assertJsonPath('data.supported_source_document_types',[]);
        Schema::connection('tenant')->table('finance_document_reverse_generations',fn($table)=>$table->index('reversal_operation_uuid','fin_origin_reverse_uuid_unique'));
        $this->signed($body)->assertOk()->assertJsonPath('data.schema_ready',false); // Same name, wrong uniqueness.
        Schema::connection('tenant')->table('finance_document_reverse_generations',fn($table)=>$table->dropIndex('fin_origin_reverse_uuid_unique'));
        $finance=rtrim((string)env('FINANCIAL_ORIGIN_FINANCE_SOURCE','/qualification/finance'),'/');
        (require $finance.'/database/migrations/finance/2026_10_07_192000_create_financial_origin_reverse_generations.php')->up();
        $this->signed($body)->assertOk()->assertJsonPath('data.schema_ready',true)->assertJsonPath('data.supported_source_document_types',['expense']);
        } finally { $committedFixture->restore(); }
    }

    /** Native Stock GRN/ledger and command recovery; Finance source authorization/facts remain explicit projections. */
    public function test_disabling_gate_blocks_new_physical_effects_but_keeps_accepted_replay_status_and_cancel(): void
    {
        $this->fixture();config(['integration_safety.financial_origin_expense_handoff_enabled'=>true,'inventory.demo_tenant.enabled'=>false]);
        $actor=new \App\Models\User;$actor->id=323;\Illuminate\Support\Facades\Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);
        $this->app->forgetInstance(\App\Services\Access\InventoryPermissionService::class);
        $supplier=\App\Models\Tenant\Supplier::create(['code'=>'PRIVATE-CAP-EXPENSE','name'=>'Private Expense receiver supplier','is_active'=>true]);$this->master('supplier',$supplier->id,704);
        $db=DB::connection('tenant');$db->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
        $account=\App\Models\Tenant\IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$account->id,300);
        $data=$this->typed('expense');
        $db->table('finance_document_requests')->insert(['organization_id'=>14,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'request_uuid'=>$data['request_uuid'],
            'side'=>'purchase','source_document_type'=>'expense','source_document_id'=>850,'source_journal_id'=>95,'source_revision'=>$data['source_revision'],'actor_id'=>323,'payload'=>json_encode($data)]);
        $this->proof($data);$service=app(\App\Services\FinancialOrigins\OriginRequestService::class);$accepted=$service->upsert($data,323);
        $context=array_intersect_key($data,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$data['source_revision']];
        $service->approveNative($context+['warehouse_id'=>$this->warehouse->id],323);
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->update(['meta'=>$meta]);
        $operation=$context+['operation_uuid'=>(string)\Illuminate\Support\Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07',
            'lines'=>[['request_line_id'=>$accepted['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]];
        $dispatch=app(\App\Services\FinancialOrigins\OriginDispatchService::class);$completed=$dispatch->executeNative($operation,323);
        $pending=$operation;$pending['operation_uuid']=(string)\Illuminate\Support\Str::uuid();$pending['lines'][0]['quantity']='1';
        $dispatch->prepareNative($pending,323); // Accepted before disable, exact same actor and payload retained.
        $effects=[GoodsReceipt::count(),StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginCommand::count()];
        config(['integration_safety.financial_origin_expense_handoff_enabled'=>false,
            'integration_safety.financial_origin_cash_handoff_enabled'=>false]);
        $this->assertSame($completed,$dispatch->executeNative($operation,323));$this->assertSame('completed',$dispatch->statusNative($context+['operation_uuid'=>$operation['operation_uuid']],323)['status']);
        $next=$operation;$next['operation_uuid']=(string)\Illuminate\Support\Str::uuid();
        try{$dispatch->prepareNative($next,323);$this->fail('Disabled source created a physical command');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$this->assertSame(409,$error->getStatusCode());}
        $this->assertSame($effects,[GoodsReceipt::count(),StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginCommand::count()]);
        $dispatch->executeNative($pending,323); // Finish the already accepted receipt; never invent a fresh operation.
        $this->assertSame('completed',$dispatch->statusNative($context+['operation_uuid'=>$pending['operation_uuid']],323)['status']);
        $effects=[GoodsReceipt::count(),StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginCommand::count()];
        $this->proof($data,'cancel');$cancel=$service->cancel($data+['command'=>'cancel','expected_revision'=>$data['source_revision']],323);
        $this->assertSame('cancelled',$cancel['status']);$this->assertSame('3.0000',FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity);
        $this->assertSame($effects,[GoodsReceipt::count(),StockLedger::count(),FinancialOriginOutbox::count(),FinancialOriginCommand::count()]);
    }
}
