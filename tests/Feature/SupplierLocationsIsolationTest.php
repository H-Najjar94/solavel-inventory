<?php

namespace Tests\Feature;

use App\Models\Tenant\Supplier;
use App\Services\Purchasing\SupplierTerms\FinanceLink;
use App\Services\Purchasing\SupplierTerms\SupplierLocations;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierLocationsIsolationTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'tenant', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = new OrganizationContext;
        $ctx->set(101);
        $this->app->instance(OrganizationContext::class, $ctx);
        $s = Schema::connection('tenant');
        $s->create('inventory_suppliers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->string('code'); $t->string('name');
            $t->json('contact')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        $s->create('supplier_locations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id')->nullable(); $t->unsignedBigInteger('supplier_id');
            $t->string('label')->nullable(); $t->decimal('lat', 10, 8); $t->decimal('lng', 11, 8); $t->string('address')->nullable();
        });
        DB::connection('tenant')->table('inventory_suppliers')->insert([
            ['id' => 1, 'organization_id' => 101, 'code' => 'A', 'name' => 'Linked', 'contact' => null],
            ['id' => 2, 'organization_id' => 101, 'code' => 'B', 'name' => 'Standalone', 'contact' => json_encode(['location' => ['lat' => 0, 'lng' => 0, 'address' => 'Null Island']])],
            ['id' => 3, 'organization_id' => 102, 'code' => 'C', 'name' => 'Other org', 'contact' => null],
        ]);
        // Finance supplier 50 belongs to finance org 7; same supplier id 50 exists under another finance org 8.
        DB::connection('tenant')->table('supplier_locations')->insert([
            ['organization_id' => 7, 'supplier_id' => 50, 'label' => 'HQ', 'lat' => 31.95, 'lng' => 35.91, 'address' => 'Amman'],
            ['organization_id' => 7, 'supplier_id' => 50, 'label' => 'Depot', 'lat' => 32.0, 'lng' => 36.0, 'address' => null],
            ['organization_id' => 8, 'supplier_id' => 50, 'label' => 'OTHER ORG', 'lat' => 1, 'lng' => 1, 'address' => null],
            ['organization_id' => 7, 'supplier_id' => 51, 'label' => 'OTHER SUPPLIER', 'lat' => 2, 'lng' => 2, 'address' => null],
        ]);
        $link = \Mockery::mock(FinanceLink::class);
        $link->shouldReceive('readable')->andReturn(true);
        $link->shouldReceive('state')->andReturn(['state' => 'connected', 'finance_organization_id' => 7, 'mapping_uuid' => 'u', 'base_currency' => null]);
        $link->shouldReceive('financeIds')->andReturnUsing(fn ($type, $ids) => in_array(1, $ids, true) ? [1 => 50] : []);
        $this->app->instance(FinanceLink::class, $link);
    }

    public function test_linked_supplier_returns_only_its_finance_orgs_pins_with_edit_url(): void
    {
        $r = app(SupplierLocations::class)->for(Supplier::findOrFail(1));
        $this->assertTrue($r['linked']);
        $this->assertSame(['HQ', 'Depot'], array_column($r['locations'], 'label'));
        $this->assertStringEndsWith('/suppliers/50/edit', $r['edit_url']);
    }

    public function test_standalone_supplier_uses_own_location_including_zero_coordinates(): void
    {
        $r = app(SupplierLocations::class)->for(Supplier::findOrFail(2));
        $this->assertFalse($r['linked']);
        $this->assertNull($r['edit_url']);
        $this->assertSame([['id' => null, 'label' => null, 'lat' => 0.0, 'lng' => 0.0, 'address' => 'Null Island']], $r['locations']);
    }

    public function test_supplier_of_another_org_is_not_resolvable_so_route_returns_404(): void
    {
        $this->assertNull(Supplier::find(3));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Supplier::findOrFail(3);
    }
}
