<?php
namespace Tests\Feature;
use App\Models\Tenant\StockBalance;
use App\Services\Access\{CentralAppAccess,InventoryPermissionService,WarehouseAccessService};
use App\Tenancy\OrganizationContext;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\{DB,Schema,Auth};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Access\AuthorizationException;

class WarehouseRoleScopeTest extends TestCase
{
    private array $decision;
    private object $actor;
    public function createApplication(){
        $app=require __DIR__.'/../../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default'=>'tenant','database.connections.tenant'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],'inventory.demo_tenant.enabled'=>false]);return $app;
    }
    protected function setUp():void {
        parent::setUp();
        $context=new OrganizationContext;$context->set(101);$this->app->instance(OrganizationContext::class,$context);
        Schema::connection('tenant')->create('inventory_user_warehouses',function(Blueprint $t){$t->id();$t->integer('organization_id');$t->integer('user_id');$t->integer('warehouse_id');});
        Schema::connection('tenant')->create('stock_balances',function(Blueprint $t){$t->id();$t->integer('organization_id');$t->integer('warehouse_id');});
        Schema::connection('tenant')->create('inventory_custom_roles',function(Blueprint $t){$t->id();$t->integer('organization_id');$t->string('key');$t->json('permissions');$t->boolean('is_active');});
        Schema::connection('tenant')->create('inventory_user_role_assignments',function(Blueprint $t){$t->id();$t->integer('organization_id');$t->integer('user_id');$t->integer('role_id');});
        DB::connection('tenant')->table('inventory_user_warehouses')->insert([['organization_id'=>101,'user_id'=>7,'warehouse_id'=>11],['organization_id'=>102,'user_id'=>7,'warehouse_id'=>21]]);
        DB::connection('tenant')->table('stock_balances')->insert([['id'=>1,'organization_id'=>101,'warehouse_id'=>11],['id'=>2,'organization_id'=>101,'warehouse_id'=>12],['id'=>3,'organization_id'=>102,'warehouse_id'=>21]]);
        $this->actor=new \Illuminate\Auth\GenericUser(['id'=>7,'central_user_id'=>77]);Auth::setUser($this->actor);request()->setUserResolver(fn()=>$this->actor);
        $this->decision=['allowed'=>true,'roles'=>['stock_manager'],'owner'=>false];
        $access=\Mockery::mock(CentralAppAccess::class);$access->shouldReceive('decision')->andReturnUsing(fn()=>$this->decision);$this->app->instance(CentralAppAccess::class,$access);
        $this->bindPermissions();
    }
    private function bindPermissions():void {
        $service=new class(app(OrganizationContext::class)) extends InventoryPermissionService {
            protected function fetchCentralRole(int $userId,int $orgId):?string{return 'client_member';}
        };$this->app->instance(InventoryPermissionService::class,$service);
    }
    public function test_queries_direct_ids_and_transfers_respect_warehouse_and_organization_assignments():void {
        $this->assertSame([1],StockBalance::pluck('id')->all());$this->assertNull(StockBalance::find(2));$this->assertNull(StockBalance::find(3));
        app(WarehouseAccessService::class)->assertAllowed(11);
        try{app(WarehouseAccessService::class)->assertTransferAllowed(11,12);$this->fail('Unassigned destination accepted');}catch(AuthorizationException){}
        app(OrganizationContext::class)->set(102);$this->assertSame([3],StockBalance::pluck('id')->all());
    }
    public function test_custom_role_restricts_native_actions_and_fresh_deny_wins():void {
        DB::connection('tenant')->table('inventory_custom_roles')->insert(['id'=>1,'organization_id'=>101,'key'=>'custom_counter','permissions'=>json_encode(['inventory.view_stock','inventory.manage_adjustments']),'is_active'=>true]);
        DB::connection('tenant')->table('inventory_user_role_assignments')->insert(['organization_id'=>101,'user_id'=>7,'role_id'=>1]);
        $service=app(InventoryPermissionService::class);
        $this->assertTrue($service->can($this->actor,'inventory.manage_adjustments'));
        $this->assertFalse($service->can($this->actor,'inventory.manage_items'));
        $this->decision['grants']=[['effect'=>'deny','permission_key'=>'inventory.manage_adjustments','scope_type'=>'organization']];
        $this->assertFalse($service->can($this->actor,'inventory.manage_adjustments'));
        $this->decision=['allowed'=>false];$this->assertFalse($service->can($this->actor,'inventory.view_stock'));
    }
    public function test_missing_scope_schema_fails_closed_for_authenticated_user():void {
        Schema::connection('tenant')->drop('inventory_user_warehouses');
        $this->assertSame([],app(WarehouseAccessService::class)->allowedIds());
        $this->assertSame([],StockBalance::pluck('id')->all());
    }

    public function test_signed_export_requires_export_permission_and_only_returns_assigned_warehouse_rows(): void
    {
        foreach (['items', 'warehouses'] as $table) Schema::connection('tenant')->create($table, function (Blueprint $t) {
            $t->id(); $t->integer('organization_id'); $t->string('name'); $t->string('sku')->nullable(); $t->string('code')->nullable(); $t->softDeletes();
        });
        Schema::connection('tenant')->create('stock_ledger', function (Blueprint $t) {
            $t->id(); $t->integer('organization_id'); $t->integer('item_id'); $t->integer('warehouse_id');
            $t->string('direction'); $t->decimal('quantity'); $t->timestamp('moved_at');
        });
        DB::table('items')->insert([['id'=>9,'organization_id'=>101,'name'=>'Scoped item','sku'=>'SCOPE'], ['id'=>10,'organization_id'=>102,'name'=>'Other organization','sku'=>'OTHER']]);
        foreach ([11,12] as $id) DB::table('warehouses')->insert(['id'=>$id,'organization_id'=>101,'name'=>'Warehouse '.$id]);
        foreach ([11,12] as $id) DB::table('stock_ledger')->insert(['id'=>$id,'organization_id'=>101,'item_id'=>9,'warehouse_id'=>$id,'direction'=>'in','quantity'=>$id,'moved_at'=>'2026-09-23 08:00:00']);
        $commercial=\Mockery::mock(\App\Services\Entitlements\InventoryCommercialEntitlementService::class);
        $commercial->shouldReceive('checkPermission')->andReturn(['allowed'=>true,'reason_code'=>'allowed']);
        $this->app->instance(\App\Services\Entitlements\InventoryCommercialEntitlementService::class,$commercial);
        $mapping=new \App\Models\Tenant\IntegrationOrganizationMapping;
        $mapping->forceFill(['central_organization_id'=>101,'solastock_organization_id'=>101]);
        $setting=new \App\Models\Tenant\IntegrationSetting;
        $outer=request(); $outer->setUserResolver(fn()=>$this->actor);
        $dispatch=fn($item=9)=>app(\App\Services\InventoryWorkspace\WorkspaceDispatcher::class)->dispatch($outer,
            ['action'=>'items.movements.export','parameters'=>['item'=>$item]],$mapping,$setting)->getData(true);
        $this->decision['roles']=['scoped_inventory_manager'];
        try { $dispatch(); $this->fail('Manager without export permission exported'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        // Explicit custom export grant, no global warehouse grant.
        $this->decision['roles']=['stock_manager'];
        DB::table('inventory_custom_roles')->insert(['id'=>1,'organization_id'=>101,'key'=>'custom_exporter','permissions'=>json_encode(['inventory.view_ledger','inventory.export_reports']),'is_active'=>true]);
        DB::table('inventory_user_role_assignments')->insert(['organization_id'=>101,'user_id'=>7,'role_id'=>1]);
        $data=$dispatch(); $this->assertSame([11],array_column($data['data'],'warehouse_id'));
        try { $dispatch(10); $this->fail('Cross-organization item exported'); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertTrue(true); }
        DB::table('inventory_user_warehouses')->where('organization_id',101)->delete();
        $this->assertSame([],$dispatch()['data']);
        $this->decision['grants']=[['effect'=>'deny','permission_key'=>'inventory.export_reports','scope_type'=>'organization']];
        try { $dispatch(); $this->fail('Explicit export denial ignored'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
    }
}
