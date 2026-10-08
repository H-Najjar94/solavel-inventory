<?php

namespace App\Console\Commands;

use App\Services\Catalog\SolaBooksItemCatalogBridge;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SyncSolaBooksItemCatalog extends Command
{
    protected $signature = 'inventory:catalog-sync
        {--database= : Explicit tenant_XXXXXX database}
        {--organization= : Limit reconciliation to one organization id}
        {--dry-run : Report differences without writing}';

    protected $description = 'Merge SolaStock and SolaCount item catalogs in one shared tenant database';

    public function handle(TenantManager $tenants, SolaBooksItemCatalogBridge $bridge): int
    {
        $database = trim((string) $this->option('database'));
        if (! preg_match('/^tenant_\d{6}$/', $database)) {
            $this->error('Provide an explicit tenant database such as --database=tenant_000002.');

            return self::INVALID;
        }

        $tenants->switchToDatabase($database);
        $connection = DB::connection((string) config('tenancy.tenant_connection', 'tenant'));
        if (! Schema::connection($connection->getName())->hasTable('items')
            || ! Schema::connection($connection->getName())->hasTable('inventory_items')) {
            $this->error("{$database} does not contain both item catalog tables.");

            return self::FAILURE;
        }

        $requestedOrg = (int) $this->option('organization');
        $organizations = $requestedOrg > 0
            ? collect([$requestedOrg])
            : $this->logicalOrganizationIds($connection);

        if ($organizations->isEmpty()) {
            $this->info("{$database}: no catalog organizations found.");

            return self::SUCCESS;
        }

        foreach ($organizations as $organizationId) {
            $before = $this->counts($connection, $organizationId);
            if ($this->option('dry-run')) {
                $this->line(json_encode([
                    'database' => $database,
                    'central_organization_id' => $organizationId,
                    'finance_organization_id' => $this->financeOrganizationId($connection, $organizationId),
                    'dry_run' => true,
                ] + $before));
                continue;
            }

            try {
                $result = $bridge->reconcileOrganization($organizationId);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    "Catalog reconciliation failed for {$database}, organization {$organizationId}: {$e->getMessage()}",
                    0,
                    $e
                );
            }

            $this->line(json_encode([
                'database' => $database,
                'central_organization_id' => $organizationId,
                'finance_organization_id' => $this->financeOrganizationId($connection, $organizationId),
            ] + $result));
        }

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function counts($connection, int $organizationId): array
    {
        $financeOrganizationId = $this->financeOrganizationId($connection, $organizationId);
        $stockSkus = $connection->table('items')
            ->whereIn('organization_id', array_values(array_unique([$organizationId, $financeOrganizationId])))
            ->whereNull('deleted_at')
            ->pluck('sku')->filter()->map(fn ($sku) => (string) $sku);
        $booksSkus = $connection->table('inventory_items')
            ->where('organization_id', $financeOrganizationId)->whereNull('deleted_at')
            ->pluck('sku')->filter()->map(fn ($sku) => (string) $sku);

        return [
            'stock_count' => $stockSkus->count(),
            'books_count' => $booksSkus->count(),
            'stock_only' => $stockSkus->diff($booksSkus)->count(),
            'books_only' => $booksSkus->diff($stockSkus)->count(),
        ];
    }

    private function logicalOrganizationIds($connection)
    {
        $organizationMap = $connection->table('organizations')
            ->whereNotNull('central_org_id')
            ->pluck('central_org_id', 'id')
            ->map(fn ($id) => (int) $id);
        $centralIds = $organizationMap->values()->all();

        $fromStock = $connection->table('items')->whereNotNull('organization_id')
            ->distinct()->pluck('organization_id')->map(function ($id) use ($organizationMap, $centralIds): int {
                $id = (int) $id;

                return in_array($id, $centralIds, true) ? $id : (int) ($organizationMap[$id] ?? $id);
            });
        $fromBooks = $connection->table('inventory_items')->whereNotNull('organization_id')
            ->distinct()->pluck('organization_id')
            ->map(fn ($id) => (int) ($organizationMap[(int) $id] ?? $id));

        return $fromStock->merge($fromBooks)->unique()->sort()->values();
    }

    private function financeOrganizationId($connection, int $centralOrganizationId): int
    {
        return (int) ($connection->table('organizations')
            ->where('central_org_id', $centralOrganizationId)
            ->value('id') ?? $centralOrganizationId);
    }
}
