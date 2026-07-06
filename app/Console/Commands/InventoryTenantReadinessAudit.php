<?php

namespace App\Console\Commands;

use App\Services\Stock\IntegrityChecker;
use App\Services\Tenancy\InventoryTenantReadinessClassifier;
use App\Services\Tenancy\TenantSchemaAuditService;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryTenantReadinessAudit extends Command
{
    protected $signature = 'inventory:tenant-readiness-audit
        {--all : Audit all discovered SolaStock tenant databases}
        {--json : Print machine-readable JSON}';

    protected $description = 'Read-only all-tenant SolaStock readiness audit';

    public function handle(
        TenantSchemaAuditService $schemaAudit,
        IntegrityChecker $integrity,
        InventoryTenantReadinessClassifier $classifier,
        OrganizationContext $context,
    ): int {
        if (! $this->option('all')) {
            $this->error('Pass --all to run the production-safe all-tenant audit.');

            return self::FAILURE;
        }

        $tenants = [];
        foreach ($this->discoverTenantKeys() as $tenantKey) {
            $tenants[] = $this->auditTenant($tenantKey, $schemaAudit, $integrity, $classifier, $context);
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'tenant_count' => count($tenants),
            'tenants' => $tenants,
            'summary' => collect($tenants)->groupBy('final_status')->map->count()->sortKeys()->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Tenant', 'Org/client', 'Schema', 'Integrity', 'Access', 'Readiness', 'Final status', 'Recommended action'],
                collect($tenants)->map(fn (array $tenant) => [
                    $tenant['tenant_key'],
                    $tenant['org_client'],
                    $tenant['schema_status'],
                    $tenant['integrity_status'],
                    $tenant['access_status'],
                    $tenant['readiness_status'],
                    $tenant['final_status'],
                    $tenant['recommended_action'],
                ])->all()
            );
        }

        return self::SUCCESS;
    }

    private function auditTenant(
        string $tenantKey,
        TenantSchemaAuditService $schemaAudit,
        IntegrityChecker $integrity,
        InventoryTenantReadinessClassifier $classifier,
        OrganizationContext $context,
    ): array {
        $central = $this->centralState($tenantKey);
        $dbExists = $this->databaseExists($tenantKey);
        $schema = $dbExists
            ? $schemaAudit->auditDatabase($tenantKey)
            : ['ok' => false, 'status' => 'fail', 'missing_database' => true, 'missing_tables' => [], 'missing_columns' => [], 'missing_indexes' => []];

        $tenantOrgIds = [];
        $integrityStatus = 'not_run';
        $integrityIssues = [];

        if ($dbExists && ($schema['ok'] ?? false)) {
            $this->switchTenantConnection($tenantKey);
            $tenantOrgIds = $this->tenantOrgIds();

            foreach ($tenantOrgIds as $orgId) {
                $result = $context->runFor((int) $orgId, fn () => $integrity->check('tenant', (int) $orgId));
                if (! ($result['ok'] ?? false)) {
                    $integrityIssues[(string) $orgId] = $result['problems'] ?? [];
                }
            }

            $integrityStatus = $integrityIssues === [] ? 'pass' : 'fail';
            $context->forget();
        }

        $activeInventoryOrgIds = $central['active_inventory_org_ids'];
        $accessStatus = match (true) {
            ! $dbExists => 'missing_database',
            ! ($schema['ok'] ?? false) => 'schema_failed',
            $activeInventoryOrgIds === [] => ($central['orgs'] === [] ? 'no_safe_access_path' : 'disabled'),
            $tenantOrgIds === [] => 'no_safe_access_path',
            $central['active_users_count'] <= 0 => 'no_safe_access_path',
            default => 'safe_path_available',
        };

        $readinessStatus = match (true) {
            ! $central['inventory_enabled'] => 'disabled',
            ! ($schema['ok'] ?? false) => 'schema_failed',
            $integrityStatus === 'fail' => 'integrity_failed',
            $accessStatus !== 'safe_path_available' => $accessStatus,
            default => 'usable',
        };

        $row = [
            'tenant_key' => $tenantKey,
            'client_id' => $this->clientId($tenantKey),
            'org_client' => $central['org_client'],
            'db_exists' => $dbExists,
            'schema_status' => ($schema['ok'] ?? false) ? 'pass' : 'fail',
            'missing_database' => (bool) ($schema['missing_database'] ?? false),
            'missing_tables_count' => count($schema['missing_tables'] ?? []),
            'missing_columns_count' => count($schema['missing_columns'] ?? []),
            'missing_indexes_count' => count($schema['missing_indexes'] ?? []),
            'integrity_status' => $integrityStatus,
            'integrity_issues' => $integrityIssues,
            'access_status' => $accessStatus,
            'readiness_status' => $readinessStatus,
            'inventory_enabled' => $central['inventory_enabled'],
            'active_inventory_org_ids' => $activeInventoryOrgIds,
            'tenant_org_ids' => $tenantOrgIds,
            'active_users_count' => $central['active_users_count'],
        ];

        return array_merge($row, $classifier->classify($row));
    }

    /** @return array<int,string> */
    private function discoverTenantKeys(): array
    {
        $keys = collect(DB::connection('mysql')->select(
            'SELECT SCHEMA_NAME tenant_key FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?',
            ['tenant\_%']
        ))->pluck('tenant_key')->map(fn ($key) => (string) $key);

        if (Schema::connection('mysql')->hasTable('organization_projects') && Schema::connection('mysql')->hasTable('projects')) {
            $inventoryClientIds = DB::connection('mysql')->table('organization_projects as op')
                ->join('projects as p', 'p.id', '=', 'op.project_id')
                ->join('organizations as o', 'o.id', '=', 'op.organization_id')
                ->where('p.slug', 'inventory')
                ->pluck('o.client_id')
                ->map(fn ($id) => 'tenant_'.str_pad((string) $id, 6, '0', STR_PAD_LEFT));
            $keys = $keys->merge($inventoryClientIds);
        }

        return $keys->unique()->sort()->values()->all();
    }

    private function centralState(string $tenantKey): array
    {
        $clientId = $this->clientId($tenantKey);
        $orgs = [];
        $activeInventoryOrgIds = [];
        $orgClient = '';
        $activeUsers = 0;

        if ($clientId !== null && Schema::connection('mysql')->hasTable('organizations')) {
            $orgRows = DB::connection('mysql')->table('organizations as o')
                ->leftJoin('clients as c', 'c.id', '=', 'o.client_id')
                ->where('o.client_id', $clientId)
                ->get(['o.id', 'o.display_name', 'o.is_active', 'c.name as client_name']);
            $orgs = $orgRows->map(fn ($row) => (array) $row)->all();
            $orgClient = $orgRows->map(fn ($row) => $row->display_name ?: $row->client_name)->filter()->implode('; ');

            $activeUsers = DB::connection('mysql')->table('users')
                ->where('client_id', $clientId)
                ->where('status', 'active')
                ->count();

            if (Schema::connection('mysql')->hasTable('organization_projects') && Schema::connection('mysql')->hasTable('projects')) {
                $activeInventoryOrgIds = DB::connection('mysql')->table('organization_projects as op')
                    ->join('projects as p', 'p.id', '=', 'op.project_id')
                    ->where('p.slug', 'inventory')
                    ->where('op.is_active', true)
                    ->whereIn('op.organization_id', $orgRows->pluck('id')->all())
                    ->pluck('op.organization_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }
        }

        return [
            'orgs' => $orgs,
            'org_client' => $orgClient,
            'inventory_enabled' => $activeInventoryOrgIds !== [],
            'active_inventory_org_ids' => $activeInventoryOrgIds,
            'active_users_count' => (int) $activeUsers,
        ];
    }

    /** @return array<int,int> */
    private function tenantOrgIds(): array
    {
        $ids = [];
        foreach (['inventory_settings', 'items', 'warehouses', 'stock_balances', 'stock_ledger', 'inventory_purchase_orders', 'goods_receipts', 'opening_stock_entries', 'stock_transfers', 'stock_adjustments', 'stock_counts', 'inventory_sales_orders'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table) || ! Schema::connection('tenant')->hasColumn($table, 'organization_id')) {
                continue;
            }

            foreach (DB::connection('tenant')->table($table)->whereNotNull('organization_id')->distinct()->pluck('organization_id')->all() as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    private function switchTenantConnection(string $tenantKey): void
    {
        Config::set('database.connections.tenant.database', $tenantKey);
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    private function databaseExists(string $tenantKey): bool
    {
        return (bool) DB::connection('mysql')->selectOne(
            'SELECT 1 ok FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
            [$tenantKey]
        );
    }

    private function clientId(string $tenantKey): ?int
    {
        if (! preg_match('/^tenant_(\d+)$/', $tenantKey, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
