<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Purchasing\PurchaseSourceOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Persisted read-only Finance projection is the explicit boundary, not Finance posting itself. */
final class PurchaseSourceOwnershipTest extends TestCase
{
    use TenantAware { tearDown as tenantTearDown; }

    private array $createdProjectionTables = [];

    protected function tearDown(): void
    {
        $db = DB::connection('tenant');
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        foreach ($this->createdProjectionTables as $table) {
            $db->statement("DROP TABLE `$table`");
        }
        $db->beginTransaction();
        $this->tenantTearDown();
    }

    public function test_new_financial_ownership_is_exact_scoped_and_preserves_reversal_pending_quantity(): void
    {
        $this->useTenantA();
        $db = DB::connection('tenant');
        $db->rollBack();
        // Temporary projection tables do not implicitly commit native test fixtures and
        // disappear with this isolated connection; no application schema is changed.
        foreach ([
            'finance_purchase_positions' => 'position_uuid CHAR(36), organization_id BIGINT, organization_mapping_uuid CHAR(36), bill_id BIGINT, bill_journal_id BIGINT, state VARCHAR(30)',
            'finance_purchase_settlements' => 'settlement_uuid CHAR(36), position_uuid CHAR(36), organization_id BIGINT, organization_mapping_uuid CHAR(36), receipt_id BIGINT, receipt_line_id BIGINT, receipt_mapping_uuid CHAR(36), quantity DECIMAL(20,4), state VARCHAR(30)',
            'bills' => 'id BIGINT, organization_id BIGINT, journal_entry_id BIGINT',
            'journal_entries' => 'id BIGINT, organization_id BIGINT, status VARCHAR(30), posted_at DATETIME, voided_at DATETIME NULL, deleted_at DATETIME NULL',
        ] as $table => $columns) {
            $kind = str_starts_with($table, 'finance_purchase_') ? '' : 'TEMPORARY ';
            $db->statement("CREATE {$kind}TABLE `$table` ($columns)");
            if ($kind === '') {
                $this->createdProjectionTables[] = $table;
            }
        }
        $db->beginTransaction();
        $mapping = IntegrationOrganizationMapping::create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => $db->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD']);
        $receiptUuid = (string) Str::uuid();
        IntegrationDocumentLifecycleMapping::create(['mapping_uuid' => $receiptUuid, 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => $db->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'source_application' => 'solastock', 'source_document_type' => 'goods_receipt', 'source_document_id' => '80',
            'base_currency_code' => 'JOD', 'lifecycle_status' => 'posted']);
        $position = (string) Str::uuid();
        $settlement = (string) Str::uuid();
        $db->table('bills')->insert(['id' => 90, 'organization_id' => 14, 'journal_entry_id' => 91]);
        $db->table('journal_entries')->insert(['id' => 91, 'organization_id' => 14, 'status' => 'posted', 'posted_at' => now()]);
        $db->table('finance_purchase_positions')->insert(['position_uuid' => $position, 'organization_id' => 14,
            'organization_mapping_uuid' => $mapping->mapping_uuid, 'bill_id' => 90, 'bill_journal_id' => 91, 'state' => 'active']);
        $db->table('finance_purchase_settlements')->insert(['settlement_uuid' => $settlement, 'position_uuid' => $position,
            'organization_id' => 14, 'organization_mapping_uuid' => $mapping->mapping_uuid, 'receipt_id' => 80,
            'receipt_line_id' => 81, 'receipt_mapping_uuid' => $receiptUuid, 'quantity' => 8, 'state' => 'settled']);
        $service = app(PurchaseSourceOwnership::class);
        $this->assertSame('48.00000000', $service->newUsedBase($mapping, 80, 81, '6'));
        $this->assertSame('0', $service->newUsedBase($mapping, 80, 81, '6', $settlement));
        $this->assertSame('0', $service->newUsedBase($mapping, 80, 82, '6'));
        $db->table('finance_purchase_settlements')->update(['receipt_mapping_uuid' => (string) Str::uuid()]);
        $this->assertSame('0', $service->newUsedBase($mapping, 80, 81, '6'));
        $db->table('finance_purchase_settlements')->update(['receipt_mapping_uuid' => $receiptUuid, 'state' => 'reversed']);
        $this->assertSame('0', $service->newUsedBase($mapping, 80, 81, '6'));
        $db->table('finance_purchase_positions')->update(['state' => 'reversal_pending']);
        $this->assertSame('48.00000000', $service->newUsedBase($mapping, 80, 81, '6'));
        $db->table('journal_entries')->update(['voided_at' => now()]);
        $this->assertSame('0', $service->newUsedBase($mapping, 80, 81, '6'));
    }
}
