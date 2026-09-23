<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ReportController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\InventoryScheduledReport;
use App\Models\Tenant\StockBalance;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\OperationalReceiving;
use App\Services\Access\WarehouseAccessService;
use App\Services\Tenancy\LiveTenantResolver;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationalInventoryRolesTest extends TestCase
{
    private array $decision;

    private object $actor;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['database.default' => 'tenant', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'inventory.demo_tenant.enabled' => false]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $context = new OrganizationContext;
        $context->set(101);
        $this->app->instance(OrganizationContext::class, $context);
        Schema::create('inventory_user_warehouses', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('warehouse_id');
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('warehouse_id');
        });
        Schema::create('inventory_custom_roles', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->string('key');
            $t->json('permissions');
            $t->boolean('is_active');
        });
        Schema::create('inventory_user_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('role_id');
        });
        (require database_path('migrations/tenant/2026_09_22_230000_create_inventory_operational_role_sets.php'))->up();
        DB::table('inventory_user_warehouses')->insert([['organization_id' => 101, 'user_id' => 7, 'warehouse_id' => 11], ['organization_id' => 102, 'user_id' => 7, 'warehouse_id' => 21]]);
        DB::table('stock_balances')->insert([['id' => 1, 'organization_id' => 101, 'warehouse_id' => 11], ['id' => 2, 'organization_id' => 101, 'warehouse_id' => 12], ['id' => 3, 'organization_id' => 102, 'warehouse_id' => 21]]);
        $this->actor = new GenericUser(['id' => 7, 'central_user_id' => 7]);
        Auth::setUser($this->actor);
        request()->setUserResolver(fn () => $this->actor);
        $this->decision = ['allowed' => true, 'roles' => ['warehouse_operator'], 'owner' => false];
        $access = \Mockery::mock(CentralAppAccess::class);
        $access->shouldReceive('decision')->andReturnUsing(fn () => $this->decision);
        $this->app->instance(CentralAppAccess::class, $access);
    }

    public function test_operator_actions_exclude_administration_catalog_valuation_adjustments_and_overrides(): void
    {
        $p = app(InventoryPermissionService::class);
        foreach (['receive_goods', 'transfer_stock', 'manage_picking', 'manage_packing', 'manage_shipments', 'manage_reservations'] as $action) {
            $this->assertTrue($p->can($this->actor, 'inventory.'.$action), $action);
        }
        foreach (['manage_settings', 'manage_items', 'manage_warehouses', 'manage_adjustments', 'manage_opening_stock', 'override_quarantine', 'override_expired_lot', 'integration.setup'] as $action) {
            $this->assertFalse($p->can($this->actor, 'inventory.'.$action), $action);
        }
    }

    public function test_both_transfer_warehouses_queries_and_direct_ids_are_scoped(): void
    {
        $this->assertSame([1], StockBalance::pluck('id')->all());
        $this->assertNull(StockBalance::find(2));
        $this->assertNull(StockBalance::find(3));
        app(WarehouseAccessService::class)->assertAllowed(11);
        foreach ([[11, 12], [12, 11], [11, 21]] as [$from,$to]) {
            try {
                app(WarehouseAccessService::class)->assertTransferAllowed($from, $to);
                $this->fail('Unassigned warehouse allowed');
            } catch (AuthorizationException) {
            }
        }
        app(OrganizationContext::class)->set(102);
        $this->assertSame([3], StockBalance::pluck('id')->all());
        DB::table('inventory_user_warehouses')->where('organization_id', 102)->delete();
        $this->assertSame([], StockBalance::pluck('id')->all());
    }

    public function test_readonly_existing_and_new_viewers_remain_readonly(): void
    {
        foreach (['warehouse_user', 'scoped_inventory_viewer'] as $role) {
            $this->decision['roles'] = [$role];
            $p = app(InventoryPermissionService::class);
            $this->assertTrue($p->can($this->actor, 'inventory.view_stock'));
            foreach (['receive_goods', 'transfer_stock', 'manage_shipments', 'manage_items', 'manage_settings'] as $action) {
                $this->assertFalse($p->can($this->actor, 'inventory.'.$action));
            }
        }
    }

    public function test_missing_native_definition_revocation_and_explicit_denial_fail_closed(): void
    {
        $p = app(InventoryPermissionService::class);
        $this->assertTrue($p->can($this->actor, 'inventory.receive_goods'));
        $this->decision['grants'] = [['effect' => 'deny', 'permission_key' => 'inventory.receive_goods', 'scope_type' => 'organization']];
        $this->assertFalse($p->can($this->actor, 'inventory.receive_goods'));
        $this->decision = ['allowed' => false];
        $this->assertFalse($p->can($this->actor, 'inventory.view_stock'));
        $this->assertSame([], StockBalance::pluck('id')->all());
        $this->decision = ['allowed' => true, 'roles' => ['warehouse_operator']];
        DB::table('inventory_operational_role_sets')->where('role_key', 'warehouse_operator')->delete();
        $this->assertFalse($p->can($this->actor, 'inventory.receive_goods'));
    }

    public function test_repeat_provision_preserves_customized_native_and_custom_roles(): void
    {
        DB::table('inventory_operational_role_sets')->where('role_key', 'warehouse_operator')->update(['permissions' => '["inventory.view_stock"]']);
        (require database_path('migrations/tenant/2026_09_22_230000_create_inventory_operational_role_sets.php'))->up();
        $p = app(InventoryPermissionService::class);
        $this->assertTrue($p->can($this->actor, 'inventory.view_stock'));
        $this->assertFalse($p->can($this->actor, 'inventory.receive_goods'));
        $this->assertSame(4, DB::table('inventory_operational_role_sets')->count());
    }

    public function test_receiving_requires_approved_scoped_order_and_preserves_base_valuation(): void
    {
        Schema::create('inventory_purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('warehouse_id');
            $t->integer('supplier_id')->nullable();
            $t->string('status');
            $t->softDeletes();
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('purchase_order_id');
            $t->integer('item_id');
            $t->decimal('unit_price', 12, 4);
            $t->integer('entered_unit_id')->nullable();
            $t->decimal('unit_conversion_factor', 12, 4);
        });
        DB::table('inventory_purchase_orders')->insert(['id' => 1, 'organization_id' => 101, 'warehouse_id' => 11, 'status' => 'approved', 'supplier_id' => 5]);
        DB::table('purchase_order_lines')->insert(['id' => 1, 'organization_id' => 101, 'purchase_order_id' => 1, 'item_id' => 9, 'unit_price' => 3, 'entered_unit_id' => 2, 'unit_conversion_factor' => 10]);
        $service = app(OperationalReceiving::class);
        $data = ['purchase_order_id' => 1, 'warehouse_id' => 11, 'supplier_id' => 999, 'lines' => [['purchase_order_line_id' => 1, 'item_id' => 9, 'unit_cost' => '30', 'entered_unit_id' => 999]]];
        $safe = $service->prepare($data);
        $this->assertSame(5, $safe['supplier_id']);
        $this->assertSame('30.0000', $safe['lines'][0]['unit_cost']);
        $this->assertSame(2, $safe['lines'][0]['entered_unit_id']);
        $receipt = (new GoodsReceipt)->forceFill(['purchase_order_id' => 1, 'warehouse_id' => 11]);
        $receipt->setRelation('lines', collect([(new GoodsReceiptLine)->forceFill(['purchase_order_line_id' => 1, 'item_id' => 9, 'unit_cost' => 3, 'unit_conversion_factor' => 10])]));
        $service->posting($receipt);
        foreach (['cost', 'warehouse', 'unapproved'] as $bad) {
            $test = $data;
            if ($bad === 'cost') {
                $test['lines'][0]['unit_cost'] = '31';
            }if ($bad === 'warehouse') {
                $test['warehouse_id'] = 12;
            }if ($bad === 'unapproved') {
                DB::table('inventory_purchase_orders')->where('id', 1)->update(['status' => 'draft']);
            }
            try {
                $service->prepare($test);
                $this->fail('Invalid receipt accepted: '.$bad);
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_custom_role_name_collision_and_disabling_never_expand_permissions(): void
    {
        DB::table('inventory_custom_roles')->insert(['id' => 1, 'organization_id' => 101, 'key' => 'warehouse_operator', 'permissions' => '["inventory.view_stock"]', 'is_active' => true]);
        DB::table('inventory_user_role_assignments')->insert(['organization_id' => 101, 'user_id' => 7, 'role_id' => 1]);
        $p = app(InventoryPermissionService::class);
        $this->assertTrue($p->can($this->actor, 'inventory.view_stock'));
        $this->assertFalse($p->can($this->actor, 'inventory.receive_goods'));
        DB::table('inventory_custom_roles')->where('id', 1)->update(['is_active' => false]);
        $this->assertFalse($p->can($this->actor, 'inventory.view_stock'));
        $this->assertFalse($p->can($this->actor, 'inventory.receive_goods'));
    }

    public function test_scoped_manager_cannot_read_create_update_or_run_unscoped_scheduled_reports(): void
    {
        $this->decision['roles'] = ['scoped_inventory_manager'];
        $controller = app(ReportController::class);
        foreach (['schedules', 'storeSchedule', 'updateSchedule', 'runSchedule'] as $method) {
            $request = Request::create('/reports/schedules', 'POST');
            $schedule = new InventoryScheduledReport;
            $args = match ($method) {
                'schedules' => [],'storeSchedule' => [$request],'updateSchedule' => [$request, $schedule],'runSchedule' => [$schedule]
            };
            try {
                $controller->$method(...$args);
                $this->fail('Scheduled report escaped warehouse scope');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertTrue(app(InventoryPermissionService::class)->can($this->actor, 'inventory.export_reports'));
    }

    public function test_signed_management_uses_explicit_actor_not_ambient_session(): void
    {
        Auth::forgetGuards();
        request()->setUserResolver(fn () => null);
        $this->decision = ['allowed' => true, 'owner' => true];
        $this->assertNull(app(WarehouseAccessService::class)->allowedIds(7));
        $this->decision = ['allowed' => false];
        $this->assertSame([], app(WarehouseAccessService::class)->allowedIds(7));
    }

    public function test_app_admission_does_not_query_native_roles_before_tenant_binding(): void
    {
        Schema::drop('inventory_operational_role_sets');
        $resolver = \Mockery::mock(LiveTenantResolver::class)->makePartial();
        $resolver->shouldReceive('clientId')->andReturn(87);
        $resolver->shouldReceive('organizationId')->andReturn(101);
        $resolver->shouldReceive('tenantDatabase')->with(87)->andReturn('tenant_000087');
        $resolver->shouldReceive('inventoryEnabledForOrg')->with(101)->andReturn(true);
        $state = $resolver->state(request());
        $this->assertSame('live_ready', $state['state']);
        $this->assertTrue($state['can_access']);
        $this->decision['allowed'] = false;
        $state = $resolver->state(request());
        $this->assertSame('no_access', $state['state']);
    }
}
