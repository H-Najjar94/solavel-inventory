<?php

namespace Database\Seeders;

use App\Models\Tenant\IntegrationOrganizationMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Phase6aStagingStockSeeder extends Seeder
{
    private const SCOPES = [
        'phase6a_uat_finance_first' => ['client' => 990000, 'org' => 1, 'mapping' => '60000000-0000-4000-8000-000000000001', 'scenario' => 'finance-first'],
        'phase6a_uat_stock_first' => ['client' => 990001, 'org' => 2, 'mapping' => '60000000-0000-4000-8000-000000000002', 'scenario' => 'stock-first'],
        'phase6a_uat_dual' => ['client' => 990002, 'org' => 3, 'mapping' => '60000000-0000-4000-8000-000000000003', 'scenario' => 'dual-existing'],
    ];

    public function run(): void
    {
        $database = (string) DB::connection('tenant')->getDatabaseName();
        $this->assertIsolatedStaging($database);
        $scope = self::SCOPES[$database];
        $keys = $this->credentials($database);
        $this->seedLandlord($scope, $database);

        $mapping = IntegrationOrganizationMapping::query()->updateOrCreate(
            ['mapping_uuid' => $scope['mapping']],
            [
                'central_client_id' => $scope['client'], 'central_organization_id' => $scope['org'],
                'tenant_database_identity' => $database, 'finance_organization_id' => 1,
                'solastock_organization_id' => $scope['org'], 'integration' => 'solabooks',
                'contract_version' => 'solastock-journal.v2', 'status' => 'verified_hold',
                'activation_state' => 'maintenance_hold', 'base_currency_code' => 'JOD',
                'currency_verified_at' => now('UTC'), 'verified_at' => now('UTC'),
                'current_v2_signing_key_id' => $keys['signing_db_id'], 'v2_key_scope_status' => 'provisioned_held',
            ],
        );

        DB::connection('tenant')->table('integration_settings')->updateOrInsert(
            ['organization_id' => $scope['org'], 'integration' => 'solabooks'],
            [
                'mode' => 'connected_pending_mapping', 'solabooks_organization_id' => 1,
                'require_mapping_before_post' => true,
                'meta' => json_encode([
                    'client_id' => $scope['client'], 'central_organization_id' => $scope['org'],
                    'integration_mapping_uuid' => $scope['mapping'], 'signing_key_id' => $keys['signing_key_id'],
                    'signing_protocol_version' => 'v1', 'contract_version' => 'solastock-journal.v2',
                    'signing_secret_encrypted' => Crypt::encryptString($keys['signing_secret']),
                    'api_key_encrypted' => Crypt::encryptString($keys['api_key']),
                    'finance_currency_contract' => [
                        'base_currency_code' => 'JOD',
                        'enabled_currency_codes' => ['JOD', 'USD', 'EUR', 'GBP', 'AED', 'SAR'],
                        'currency_precisions' => ['JOD' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'AED' => 2, 'SAR' => 2],
                        'money_scale' => 2, 'rate_scale' => 8,
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
        );

        $records = $this->masterData($scope);
        $this->stableMapping($mapping->mapping_uuid, $scope, 'category', $records['stock_category'], $records['finance_category']);
        $this->stableMapping($mapping->mapping_uuid, $scope, 'unit', $records['stock_unit'], $records['finance_unit']);
        $this->stableMapping($mapping->mapping_uuid, $scope, 'warehouse', $records['stock_warehouse'], $records['finance_warehouse']);
        $this->stableMapping($mapping->mapping_uuid, $scope, 'customer', $records['stock_customer'], $records['finance_customer']);
        $this->stableMapping($mapping->mapping_uuid, $scope, 'supplier', $records['stock_supplier'], $records['finance_supplier']);
        $this->accountMappings($scope);
        $this->taxMappings($scope);
        $this->scenarioInventory($scope, $records);

        $this->command?->info("Seeded isolated SolaStock UAT {$database}; organization {$scope['org']}.");
    }

    private function masterData(array $scope): array
    {
        $now = now('UTC');
        $stockUnit = $this->id('units', ['organization_id' => $scope['org'], 'code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'kind' => 'count', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $financeUnit = $this->id('inventory_units', ['organization_id' => 1, 'symbol' => 'ea'], ['name' => 'Each', 'created_at' => $now, 'updated_at' => $now]);
        $stockCategory = $this->id('item_categories', ['organization_id' => $scope['org'], 'name' => 'UAT Goods'], ['level' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $financeCategory = $this->id('inventory_categories', ['organization_id' => 1, 'name' => 'UAT Goods'], ['level' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $stockWarehouse = $this->id('warehouses', ['organization_id' => $scope['org'], 'code' => 'UAT-MAIN'], ['name' => 'UAT Main Warehouse', 'type' => 'warehouse', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $financeWarehouse = $this->id('inventory_locations', ['organization_id' => 1, 'location_name' => 'UAT Main Warehouse'], ['location_type' => 'warehouse', 'created_at' => $now, 'updated_at' => $now]);
        $stockCustomer = $this->id('inventory_customers', ['organization_id' => $scope['org'], 'code' => 'UAT-CUST'], ['name' => 'UAT Customer', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $financeCustomer = $this->id('customers', ['organization_id' => 1, 'email' => 'uat-customer@staging.invalid'], ['name' => 'UAT Customer', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $stockSupplier = $this->id('inventory_suppliers', ['organization_id' => $scope['org'], 'code' => 'UAT-SUP'], ['name' => 'UAT Supplier', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $financeSupplier = $this->id('suppliers', ['organization_id' => 1, 'email' => 'uat-supplier@staging.invalid'], ['name' => 'UAT Supplier', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

        return [
            'stock_unit' => $stockUnit,
            'finance_unit' => $financeUnit,
            'stock_category' => $stockCategory,
            'finance_category' => $financeCategory,
            'stock_warehouse' => $stockWarehouse,
            'finance_warehouse' => $financeWarehouse,
            'stock_customer' => $stockCustomer,
            'finance_customer' => $financeCustomer,
            'stock_supplier' => $stockSupplier,
            'finance_supplier' => $financeSupplier,
        ];
    }

    private function scenarioInventory(array $scope, array $records): void
    {
        $now = now('UTC');
        if ($scope['scenario'] !== 'stock-first') {
            $inventoryAccount = DB::connection('tenant')->table('integration_account_mappings')
                ->where('organization_id', $scope['org'])->where('integration', 'solabooks')
                ->where('mapping_type', 'inventory_asset')->value('solabooks_account_id');
            $cogsAccount = DB::connection('tenant')->table('integration_account_mappings')
                ->where('organization_id', $scope['org'])->where('integration', 'solabooks')
                ->where('mapping_type', 'cogs')->value('solabooks_account_id');
            $salesAccount = DB::connection('tenant')->table('integration_account_mappings')
                ->where('organization_id', $scope['org'])->where('integration', 'solabooks')
                ->where('mapping_type', 'sales_revenue')->value('solabooks_account_id');
            $this->id('inventory_items', ['organization_id' => 1, 'sku' => 'UAT-SHARED'], [
                'name' => 'UAT Shared Item', 'item_type' => 'inventory', 'type' => 'inventory',
                'unit_id' => $records['finance_unit'], 'category_id' => $records['finance_category'],
                'qty_on_hand' => '0.0000',
                'average_cost' => '0.0000',
                'unit_cost' => '0.0000',
                'valuation_method' => 'fifo',
                'inventory_asset_account_id' => $inventoryAccount,
                'cogs_account_id' => $cogsAccount,
                'income_account_id' => $salesAccount,
                'default_sales_account_id' => $salesAccount,
                'tracking_type' => 'none', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($scope['scenario'] !== 'finance-first') {
            $this->id('items', ['organization_id' => $scope['org'], 'sku' => 'UAT-SHARED'], [
                'name' => 'UAT Shared Item', 'item_type' => 'inventory', 'tracking_type' => 'none',
                'category_id' => $records['stock_category'], 'base_unit_id' => $records['stock_unit'],
                'costing_method' => 'fifo', 'purchase_price' => '10.0000', 'sales_price' => '15.0000',
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function accountMappings(array $scope): void
    {
        $defaults = (array) DB::connection('tenant')->table('org_account_defaults')->where('organization_id', 1)->first();
        $codes = ['cogs' => '5001', 'grni' => '2198', 'landed_cost_clearing' => '1398', 'transfer_clearing' => '1399', 'rounding' => '6898'];
        $roles = [
            'inventory_asset' => $defaults['inventory_asset_account_id'] ?? null,
            'opening_offset' => $defaults['opening_balance_equity_account_id'] ?? null,
            'adjustment_gain' => $defaults['inventory_adjustment_gain_account_id'] ?? null,
            'adjustment_loss' => $defaults['inventory_adjustment_loss_account_id'] ?? null,
            'accounts_receivable' => $defaults['ar_account_id'] ?? null, 'accounts_payable' => $defaults['ap_account_id'] ?? null,
            'sales_revenue' => $defaults['sales_account_id'] ?? null,
            'input_tax' => $defaults['vat_input_account_id'] ?? null, 'output_tax' => $defaults['vat_output_account_id'] ?? null,
        ];
        foreach ($codes as $role => $code) {
            $roles[$role] = DB::connection('tenant')->table('accounts')->where('organization_id', 1)->where('code', $code)->value('id');
        }
        foreach ($roles as $role => $accountId) {
            if (! $accountId) {
                throw new RuntimeException("Missing Finance account for {$role}.");
            }
            DB::connection('tenant')->table('integration_account_mappings')->updateOrInsert(
                ['organization_id' => $scope['org'], 'integration' => 'solabooks', 'mapping_type' => $role],
                ['solabooks_account_id' => (string) $accountId, 'status' => 'verified', 'last_verified_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
            );
        }
    }

    private function taxMappings(array $scope): void
    {
        foreach (['UAT-VAT16' => 'standard', 'UAT-ZERO' => 'zero'] as $code => $treatment) {
            $tax = DB::connection('tenant')->table('taxes')->where('organization_id', 1)->where('code', $code)->first();
            if (! $tax) {
                throw new RuntimeException("Missing Finance tax {$code}.");
            }
            DB::connection('tenant')->table('integration_tax_mappings')->updateOrInsert(
                ['organization_id' => $scope['org'], 'integration' => 'solabooks', 'tax_code' => $code],
                ['treatment' => $treatment, 'solabooks_tax_id' => $tax->id, 'solabooks_tax_code' => $code, 'status' => 'mapped', 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
            );
        }
    }

    private function stableMapping(string $organizationMappingUuid, array $scope, string $type, int $stockId, int $financeId): void
    {
        DB::connection('tenant')->table('integration_master_data_mappings')->updateOrInsert(
            ['organization_mapping_uuid' => $organizationMappingUuid, 'entity_type' => $type, 'solastock_record_id' => (string) $stockId],
            [
                'mapping_uuid' => $this->stableUuid("{$organizationMappingUuid}|{$type}|{$stockId}|{$financeId}"), 'central_client_id' => $scope['client'],
                'central_organization_id' => $scope['org'], 'finance_organization_id' => 1,
                'solastock_organization_id' => $scope['org'], 'solabooks_record_id' => (string) $financeId,
                'status' => 'verified', 'contract_source_version' => 'phase6a-staging.v1',
                'discovery_method' => 'reviewed_staging_seed', 'last_verified_at' => now('UTC'),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
        );
    }

    private function id(string $table, array $identity, array $values): int
    {
        DB::connection('tenant')->table($table)->updateOrInsert($identity, $values);

        return (int) DB::connection('tenant')->table($table)->where($identity)->value('id');
    }

    private function stableUuid(string $identity): string
    {
        $hex = hash('sha256', $identity);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function credentials(string $database): array
    {
        $path = '/home/hnajjar/staging/phase6a/credentials/integration-keys.json';
        if (! is_file($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Protected Staging integration credentials are unavailable.');
        }
        $all = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $row = $all[$database] ?? null;
        if (! is_array($row) || ! isset($row['signing_key_id'], $row['signing_secret'], $row['api_key'], $row['mapping_id'])) {
            throw new RuntimeException('Incomplete protected Staging integration credentials.');
        }
        $row['signing_db_id'] = (int) DB::connection('tenant')->table('external_signing_keys')
            ->where('key_id', $row['signing_key_id'])->where('status', 'active')->value('id');
        if ($row['signing_db_id'] <= 0) {
            throw new RuntimeException('Staging signing key does not match Finance.');
        }

        return $row;
    }

    private function seedLandlord(array $scope, string $database): void
    {
        $credentials = json_decode((string) file_get_contents('/home/hnajjar/staging/phase6a/credentials/uat-users.json'), true, 512, JSON_THROW_ON_ERROR);
        $user = collect($credentials)->firstWhere('client_id', $scope['client']);
        if (! $user) {
            throw new RuntimeException('Staging user identity is missing.');
        }
        DB::connection('mysql')->table('organizations')->updateOrInsert(
            ['central_organization_id' => $scope['org']],
            [
                'id' => $scope['org'], 'client_id' => $scope['client'],
                'name' => "Phase 6A Staging {$scope['scenario']}",
                'display_name' => "Phase 6A Staging {$scope['scenario']}",
                'legal_name' => "Phase 6A Staging {$scope['scenario']}",
                'database_name' => $database, 'base_currency' => 'JOD', 'is_active' => 1,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ],
        );
        DB::connection('mysql')->table('users')->updateOrInsert(
            ['id' => $user['central_user_id']],
            ['client_id' => $scope['client'], 'organization_id' => $scope['org'], 'name' => "Phase 6A Staging {$scope['scenario']} owner", 'email' => $user['email'], 'password' => password_hash($user['password'], PASSWORD_BCRYPT), 'status' => 'active', 'email_verified_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
        );
        DB::connection('mysql')->table('user_organizations')->updateOrInsert(
            ['user_id' => $user['central_user_id'], 'organization_id' => $scope['org']],
            ['role' => 'client_owner', 'status' => 'active', 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
        );
    }

    private function assertIsolatedStaging(string $database): void
    {
        if (! app()->environment('staging') || ! isset(self::SCOPES[$database])
            || (string) DB::connection('mysql')->getDatabaseName() !== 'phase6a_staging_stock_registry'
            || (int) DB::connection('tenant')->selectOne('SELECT @@port AS port')->port !== 0) {
            throw new RuntimeException('Phase 6A SolaStock seeding requires exact socket-only Staging databases.');
        }
    }
}
