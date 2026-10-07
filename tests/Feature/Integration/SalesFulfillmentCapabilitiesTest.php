<?php
namespace Tests\Feature\Integration;

use App\Models\User;
use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationSetting,InventoryUserWarehouse,Warehouse};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Entitlements\EntitlementsCache;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use App\Services\Sales\FinanceDispatchService;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native permissions, warehouse scope, commercial decision and signed controller.
 * Central access/cache and reserved tenant switching are explicit offline seams.
 */
final class SalesFulfillmentCapabilitiesTest extends TestCase
{
    use TenantAware;
    private array $access=['allowed'=>true,'owner'=>false,'roles'=>['warehouse_operator']];
    private array $snapshot=[];
    private bool $stockAccess=true;
    private Warehouse $warehouse;
    private const SECRET='isolated-capabilities-signature-secret-000000000000';

    private function fixture():void
    {
        $this->useTenantA();
        config(['inventory.demo_tenant.enabled'=>false,'inventory_entitlements.feature_enforcement'=>true,'cache.default'=>'array','finance_workspace.secret'=>self::SECRET]);
        $user=new User;$user->id=335;Auth::setUser($user);request()->setUserResolver(fn()=>$user);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturnUsing(fn($u,$o,$app)=>$app==='inventory'?array_replace($this->access,['allowed'=>$this->stockAccess]):['allowed'=>true,'owner'=>false,'roles'=>[]]);
        $this->snapshot=['accessible'=>true,'commercially_entitled'=>true,'tier'=>'enterprise','access_until'=>now()->addMonth()->toIso8601String(),'allowed_features'=>['stock.sales_fulfillment']];
        $this->mock(EntitlementsCache::class)->shouldReceive('currentClientId')->andReturn(7)->getMock()->shouldReceive('getProjectSnapshot')->andReturnUsing(fn()=>$this->snapshot);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->warehouse=Warehouse::create(['code'=>'CAP-PRIVATE','name'=>'Private warehouse','type'=>'warehouse','is_active'=>true]);
        InventoryUserWarehouse::create(['user_id'=>335,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>335]);
    }
    private function counts():array
    {
        $db=DB::connection('tenant');$result=[];
        foreach(['sales_fulfillment_requests','sales_orders','shipments','reservations','stock_ledger','inventory_audit_logs']as$table){if($db->getSchemaBuilder()->hasTable($table))$result[$table]=$db->table($table)->count();}
        return $result;
    }
    private function caps():array{return app(FinanceDispatchService::class)->capabilities();}
    public function test_native_permissions_and_active_assigned_warehouse_are_independent():void
    {
        $this->fixture();$before=$this->counts();
        $this->assertSame(['can_dispatch_operation'=>true,'can_approve'=>false],$this->caps());
        $this->access['roles']=['scoped_inventory_viewer'];$this->app->forgetInstance(InventoryPermissionService::class);
        $this->assertSame(['can_dispatch_operation'=>false,'can_approve'=>false],$this->caps());
        $this->access['roles']=['warehouse_operator'];$this->app->forgetInstance(InventoryPermissionService::class);
        DB::connection('tenant')->table('inventory_user_warehouses')->where('user_id',335)->delete();
        $this->assertFalse($this->caps()['can_dispatch_operation']);
        InventoryUserWarehouse::create(['user_id'=>335,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>335]);
        DB::connection('tenant')->table('warehouses')->where('id',$this->warehouse->id)->update(['is_active'=>false]);
        $this->assertFalse($this->caps()['can_dispatch_operation']);
        $this->assertSame($before,$this->counts());
    }
    public function test_native_commercial_gate_returns_only_capability_booleans_without_writes():void
    {
        $this->fixture();$before=$this->counts();$this->access=['allowed'=>true,'owner'=>true,'roles'=>[]];$this->app->forgetInstance(InventoryPermissionService::class);
        $this->assertSame(['can_dispatch_operation'=>true,'can_approve'=>true],$this->caps());
        $this->snapshot['blocked_features']=['stock.sales_fulfillment'];
        $this->assertSame(['can_dispatch_operation'=>false,'can_approve'=>false],$this->caps());
        $this->snapshot['blocked_features']=[];$this->snapshot['accessible']=false;
        $this->assertSame(['can_dispatch_operation'=>false,'can_approve'=>false],$this->caps());
        $this->assertSame(['can_dispatch_operation','can_approve'],array_keys($this->caps()));
        $this->assertSame($before,$this->counts());
    }
    private function signed(array $body):\Illuminate\Testing\TestResponse
    {
        $json=json_encode($body,JSON_UNESCAPED_SLASHES);$ts=(string)time();$nonce=bin2hex(random_bytes(24));
        return $this->call('POST',WorkspaceSignature::PATH,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_WORKSPACE_TIMESTAMP'=>$ts,'HTTP_X_WORKSPACE_NONCE'=>$nonce,'HTTP_X_WORKSPACE_SIGNATURE'=>WorkspaceSignature::sign($json,$ts,$nonce,self::SECRET)],$json);
    }
    public function test_actual_signed_capabilities_requires_both_apps_and_rejects_actor_zero():void
    {
        $this->fixture();$db=DB::connection('tenant');$database=$db->getDatabaseName();$org=990010;
        config(['tenancy.central_connection'=>'cap_central','database.connections.cap_central'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);DB::purge('cap_central');$schema=DB::connection('cap_central')->getSchemaBuilder();
        $schema->create('clients',function($t){$t->id();$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('organizations',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('users',function($t){$t->id();$t->unsignedBigInteger('client_id');$t->string('name');$t->string('status')->nullable();$t->timestamp('deleted_at')->nullable();});
        $schema->create('user_organizations',function($t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('organization_id');$t->string('status')->nullable();});
        $schema->create('projects',function($t){$t->id();$t->string('slug');$t->boolean('is_active');});
        $schema->create('organization_projects',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('project_id');$t->boolean('is_active');});
        $central=DB::connection('cap_central');$central->table('clients')->insert(['id'=>7,'is_active'=>true]);$central->table('organizations')->insert(['id'=>$org,'client_id'=>7,'is_active'=>true]);$central->table('users')->insert(['id'=>335,'client_id'=>7,'name'=>'Private capability member','status'=>'active']);$central->table('user_organizations')->insert(['user_id'=>335,'organization_id'=>$org,'status'=>'active']);
        foreach(['finance'=>1,'inventory'=>2]as$slug=>$id){$central->table('projects')->insert(['id'=>$id,'slug'=>$slug,'is_active'=>true]);$central->table('organization_projects')->insert(['organization_id'=>$org,'project_id'=>$id,'is_active'=>true]);}
        $db->table('organizations')->insert(['id'=>14,'central_org_id'=>$org,'setup_status'=>'complete','finance_setup_completed_at'=>now()]);
        IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,'tenant_database_identity'=>$database,'contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD','verified_at'=>now()]);
        IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14]);
        $this->mock(\App\Services\Tenancy\TenantManager::class)->shouldReceive('resolveDatabaseName')->with(7)->andReturn($database)->getMock()->shouldReceive('useTenant')->with($org,$database)->andReturn($database);
        $this->mock(\App\Services\Integration\FinanceOnboardingReadiness::class)->shouldReceive('assertComplete')->andReturnNull();
        $this->mock(\App\Services\Integration\FinanceInventoryCapability::class)->shouldReceive('allows')->with(7,$org)->andReturnTrue();
        $body=['client_id'=>7,'organization_id'=>$org,'finance_organization_id'=>14,'actor_id'=>335,'action'=>'sales.fulfillment.capabilities','data'=>['source_invoice_id'=>987654]];$before=$this->counts();
        $this->signed($body)->assertOk()->assertExactJson(['success'=>true,'data'=>['can_dispatch_operation'=>true,'can_approve'=>false]]);
        $this->stockAccess=false;$this->signed($body)->assertStatus(403);
        $this->stockAccess=true;$bad=$body;$bad['actor_id']=0;$this->signed($bad)->assertStatus(403);
        $this->postJson(WorkspaceSignature::PATH,$body)->assertStatus(403);
        $bad=$body;$bad['organization_id']++;$this->signed($bad)->assertStatus(403);
        $this->assertSame($before,$this->counts());
    }
}
