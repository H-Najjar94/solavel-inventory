<?php

namespace Tests\Unit;

use App\Services\Tenancy\InventoryTenantReadinessClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryTenantReadinessClassifierTest extends TestCase
{
    #[Test]
    public function it_marks_enabled_clean_production_tenants_as_production_ok(): void
    {
        $status = app(InventoryTenantReadinessClassifier::class)->classify([
            'tenant_key' => 'tenant_000002',
            'db_exists' => true,
            'schema_status' => 'pass',
            'integrity_status' => 'pass',
            'access_status' => 'safe_path_available',
            'inventory_enabled' => true,
        ]);

        $this->assertSame('production_ok', $status['final_status']);
    }

    #[Test]
    public function it_marks_clean_reserved_qa_tenants_as_qa_ok(): void
    {
        $status = app(InventoryTenantReadinessClassifier::class)->classify([
            'tenant_key' => 'tenant_990010',
            'db_exists' => true,
            'schema_status' => 'pass',
            'integrity_status' => 'pass',
            'access_status' => 'safe_path_available',
            'inventory_enabled' => true,
        ]);

        $this->assertSame('qa_ok', $status['final_status']);
    }

    #[Test]
    public function it_classifies_disabled_tenants_as_not_entitled(): void
    {
        $status = app(InventoryTenantReadinessClassifier::class)->classify([
            'tenant_key' => 'tenant_000008',
            'db_exists' => true,
            'schema_status' => 'pass',
            'integrity_status' => 'fail',
            'access_status' => 'disabled',
            'inventory_enabled' => false,
        ]);

        $this->assertSame('not_entitled', $status['final_status']);
    }

    #[Test]
    public function it_does_not_mark_schema_pass_tenants_usable_without_access_path(): void
    {
        $status = app(InventoryTenantReadinessClassifier::class)->classify([
            'tenant_key' => 'tenant_990011',
            'db_exists' => true,
            'schema_status' => 'pass',
            'integrity_status' => 'pass',
            'access_status' => 'no_safe_access_path',
            'inventory_enabled' => true,
        ]);

        $this->assertSame('no_safe_access_path', $status['final_status']);
    }

    #[Test]
    public function it_marks_reachable_incomplete_schema_as_migrations_incomplete(): void
    {
        $classifier = app(InventoryTenantReadinessClassifier::class);

        $partial = $classifier->classify([
            'tenant_key' => 'tenant_000018',
            'db_exists' => true,
            'schema_status' => 'fail',
            'missing_tables_count' => 18,
            'inventory_enabled' => true,
        ]);
        $empty = $classifier->classify([
            'tenant_key' => 'tenant_000019',
            'db_exists' => true,
            'schema_status' => 'fail',
            'missing_tables_count' => 22,
            'inventory_enabled' => true,
        ]);

        $this->assertSame('migrations_incomplete', $partial['final_status']);
        $this->assertSame('migrations_incomplete', $empty['final_status']);
    }

    #[Test]
    public function it_distinguishes_not_entitled_from_entitled_but_not_provisioned(): void
    {
        $classifier = app(InventoryTenantReadinessClassifier::class);

        $notEntitled = $classifier->classify([
            'tenant_key' => 'tenant_000012',
            'database_status' => 'missing',
            'inventory_enabled' => false,
        ]);
        $notProvisioned = $classifier->classify([
            'tenant_key' => 'tenant_000019',
            'database_status' => 'missing',
            'inventory_enabled' => true,
        ]);

        $this->assertSame('not_entitled', $notEntitled['final_status']);
        $this->assertSame('entitled_not_provisioned', $notProvisioned['final_status']);
    }
}
