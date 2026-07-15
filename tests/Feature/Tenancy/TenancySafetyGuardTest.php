<?php

namespace Tests\Feature\Tenancy;

use App\Tenancy\TenancySafetyGuard;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pure logic checks of the safety guard — no database needed. Proves the guard
 * accepts only the three fixed SolaStock reserved DBs and rejects everything
 * else (Finance's DB, real tenants, production, central==tenant) BEFORE any DB
 * action could occur.
 */
class TenancySafetyGuardTest extends TestCase
{
    #[Test]
    public function it_accepts_the_three_reserved_solastock_databases(): void
    {
        TenancySafetyGuard::assertSafeTestDatabase('solastock_test_a');
        TenancySafetyGuard::assertSafeTestDatabase('solastock_test_b');
        TenancySafetyGuard::assertSafeTestDatabase('solastock_test_central');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_finance_reserved_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('tenant_990001');
    }

    #[Test]
    public function it_rejects_projects_reserved_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('tenant_990002');
    }

    #[Test]
    public function it_rejects_empty_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('');
    }

    #[Test]
    public function it_rejects_production_inventory_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('solavel_inventory');
    }

    #[Test]
    public function it_rejects_finance_production_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('solavel_finance');
    }

    #[Test]
    public function it_rejects_real_tenant_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('tenant_000002');
    }

    #[Test]
    public function it_rejects_real_inventory_tenant_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('inventory_tenant_000002');
    }

    #[Test]
    public function it_rejects_an_unreserved_99_range_name(): void
    {
        // Only 990010/11/12 are allowed — 990099 is not.
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestDatabase('tenant_990099');
    }

    #[Test]
    public function it_rejects_when_central_and_tenant_are_the_same_database(): void
    {
        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertCentralAndTenantDiffer('tenant_990010', 'tenant_990010');
    }

    #[Test]
    public function it_allows_distinct_central_and_tenant(): void
    {
        TenancySafetyGuard::assertCentralAndTenantDiffer('tenant_990004', 'tenant_990010');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_unsafe_resolved_tenant_config(): void
    {
        config(['database.connections.tenant.database' => 'tenant_000002']);

        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestEnvironment();
    }

    #[Test]
    public function it_accepts_valid_resolved_environment(): void
    {
        config(['database.connections.tenant.database' => 'solastock_test_a']);
        config(['database.connections.mysql.database' => 'solastock_test_central']);

        TenancySafetyGuard::assertSafeTestEnvironment();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_resolved_environment_when_central_equals_tenant(): void
    {
        config(['database.connections.tenant.database' => 'solastock_test_a']);
        config(['database.connections.mysql.database' => 'solastock_test_a']);

        $this->expectException(RuntimeException::class);
        TenancySafetyGuard::assertSafeTestEnvironment();
    }
}
