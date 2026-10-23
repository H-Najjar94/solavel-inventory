<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Entitlements\EntitlementAccessDecision;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceInventoryCapability;
use App\Services\Sales\CustomerItems\CustomerItemIdentityReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class CustomerItemIdentityTest extends TestCase
{
    use TenantAware;

    private const FINANCE_ORG = 14;

    private function reader(): CustomerItemIdentityReader
    {
        return app(CustomerItemIdentityReader::class);
    }

    private function requireFinanceTable(): void
    {
        if (! Schema::connection('tenant')->hasTable('finance_customer_items')) {
            $this->markTestSkipped('The test tenant has no SolaCount finance_customer_items schema.');
        }
    }

    private function customer(string $code): Customer
    {
        return Customer::query()->create(['code' => $code, 'name' => 'Customer '.$code, 'is_active' => true]);
    }

    private function agreement(int $financeCustomer, int $financeItem, string $status, ?string $sku, ?string $name, int $org = self::FINANCE_ORG): void
    {
        DB::connection('tenant')->table('finance_customer_items')->insert([
            'organization_id' => $org, 'customer_id' => $financeCustomer, 'inventory_item_id' => $financeItem,
            'status' => $status, 'customer_sku' => $sku, 'customer_item_name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(): array
    {
        $db = DB::connection('tenant');

        return [
            $db->table('finance_customer_items')->count(),
            $db->table('integration_master_data_mappings')->count(),
            $db->table('integration_organization_mappings')->count(),
        ];
    }

    #[Test]
    public function a_mapped_customer_and_item_return_identity_without_writing(): void
    {
        $this->useTenantA();
        $this->requireFinanceTable();
        $mapping = $this->connectFinance(true);
        $customer = $this->customer('CI-A');
        $item = F::averageItem();
        $other = F::averageItem();
        $this->financeMaster($mapping, 'customer', $customer->id, 8101);
        $this->financeMaster($mapping, 'item', $item->id, 8201);
        $this->agreement(8101, 8201, 'approved', 'ACME-001', 'Acme widget');
        $before = $this->snapshot();

        $result = $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, [$item->id, $other->id]);

        $this->assertSame([$item->id => ['customer_sku' => 'ACME-001', 'customer_item_name' => 'Acme widget', 'status' => 'approved']], $result);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function a_blocked_agreement_is_reported_with_its_status(): void
    {
        $this->useTenantA();
        $this->requireFinanceTable();
        $mapping = $this->connectFinance(true);
        $customer = $this->customer('CI-B');
        $item = F::averageItem();
        $this->financeMaster($mapping, 'customer', $customer->id, 8102);
        $this->financeMaster($mapping, 'item', $item->id, 8202);
        $this->agreement(8102, 8202, 'blocked', 'BLK-1', null);

        $result = $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, [$item->id]);

        $this->assertSame('blocked', $result[$item->id]['status']);
        $this->assertSame('BLK-1', $result[$item->id]['customer_sku']);
        $this->assertNull($result[$item->id]['customer_item_name']);
    }

    #[Test]
    public function unmapped_conflicting_or_foreign_records_return_nothing(): void
    {
        $this->useTenantA();
        $this->requireFinanceTable();
        $mapping = $this->connectFinance(true);
        $customer = $this->customer('CI-C');
        $unmappedItem = F::averageItem();
        $conflictItem = F::averageItem();
        $foreignItem = F::averageItem();
        $this->financeMaster($mapping, 'customer', $customer->id, 8103);
        $this->financeMaster($mapping, 'item', $conflictItem->id, 8204, ['conflict_code' => 'duplicate_identity']);
        $this->financeMaster($mapping, 'item', $foreignItem->id, 8205);
        $this->agreement(8103, 8203, 'approved', 'UNM', null);
        $this->agreement(8103, 8204, 'approved', 'CON', null);
        // Same finance ids but another finance organization's agreement.
        $this->agreement(8103, 8205, 'approved', 'FOREIGN', null, self::FINANCE_ORG + 1);
        $ids = [$unmappedItem->id, $conflictItem->id, $foreignItem->id];

        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, $ids));
        // Asking on behalf of another SolaStock organization never resolves.
        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_B, $customer->id, $ids));

        // An unmapped customer resolves nothing even for a mapped item.
        $stranger = $this->customer('CI-D');
        $mappedItem = F::averageItem();
        $this->financeMaster($mapping, 'item', $mappedItem->id, 8206);
        $this->agreement(8103, 8206, 'approved', 'OK', null);
        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $stranger->id, [$mappedItem->id]));
    }

    #[Test]
    public function a_not_entitled_or_unconnected_organization_reads_nothing(): void
    {
        $this->useTenantA();
        $this->requireFinanceTable();
        $customer = $this->customer('CI-E');
        $item = F::averageItem();
        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, [$item->id]));

        $mapping = $this->connectFinance(false);
        $this->financeMaster($mapping, 'customer', $customer->id, 8107);
        $this->financeMaster($mapping, 'item', $item->id, 8207);
        $this->agreement(8107, 8207, 'approved', 'NE', null);

        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, [$item->id]));
    }

    #[Test]
    public function a_missing_finance_table_returns_empty(): void
    {
        $this->useTenantA();
        if (Schema::connection('tenant')->hasTable('finance_customer_items')) {
            $this->markTestSkipped('The test tenant already has finance_customer_items.');
        }
        $mapping = $this->connectFinance(true);
        $customer = $this->customer('CI-F');
        $item = F::averageItem();
        $this->financeMaster($mapping, 'customer', $customer->id, 8108);
        $this->financeMaster($mapping, 'item', $item->id, 8208);

        $this->assertSame([], $this->reader()->forCustomerItems(TenantTestManager::ORG_A, $customer->id, [$item->id]));
    }

    private function connectFinance(bool $entitled): IntegrationOrganizationMapping
    {
        $mapping = IntegrationOrganizationMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => self::FINANCE_ORG, 'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
        IntegrationSetting::query()->create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => self::FINANCE_ORG,
            'meta' => ['client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A]]);
        $capability = \Mockery::mock(FinanceInventoryCapability::class);
        $capability->shouldReceive('allows')->andReturn($entitled);
        $cache = \Mockery::mock(EntitlementsCache::class);
        $cache->shouldReceive('getProjectSnapshot')->andReturn(['accessible' => true, 'commercially_entitled' => true,
            'subscription_status' => 'active', 'entitlement_source' => 'separate_subscription', 'access_until' => now()->addMonth()->toIso8601String()]);
        $this->app->instance(ApprovedFinanceIntegrationEntitlement::class, new ApprovedFinanceIntegrationEntitlement($cache, $capability, new EntitlementAccessDecision));

        return $mapping;
    }

    private function financeMaster(IntegrationOrganizationMapping $mapping, string $type, int $stockId, int $financeId, array $extra = []): void
    {
        IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => self::FINANCE_ORG,
            'solastock_organization_id' => TenantTestManager::ORG_A, 'entity_type' => $type,
            'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified'] + $extra);
    }
}
