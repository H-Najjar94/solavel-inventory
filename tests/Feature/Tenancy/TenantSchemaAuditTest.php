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
        $this->assertSame([], $audit['missing_tables']);
        $this->assertSame([], $audit['missing_columns']);
        $this->assertSame([], $audit['missing_indexes']);
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
