<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\TenantSchemaAuditService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class TenantSchemaAuditTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function schema_audit_passes_for_reserved_inventory_tenant(): void
    {
        $this->useTenantA();

        $audit = app(TenantSchemaAuditService::class)->audit();

        $this->assertTrue($audit['ok']);
        $this->assertSame('pass', $audit['status']);
        $this->assertContains('stock_ledger', $audit['checked_tables']);
        $this->assertContains('expiry_warning_days', TenantSchemaAuditService::requirements()['inventory_settings']['columns']);
        $this->assertContains('default_purchase_tax_code', TenantSchemaAuditService::requirements()['inventory_settings']['columns']);
        $this->assertContains('default_sales_tax_code', TenantSchemaAuditService::requirements()['inventory_settings']['columns']);
        $this->assertContains('tax_amount', TenantSchemaAuditService::requirements()['purchase_order_lines']['columns']);
        $this->assertContains('serial_id', TenantSchemaAuditService::requirements()['pack_lines']['columns']);
        $this->assertContains('packl_org_serial_idx', TenantSchemaAuditService::requirements()['pack_lines']['indexes']);
        $this->assertSame([], $audit['missing_tables']);
        $this->assertSame([], $audit['missing_columns']);
        $this->assertSame([], $audit['missing_indexes']);
    }

    #[Test]
    public function phase_11_columns_are_release_blocking_requirements(): void
    {
        $this->useTenantA();

        foreach ([
            ['inventory_settings' => ['columns' => ['definitely_missing_expiry_warning_days']]],
            ['purchase_order_lines' => ['columns' => ['definitely_missing_tax_snapshot']]],
            ['pack_lines' => ['columns' => ['definitely_missing_serial_traceability']]],
        ] as $requirements) {
            $audit = app(TenantSchemaAuditService::class)->audit(requirements: $requirements);
            $this->assertFalse($audit['ok']);
            $this->assertNotEmpty($audit['missing_columns']);
        }
    }

    #[Test]
    public function schema_audit_reports_missing_tables_columns_and_indexes_without_repairing(): void
    {
        $this->useTenantA();

        $audit = app(TenantSchemaAuditService::class)->audit(requirements: [
            'definitely_missing_inventory_table' => ['columns' => ['id']],
            'items' => ['columns' => ['definitely_missing_column'], 'indexes' => ['definitely_missing_index']],
        ]);

        $this->assertFalse($audit['ok']);
        $this->assertSame('fail', $audit['status']);
        $this->assertContains('definitely_missing_inventory_table', $audit['missing_tables']);
        $this->assertContains('items.definitely_missing_column', $audit['missing_columns']);
        $this->assertContains('items.definitely_missing_index', $audit['missing_indexes']);
    }
}
