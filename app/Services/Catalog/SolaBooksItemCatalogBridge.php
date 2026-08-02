<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps SolaStock's operational catalog (`items`) visible in SolaBooks'
 * accounting catalog (`inventory_items`).
 *
 * Both tables live in the same tenant database. The bridge deliberately owns
 * only common catalog fields; Finance account assignments and quantities stay
 * under SolaBooks ownership, while warehouse, traceability and costing-engine
 * data stay under SolaStock ownership.
 */
class SolaBooksItemCatalogBridge
{
    public function sync(Item $item, ?string $previousSku = null): bool
    {
        $connectionName = (string) config('tenancy.tenant_connection', 'tenant');
        $schema = Schema::connection($connectionName);

        if (! $schema->hasTable('inventory_items')) {
            return false;
        }

        $stockOrganizationId = (int) $item->organization_id;
        $sku = trim((string) $item->sku);
        if ($stockOrganizationId <= 0 || $sku === '') {
            return false;
        }

        $connection = DB::connection($connectionName);
        $booksOrganizationId = $this->financeOrganizationId($connection, $stockOrganizationId);
        $target = $this->findTarget($connection, $booksOrganizationId, $sku, $previousSku);
        $attributes = $this->onlyExistingColumns($schema->getColumnListing('inventory_items'), [
            'organization_id' => $booksOrganizationId,
            'name' => (string) $item->name,
            'sku' => $sku,
            'description' => $item->description,
            'type' => $this->financeType((string) $item->item_type),
            'item_type' => $this->stockType((string) $item->item_type),
            'tracking_type' => $this->financeTrackingType((string) $item->tracking_type),
            'unit_price' => $item->sales_price,
            'last_purchase_price' => $item->purchase_price ?? 0,
            'last_purchase_cost' => $item->purchase_price ?? 0,
            'reorder_point' => $item->reorder_point,
            'reorder_quantity' => $item->reorder_qty,
            'enable_reorder_alert' => (bool) $item->enable_reorder_alert,
            'is_active' => (bool) $item->is_active,
            'deleted_at' => $item->deleted_at,
            'updated_at' => $item->updated_at ?? now(),
        ]);

        if ($target) {
            $connection->table('inventory_items')->where('id', $target->id)->update($attributes);

            return true;
        }

        $attributes += $this->onlyExistingColumns($schema->getColumnListing('inventory_items'), [
            'qty_on_hand' => 0,
            'avg_cost' => 0,
            'average_cost' => $item->purchase_price ?? 0,
            'unit_cost' => $item->purchase_price ?? 0,
            'valuation_method' => 'fifo',
            'is_returnable' => true,
            'is_resold' => $this->stockType((string) $item->item_type) !== 'service',
            'is_free' => false,
            'reverse_charge_eligible' => false,
            'created_at' => $item->created_at ?? now(),
        ]);

        $connection->table('inventory_items')->insert($attributes);

        return true;
    }

    /**
     * Import a SolaBooks-only row into SolaStock. Existing SolaStock rows keep
     * their operational fields; reconciliation calls this only for missing SKUs.
     */
    public function importFromSolaBooks(object $source): bool
    {
        $connectionName = (string) config('tenancy.tenant_connection', 'tenant');
        $schema = Schema::connection($connectionName);
        if (! $schema->hasTable('items')) {
            return false;
        }

        $booksOrganizationId = (int) ($source->organization_id ?? 0);
        if ($booksOrganizationId <= 0 || ! isset($source->id)) {
            return false;
        }

        $connection = DB::connection($connectionName);
        $stockOrganizationId = $this->stockOrganizationId($connection, $booksOrganizationId);
        $sku = trim((string) ($source->sku ?? ''));
        if ($sku === '') {
            $sku = 'BOOKS-'.(int) $source->id;
            $connection->table('inventory_items')
                ->where('id', $source->id)
                ->where('organization_id', $booksOrganizationId)
                ->update(['sku' => $sku]);
        }

        if ($connection->table('items')
            ->whereIn('organization_id', $this->stockOrganizationScopes($connection, $stockOrganizationId))
            ->where('sku', $sku)
            ->exists()) {
            return false;
        }

        $type = $this->stockType((string) ($source->item_type ?? $source->type ?? 'inventory'));
        $purchasePrice = $source->last_purchase_price
            ?? $source->last_purchase_cost
            ?? $source->average_cost
            ?? $source->avg_cost
            ?? $source->unit_cost
            ?? 0;

        $attributes = $this->onlyExistingColumns($schema->getColumnListing('items'), [
            'organization_id' => $stockOrganizationId,
            'sku' => $sku,
            'name' => (string) ($source->name ?? $sku),
            'description' => $source->description ?? null,
            'item_type' => $type,
            'tracking_type' => $this->stockTrackingType((string) ($source->tracking_type ?? 'quantity')),
            'costing_method' => $this->stockCostingMethod((string) ($source->valuation_method ?? 'average')),
            'is_variant_parent' => false,
            'purchase_price' => $purchasePrice,
            'sales_price' => $source->unit_price ?? 0,
            'reorder_point' => $source->reorder_point ?? null,
            'reorder_qty' => $source->reorder_quantity ?? null,
            'enable_reorder_alert' => (bool) ($source->enable_reorder_alert ?? false),
            'is_active' => (bool) ($source->is_active ?? true),
            'created_at' => $source->created_at ?? now(),
            'updated_at' => $source->updated_at ?? now(),
            'deleted_at' => $source->deleted_at ?? null,
        ]);

        $connection->table('items')->insert($attributes);

        return true;
    }

    /**
     * Merge both live catalogs for one organization. SolaStock wins only for
     * overlapping common catalog fields; SolaBooks-only rows are added to
     * SolaStock and all Finance/accounting-only columns remain untouched.
     *
     * @return array<string,int>
     */
    public function reconcileOrganization(int $stockOrganizationId): array
    {
        $connectionName = (string) config('tenancy.tenant_connection', 'tenant');
        $connection = DB::connection($connectionName);
        $schema = Schema::connection($connectionName);
        if (! $schema->hasTable('items') || ! $schema->hasTable('inventory_items')) {
            return ['stock_before' => 0, 'books_before' => 0, 'to_books' => 0, 'to_stock' => 0, 'stock_after' => 0, 'books_after' => 0];
        }

        return $connection->transaction(function () use ($connection, $connectionName, $stockOrganizationId): array {
            $booksOrganizationId = $this->financeOrganizationId($connection, $stockOrganizationId);
            $stockScopes = $this->stockOrganizationScopes($connection, $stockOrganizationId);
            $stockRows = $connection->table('items')
                ->whereIn('organization_id', $stockScopes)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();
            $booksRows = $connection->table('inventory_items')
                ->where('organization_id', $booksOrganizationId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            $toBooks = 0;
            foreach ($stockRows as $row) {
                $model = (new Item)->newFromBuilder((array) $row, $connectionName);
                if ($this->sync($model)) {
                    $toBooks++;
                }
            }

            $toStock = 0;
            foreach ($booksRows as $row) {
                if ($this->importFromSolaBooks($row)) {
                    $toStock++;
                }
            }

            return [
                'stock_before' => $stockRows->count(),
                'books_before' => $booksRows->count(),
                'to_books' => $toBooks,
                'to_stock' => $toStock,
                'stock_after' => (int) $connection->table('items')
                    ->whereIn('organization_id', $stockScopes)->whereNull('deleted_at')->count(),
                'books_after' => (int) $connection->table('inventory_items')
                    ->where('organization_id', $booksOrganizationId)->whereNull('deleted_at')->count(),
            ];
        });
    }

    private function findTarget(
        ConnectionInterface $connection,
        int $organizationId,
        string $sku,
        ?string $previousSku
    ): ?object {
        $query = $connection->table('inventory_items')->where('organization_id', $organizationId);
        $old = trim((string) $previousSku);

        return $query->where(function ($candidate) use ($sku, $old): void {
            $candidate->where('sku', $sku);
            if ($old !== '' && $old !== $sku) {
                $candidate->orWhere('sku', $old);
            }
        })->orderByRaw('sku = ? desc', [$sku])->first(['id']);
    }

    private function onlyExistingColumns(array $columns, array $attributes): array
    {
        return array_intersect_key($attributes, array_flip($columns));
    }

    /** SolaStock scopes by central org id; SolaBooks scopes by tenant-local id. */
    private function financeOrganizationId(ConnectionInterface $connection, int $centralOrganizationId): int
    {
        if (! $connection->getSchemaBuilder()->hasTable('organizations')
            || ! $connection->getSchemaBuilder()->hasColumn('organizations', 'central_org_id')) {
            return $centralOrganizationId;
        }

        return (int) ($connection->table('organizations')
            ->where('central_org_id', $centralOrganizationId)
            ->value('id') ?? $centralOrganizationId);
    }

    /** Translate SolaBooks' tenant-local organization id to SolaStock's central id. */
    private function stockOrganizationId(ConnectionInterface $connection, int $financeOrganizationId): int
    {
        if (! $connection->getSchemaBuilder()->hasTable('organizations')
            || ! $connection->getSchemaBuilder()->hasColumn('organizations', 'central_org_id')) {
            return $financeOrganizationId;
        }

        return (int) ($connection->table('organizations')
            ->where('id', $financeOrganizationId)
            ->value('central_org_id') ?? $financeOrganizationId);
    }

    /** Current central id plus the historical tenant-local alias, if present. */
    private function stockOrganizationScopes(ConnectionInterface $connection, int $centralOrganizationId): array
    {
        $local = $this->financeOrganizationId($connection, $centralOrganizationId);

        return array_values(array_unique(array_filter([$centralOrganizationId, $local])));
    }

    private function stockType(string $type): string
    {
        return match (strtolower($type)) {
            'noninventory', 'non-inventory', 'non_inventory' => 'non_inventory',
            'service' => 'service',
            default => 'inventory',
        };
    }

    private function financeType(string $type): string
    {
        return $this->stockType($type);
    }

    private function financeTrackingType(string $type): string
    {
        return match (strtolower($type)) {
            'serial' => 'serial',
            'lot', 'batch', 'lot_serial' => 'batch',
            default => 'quantity',
        };
    }

    private function stockTrackingType(string $type): string
    {
        return match (strtolower($type)) {
            'serial' => 'serial',
            'batch', 'lot' => 'lot',
            'lot_serial' => 'lot_serial',
            default => 'none',
        };
    }

    private function stockCostingMethod(string $method): string
    {
        return match (strtolower($method)) {
            'fifo', 'lifo' => 'fifo',
            'standard' => 'standard',
            default => 'average',
        };
    }
}
