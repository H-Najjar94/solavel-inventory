<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Catalog\FinanceReferenceDefaultsService;
use App\Support\InventoryCategoryDefaults;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Maps the standard units and categories both apps ship with, by a canonical key,
 * so a new organization never has to "reconcile" defaults it did not create.
 *
 * SolaCount stores its standard defaults once per tenant (organization_id NULL)
 * and may add organization-owned records; SolaStock seeds its own copy per
 * organization. Local ids never match, so pairs are found by canonical key:
 *   unit      "unit:<symbol>"        — the standard name AND symbol, unchanged
 *                                      (the standard list also fixes its dimension)
 *   category  "category:<a>/<b>"     — the standard name path from the top level,
 *                                      so hierarchy must match too
 *
 * A pair is mapped only when each side has exactly one active, unmodified
 * standard record for the key and neither is already mapped elsewhere. Anything
 * else (renamed, custom, duplicated, archived, conflicting) is left for review.
 * Re-running is a no-op: existing mappings are never changed or overwritten.
 */
final class StandardCatalogMappings
{
    public const METHOD = 'canonical_standard_default';

    public function ensure(IntegrationOrganizationMapping $mapping, int $actorId = 0): array
    {
        $result = ['unit' => 0, 'category' => 0];
        foreach (['unit' => ['units', 'inventory_units'], 'category' => ['item_categories', 'inventory_categories']] as $type => [$stockTable, $booksTable]) {
            if (! Schema::connection('tenant')->hasTable($stockTable) || ! Schema::connection('tenant')->hasTable($booksTable)) {
                continue;
            }
            $stock = $this->keyed($type, $stockTable, fn ($q) => $q->where('organization_id', $mapping->solastock_organization_id));
            $books = $this->keyed($type, $booksTable, fn ($q) => $q->where(fn ($w) => $w
                ->where('organization_id', $mapping->finance_organization_id)->orWhereNull('organization_id')));
            $existing = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->where('entity_type', $type)->get(['solastock_record_id', 'solabooks_record_id']);
            $mappedStock = $existing->pluck('solastock_record_id')->map('strval')->flip();
            $mappedBooks = $existing->pluck('solabooks_record_id')->map('strval')->flip();

            foreach ($stock as $key => $stockIds) {
                $booksIds = $books[$key] ?? [];
                if (count($stockIds) !== 1 || count($booksIds) !== 1) {
                    continue; // missing or ambiguous: a person decides
                }
                [$stockId, $booksId] = [(string) $stockIds[0], (string) $booksIds[0]];
                if (isset($mappedStock[$stockId]) || isset($mappedBooks[$booksId])) {
                    continue; // already mapped (possibly differently): never overwrite
                }
                IntegrationMasterDataMapping::query()->create([
                    'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id,
                    'finance_organization_id' => $mapping->finance_organization_id, 'solastock_organization_id' => $mapping->solastock_organization_id,
                    'entity_type' => $type, 'solastock_record_id' => $stockId, 'solabooks_record_id' => $booksId,
                    'status' => 'verified', 'contract_source_version' => $key, 'discovery_method' => self::METHOD,
                    'last_verified_at' => now(), 'created_by_user_id' => $actorId ?: null, 'updated_by_user_id' => $actorId ?: null,
                ]);
                $mappedStock[$stockId] = true;
                $mappedBooks[$booksId] = true;
                $result[$type]++;
            }
        }

        return $result;
    }

    /** Canonical key of one record, or null when it is not an unmodified standard default. */
    public static function unitKey(?string $name, ?string $symbol): ?string
    {
        foreach (FinanceReferenceDefaultsService::UNITS as [$standardName, $standardSymbol]) {
            if ($name === $standardName && $symbol === $standardSymbol) {
                return 'unit:'.$standardSymbol;
            }
        }

        return null;
    }

    /** @param list<string> $path category names from the top level down */
    public static function categoryKey(array $path): ?string
    {
        $tree = self::categoryTree();
        $node = $tree;
        foreach ($path as $name) {
            if (! array_key_exists($name, $node)) {
                return null;
            }
            $node = $node[$name];
        }

        return $path === [] ? null : 'category:'.implode('/', $path);
    }

    /** @return array<string,array> standard category names → standard children */
    private static function categoryTree(): array
    {
        $tree = [];
        foreach (InventoryCategoryDefaults::all() as $category) {
            $tree[$category['name']] = [];
        }
        foreach (FinanceReferenceDefaultsService::LEGACY_FINANCE_CATEGORIES as [$parent, $children]) {
            $tree[$parent] = array_fill_keys($children, []);
        }

        return $tree;
    }

    /** @return array<string,list<string>> canonical key → active record ids */
    private function keyed(string $type, string $table, callable $scope): array
    {
        $query = DB::connection('tenant')->table($table);
        $scope($query);
        if (Schema::connection('tenant')->hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if (Schema::connection('tenant')->hasColumn($table, 'is_active')) {
            $query->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true));
        }
        $columns = $type === 'unit' ? ['id', 'name', 'symbol'] : ['id', 'name', 'parent_id'];
        /** @var Collection<int,object> $rows */
        $rows = $query->get($columns)->keyBy(fn ($row) => (string) $row->id);

        $keys = [];
        foreach ($rows as $id => $row) {
            $key = $type === 'unit'
                ? self::unitKey($row->name, $row->symbol)
                : self::categoryKey($this->path($rows, $row));
            if ($key !== null) {
                $keys[$key][] = (string) $id;
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function path(Collection $rows, object $row): array
    {
        $path = [(string) $row->name];
        $seen = [(string) $row->id => true];
        while ($row->parent_id !== null) {
            $parent = $rows->get((string) $row->parent_id);
            if (! $parent || isset($seen[(string) $parent->id])) {
                return []; // parent outside scope or a cycle: not a standard path
            }
            $seen[(string) $parent->id] = true;
            array_unshift($path, (string) $parent->name);
            $row = $parent;
        }

        return $path;
    }
}
