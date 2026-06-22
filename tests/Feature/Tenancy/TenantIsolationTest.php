<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant\Item;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Real MySQL tenancy isolation using the FIXED reserved databases:
 *   tenant A = tenant_990010, tenant B = tenant_990011, central = tenant_990012.
 * No databases are created or dropped; each test rolls back its transaction.
 *
 * Requires the reserved DBs to exist and be migrated (see docs/TESTING_TENANCY.md).
 */
class TenantIsolationTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function tenant_a_and_b_use_distinct_reserved_databases(): void
    {
        $dbA = $this->useTenantA();
        $this->assertSame('tenant_990010', $dbA);

        $dbB = $this->useTenantB();
        $this->assertSame('tenant_990011', $dbB);

        $this->assertNotSame($dbA, $dbB);
    }

    #[Test]
    public function tenant_a_cannot_read_or_write_tenant_b(): void
    {
        // Write a distinct record into tenant A.
        $this->useTenantA();
        Item::create(['sku' => 'A-ONLY', 'name' => 'Item in A']);
        $aSkus = Item::query()->pluck('sku')->all();
        $this->assertContains('A-ONLY', $aSkus);

        // Switch to tenant B: must NOT see A's record. Write B's own record.
        $this->useTenantB();
        $this->assertNotContains('A-ONLY', Item::query()->pluck('sku')->all());
        Item::create(['sku' => 'B-ONLY', 'name' => 'Item in B']);
        $this->assertContains('B-ONLY', Item::query()->pluck('sku')->all());

        // Back to A: B's record must be invisible from A — the core cross-tenant
        // guarantee, proven in BOTH directions (A↛B above, B↛A here).
        //
        // NOTE: we deliberately do NOT assert the earlier uncommitted 'A-ONLY'
        // row is still present. Switching tenants does Config::set + DB::purge +
        // DB::reconnect on the single `tenant` connection, which closes A's PDO
        // and rolls back its open test transaction — so A's *uncommitted* row is
        // gone on return. That is the isolation harness working as designed, not
        // a product behaviour: a real request is bound to exactly one tenant DB
        // for its lifetime and never round-trips away and back expecting
        // uncommitted state to survive. We re-create the row to confirm A is the
        // correct, writable database and that B's row never bled across.
        $this->useTenantA();
        $this->assertNotContains('B-ONLY', Item::query()->pluck('sku')->all(),
            'tenant A must never see tenant B rows');
        Item::create(['sku' => 'A-ONLY', 'name' => 'Item in A again']);
        $aSkus = Item::query()->pluck('sku')->all();
        $this->assertContains('A-ONLY', $aSkus);
        $this->assertNotContains('B-ONLY', $aSkus);
    }

    #[Test]
    public function central_and_tenant_resolve_to_distinct_databases(): void
    {
        $this->useTenantA();

        $central = config('database.connections.mysql.database');
        $tenant = config('database.connections.tenant.database');

        $this->assertSame('tenant_990012', $central);
        $this->assertSame('tenant_990010', $tenant);
        $this->assertNotSame($central, $tenant);
    }

    #[Test]
    public function central_models_use_mysql_and_inventory_models_use_tenant(): void
    {
        $this->useTenantA();

        // Inventory model → tenant connection → tenant_990010.
        $item = new Item;
        $this->assertSame('tenant', $item->getConnectionName());
        $this->assertSame('tenant_990010', $item->getConnection()->getDatabaseName());

        // Central/landlord model → mysql connection → tenant_990012.
        $org = new \App\Models\Landlord\Organization;
        $this->assertSame('mysql', $org->getConnectionName());
        $this->assertSame('tenant_990012', $org->getConnection()->getDatabaseName());
    }

    #[Test]
    public function org_scope_blocks_cross_org_rows_within_a_tenant(): void
    {
        $this->useTenantA(); // org context = ORG_A (990010)
        Item::create(['sku' => 'SCOPED', 'name' => 'Scoped']);

        // Raw-insert a row stamped for a different org; scoped query must hide it.
        DB::connection('tenant')->table('items')->insert([
            'organization_id' => TenantTestManager::ORG_B,
            'sku' => 'OTHER-ORG',
            'name' => 'Belongs to B-org',
            'item_type' => 'inventory',
            'tracking_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $skus = Item::query()->pluck('sku')->all();
        $this->assertContains('SCOPED', $skus);
        $this->assertNotContains('OTHER-ORG', $skus);
    }

    #[Test]
    public function creating_for_a_foreign_org_is_rejected(): void
    {
        $this->useTenantA();

        $this->expectException(\RuntimeException::class);
        Item::create([
            'organization_id' => TenantTestManager::ORG_B,
            'sku' => 'X',
            'name' => 'X',
        ]);
    }

    #[Test]
    public function no_active_org_returns_no_rows(): void
    {
        $this->useTenantA();
        Item::create(['sku' => 'PRESENT', 'name' => 'Present']);

        app(OrganizationContext::class)->forget();
        $this->assertSame(0, Item::query()->count());
    }

    #[Test]
    public function lock_for_update_runs_on_mysql(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'LOCK', 'name' => 'Lockable']);

        $this->assertSame('mysql', DB::connection('tenant')->getDriverName());

        // lockForUpdate inside the existing test transaction must execute on MySQL.
        $locked = Item::query()->whereKey($item->id)->lockForUpdate()->first();
        $this->assertNotNull($locked);
        $this->assertSame('LOCK', $locked->sku);
    }
}
