<?php

namespace Tests\Feature\Warehouses;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\InventoryUserWarehouse;
use App\Models\Tenant\Warehouse;
use App\Services\Access\CentralAppAccess;
use App\Services\Warehouses\DefaultWarehouseService;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\StockTestFactory;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class DefaultWarehouseProvisioningTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantAware();
        $this->useTenantA();
    }

    protected function tearDown(): void
    {
        $this->rollbackTenantAware();
        parent::tearDown();
    }

    public function test_empty_organization_initializes_once_without_stock_or_assignments(): void
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $service = app(DefaultWarehouseService::class);
        $plan = $service->ensure($org, false);
        $this->assertSame('would_create', $plan['status']);
        $this->assertSame(0, Warehouse::count());
        $first = $service->ensure($org);
        $second = $service->ensure($org);
        $this->assertSame('created', $first['status']);
        $this->assertSame('existing_default', $second['status']);
        $this->assertSame($first['default_warehouse_id'], $second['default_warehouse_id']);
        $this->assertSame(1, Warehouse::count());
        $this->assertTrue($first['active_warehouse_available']);
        $this->assertSame(0, InventoryUserWarehouse::count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledger')->count());
        $this->assertSame(0, DB::connection('tenant')->table('purchasing_document_outbox')->count());
    }

    public function test_editing_default_name_and_code_preserves_reference_and_customer_changes(): void
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $service = app(DefaultWarehouseService::class);
        $id = $service->ensure($org)['default_warehouse_id'];
        Warehouse::findOrFail($id)->update(['name' => 'المستودع المعدّل', 'code' => 'CUSTOM']);
        $this->assertSame($id, $service->ensure($org)['default_warehouse_id']);
        $this->assertSame('المستودع المعدّل', Warehouse::findOrFail($id)->name);
        $this->assertSame('CUSTOM', Warehouse::findOrFail($id)->code);
        $this->assertSame(1, Warehouse::count());
    }

    public function test_existing_inactive_or_deleted_warehouse_is_never_replaced(): void
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $warehouse = StockTestFactory::warehouse(['is_active' => false]);
        $service = app(DefaultWarehouseService::class);
        $result = $service->ensure($org);
        $this->assertSame('existing_warehouses_preserved', $result['status']);
        $this->assertNull($result['default_warehouse_id']);
        $this->assertFalse($result['active_warehouse_available']);
        $warehouse->delete();
        $this->assertSame('existing_warehouses_preserved', $service->ensure($org)['status']);
        $this->assertSame(1, Warehouse::withTrashed()->count());
        $this->assertSame(0, Warehouse::count());
    }

    public function test_manual_first_warehouse_is_default_and_cannot_change_organization(): void
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $service = app(DefaultWarehouseService::class);
        $warehouse = $service->create(['organization_id' => 888888, 'name' => 'Chosen warehouse', 'code' => 'MY-WH', 'is_active' => true]);
        $this->assertSame($org, (int) $warehouse->organization_id);
        $this->assertSame((int) $warehouse->id, (int) InventorySetting::first()->default_warehouse_id);
        $this->assertSame('existing_default', $service->ensure($org)['status']);
        $this->assertSame(1, Warehouse::count());
    }

    public function test_default_selection_respects_native_warehouse_assignments_and_tenant_scope(): void
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $service = app(DefaultWarehouseService::class);
        $id = $service->ensure($org)['default_warehouse_id'];
        Auth::login(new GenericUser(['id' => 943]));
        // Canonical app admission is the remote boundary; native resource rows/scopes are real.
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->with(943, $org, 'inventory')->andReturn(['allowed' => true, 'owner' => false, 'roles' => []]);
        $this->assertNull($service->authorizedId());
        InventoryUserWarehouse::create(['user_id' => 943, 'warehouse_id' => $id, 'assigned_by' => 943]);
        $this->assertSame($id, $service->authorizedId());
        Warehouse::findOrFail($id)->update(['is_active' => false]);
        $this->assertNull($service->authorizedId());
        Auth::logout();
        $this->useTenantB();
        $this->assertNull($service->authorizedId());
        $this->assertSame(0, Warehouse::count());
    }
}
