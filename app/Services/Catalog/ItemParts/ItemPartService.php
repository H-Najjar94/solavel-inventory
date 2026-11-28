<?php

namespace App\Services\Catalog\ItemParts;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemPart;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Item Parts link service. One logical link, two authoritative stores in the shared tenant
 * database: Stock `item_parts` and SolaCount `inventory_item_parts`. Reads are the union
 * (Finance ids translated through verified item mappings); writes go to Stock's table.
 */
class ItemPartService
{
    private const FINANCE_TABLE = 'inventory_item_parts';

    private function db()
    {
        return DB::connection('tenant');
    }

    private function org(): int
    {
        return app(OrganizationContext::class)->idOrFail();
    }

    public function schemaReady(): bool
    {
        return Schema::connection('tenant')->hasTable('item_parts');
    }

    /** Finance organization id of the single verified connection, or null (standalone). */
    private function financeOrgId(): ?int
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('integration_organization_mappings') || ! $schema->hasTable('integration_master_data_mappings')) {
            return null;
        }
        $ids = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $this->org())
            ->where('tenant_database_identity', (string) $this->db()->getDatabaseName())
            ->whereIn('status', ['verified', 'verified_hold'])
            ->pluck('finance_organization_id')->unique()->values();

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** @return array<int,int> finance item id => stock item id (verified item mappings only) */
    private function financeToStock(int $financeOrg, array $financeIds): array
    {
        if ($financeIds === []) {
            return [];
        }
        $map = [];
        foreach (array_chunk(array_values(array_unique($financeIds)), 500) as $chunk) {
            $rows = $this->db()->table('integration_master_data_mappings')
                ->where('solastock_organization_id', $this->org())
                ->where('finance_organization_id', $financeOrg)
                ->where('entity_type', 'item')->where('status', 'verified')
                ->whereNull('conflict_code')->whereNull('error_state')
                ->where('solastock_archived', false)->where('solabooks_archived', false)
                ->whereIn('solabooks_record_id', array_map('strval', $chunk))
                ->get(['solastock_record_id', 'solabooks_record_id']);
            foreach ($rows as $r) {
                $map[(int) $r->solabooks_record_id] = (int) $r->solastock_record_id;
            }
        }

        return $map;
    }

    /** @return array<int,int[]> stock item id => finance item ids mapped to it */
    private function stockToFinance(int $financeOrg, array $stockIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($stockIds)), 500) as $chunk) {
            $rows = $this->db()->table('integration_master_data_mappings')
                ->where('solastock_organization_id', $this->org())
                ->where('finance_organization_id', $financeOrg)
                ->where('entity_type', 'item')->where('status', 'verified')
                ->whereNull('conflict_code')->whereNull('error_state')
                ->whereIn('solastock_record_id', array_map('strval', $chunk))
                ->get(['solastock_record_id', 'solabooks_record_id']);
            foreach ($rows as $r) {
                $out[(int) $r->solastock_record_id][] = (int) $r->solabooks_record_id;
            }
        }

        return $out;
    }

    /**
     * Whole union graph, deduped by (parent,part). Missing tables count as empty.
     *
     * @return list<array{parent:int,part:int,owner:string}>
     */
    public function edges(?array $itemIds = null): array
    {
        $edges = [];
        if ($this->schemaReady()) {
            $q = $this->db()->table('item_parts')->where('organization_id', $this->org());
            if ($itemIds !== null) {
                $q->where(fn ($w) => $w->whereIn('parent_item_id', $itemIds)->orWhereIn('part_item_id', $itemIds));
            }
            foreach ($q->get(['parent_item_id', 'part_item_id']) as $r) {
                $edges[(int) $r->parent_item_id.':'.(int) $r->part_item_id] = ['parent' => (int) $r->parent_item_id, 'part' => (int) $r->part_item_id, 'owner' => 'stock'];
            }
        }
        $financeOrg = $this->financeOrgId();
        if ($financeOrg !== null && Schema::connection('tenant')->hasTable(self::FINANCE_TABLE)) {
            $fq = $this->db()->table(self::FINANCE_TABLE)->where('organization_id', $financeOrg);
            if ($itemIds !== null) {
                $fids = array_merge(...array_values($this->stockToFinance($financeOrg, $itemIds)) ?: [[]]);
                if ($fids === []) {
                    $fq = null;
                } else {
                    $fq->where(fn ($w) => $w->whereIn('parent_item_id', $fids)->orWhereIn('part_item_id', $fids));
                }
            }
            $rows = $fq ? $fq->get(['parent_item_id', 'part_item_id']) : collect();
            $ids = [];
            foreach ($rows as $r) {
                $ids[] = (int) $r->parent_item_id;
                $ids[] = (int) $r->part_item_id;
            }
            $map = $this->financeToStock($financeOrg, $ids);
            foreach ($rows as $r) {
                $p = $map[(int) $r->parent_item_id] ?? null;
                $c = $map[(int) $r->part_item_id] ?? null;
                if ($p && $c && ! isset($edges[$p.':'.$c])) {
                    $edges[$p.':'.$c] = ['parent' => $p, 'part' => $c, 'owner' => 'finance'];
                }
            }
        }

        return array_values($edges);
    }

    /** @return int[] union part ids of a parent */
    public function partIds(int $parentId): array
    {
        return array_values(array_unique(array_map(
            fn ($e) => $e['part'],
            array_filter($this->edges([$parentId]), fn ($e) => $e['parent'] === $parentId)
        )));
    }

    /** @return array{parents: list<array>, parts: list<array>} */
    public function links(int $itemId): array
    {
        $edges = $this->edges([$itemId]);
        $parents = array_values(array_filter($edges, fn ($e) => $e['part'] === $itemId));
        $parts = array_values(array_filter($edges, fn ($e) => $e['parent'] === $itemId));
        $ids = array_unique(array_merge(array_column($parents, 'parent'), array_column($parts, 'part')));
        $items = $ids === [] ? collect() : Item::query()->with('category:id,name')->whereIn('id', $ids)->get(['id', 'name', 'sku', 'is_active', 'category_id'])->keyBy('id');
        $present = function (array $edges, string $key) use ($items) {
            $rows = [];
            foreach ($edges as $e) {
                $i = $items->get($e[$key]);
                if ($i) {
                    $rows[] = ['id' => (int) $i->id, 'name' => $i->name, 'sku' => $i->sku, 'is_active' => (bool) $i->is_active, 'category' => $i->category?->name, 'owner' => $e['owner']];
                }
            }
            usort($rows, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

            return $rows;
        };

        return ['parents' => $present($parents, 'parent'), 'parts' => $present($parts, 'part')];
    }

    /**
     * Batched list summaries for a page of item ids (one query per store + one name lookup).
     *
     * @param  int[]  $itemIds
     * @return array<int, array{parents: list<array{id:int,name:string,sku:?string}>, parts_count: int}> only items with links
     */
    public function summaries(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($ids === []) {
            return [];
        }
        $edges = $this->edges($ids);
        $want = array_flip($ids);
        $parentIds = [];
        foreach ($edges as $e) {
            if (isset($want[$e['part']])) {
                $parentIds[$e['parent']] = true;
            }
        }
        $names = $parentIds === [] ? collect() : Item::query()->whereIn('id', array_keys($parentIds))->get(['id', 'name', 'sku'])->keyBy('id');
        $out = [];
        $seenParts = [];
        foreach ($edges as $e) {
            if (isset($want[$e['part']]) && ($p = $names->get($e['parent']))) {
                $out[$e['part']]['parents'][] = ['id' => (int) $p->id, 'name' => (string) $p->name, 'sku' => $p->sku];
            }
            if (isset($want[$e['parent']]) && ! isset($seenParts[$e['parent'].':'.$e['part']])) {
                $seenParts[$e['parent'].':'.$e['part']] = true;
                $out[$e['parent']]['parts_count'] = ($out[$e['parent']]['parts_count'] ?? 0) + 1;
            }
        }
        foreach ($out as $id => &$row) {
            $row['parents'] ??= [];
            $row['parts_count'] ??= 0;
            usort($row['parents'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($row);

        return $out;
    }

    /** Link is added (or already present). Returns true when a row was created. */
    public function attach(int $parentId, int $partId, ?int $userId = null, string $errorKey = 'part_item_id'): bool
    {
        if ($parentId === $partId) {
            throw ValidationException::withMessages([$errorKey => __('inventory.item_parts.self')]);
        }
        $this->assertSchema();

        return $this->locked(function () use ($parentId, $partId, $userId, $errorKey) {
            $items = Item::query()->whereIn('id', [$parentId, $partId])->get(['id', 'is_active', 'sku'])->keyBy('id');
            if (! $items->has($parentId) || ! $items->has($partId)) {
                throw ValidationException::withMessages([$errorKey => __('inventory.item_parts.not_found')]);
            }
            $edges = $this->edges();
            foreach ($edges as $e) {
                if ($e['parent'] === $parentId && $e['part'] === $partId) {
                    return false;
                }
            }
            if (! $items[$parentId]->is_active || ! $items[$partId]->is_active) {
                throw ValidationException::withMessages([$errorKey => __('inventory.item_parts.inactive')]);
            }
            if ($this->reachable($edges, $partId, $parentId)) {
                throw ValidationException::withMessages([$errorKey => __('inventory.item_parts.cycle')]);
            }
            try {
                $this->db()->table('item_parts')->insert([
                    'organization_id' => $this->org(), 'parent_item_id' => $parentId, 'part_item_id' => $partId,
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (str_starts_with((string) $e->getCode(), '23')) {
                    return false;
                }
                throw $e;
            }
            $this->audit('item_part.linked', $parentId, $partId, $userId, $items[$parentId]->sku);

            return true;
        });
    }

    /** Removes the link from both stores. Missing link is a no-op. Returns true when something was removed. */
    public function detach(int $parentId, int $partId, ?int $userId = null): bool
    {
        if (! $this->schemaReady() && ! Schema::connection('tenant')->hasTable(self::FINANCE_TABLE)) {
            return false;
        }

        return $this->locked(function () use ($parentId, $partId, $userId) {
            $removed = 0;
            if ($this->schemaReady()) {
                $removed += $this->db()->table('item_parts')->where('organization_id', $this->org())
                    ->where('parent_item_id', $parentId)->where('part_item_id', $partId)->delete();
            }
            $financeOrg = $this->financeOrgId();
            if ($financeOrg !== null && Schema::connection('tenant')->hasTable(self::FINANCE_TABLE)) {
                $map = $this->stockToFinance($financeOrg, [$parentId, $partId]);
                if (! empty($map[$parentId]) && ! empty($map[$partId])) {
                    $removed += $this->db()->table(self::FINANCE_TABLE)->where('organization_id', $financeOrg)
                        ->whereIn('parent_item_id', $map[$parentId])->whereIn('part_item_id', $map[$partId])->delete();
                }
            }
            if ($removed > 0) {
                $this->audit('item_part.unlinked', $parentId, $partId, $userId, Item::query()->whereKey($parentId)->value('sku'));
            }

            return $removed > 0;
        });
    }

    /** Make the item's parents equal to $parentIds (diff only). */
    public function syncParents(int $itemId, array $parentIds, ?int $userId = null): void
    {
        $want = array_values(array_unique(array_map('intval', $parentIds)));
        $current = array_column($this->links($itemId)['parents'], 'id');
        foreach (array_diff($current, $want) as $remove) {
            $this->detach((int) $remove, $itemId, $userId);
        }
        foreach (array_diff($want, $current) as $add) {
            $this->attach((int) $add, $itemId, $userId, 'part_of_ids');
        }
    }

    /** True when $to can be reached from $from following parent -> part edges. */
    private function reachable(array $edges, int $from, int $to): bool
    {
        $children = [];
        foreach ($edges as $e) {
            $children[$e['parent']][] = $e['part'];
        }
        $seen = [$from => true];
        $stack = [$from];
        while ($stack) {
            $n = array_pop($stack);
            if ($n === $to) {
                return true;
            }
            foreach ($children[$n] ?? [] as $c) {
                if (! isset($seen[$c])) {
                    $seen[$c] = true;
                    $stack[] = $c;
                }
            }
        }

        return false;
    }

    private function assertSchema(): void
    {
        abort_unless($this->schemaReady(), 503, 'item_parts_schema_pending');
    }

    private function audit(string $action, int $parentId, int $partId, ?int $userId, ?string $ref): void
    {
        InventoryAuditLog::create([
            'organization_id' => $this->org(),
            'actor_user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => 'item',
            'entity_id' => $parentId,
            'before' => null,
            'after' => ['parent_item_id' => $parentId, 'part_item_id' => $partId],
            'document_ref' => $ref,
            'created_at' => now(),
        ]);
    }

    /** Same lock name as SolaCount so both apps serialise link writes per tenant database. */
    private function locked(callable $fn)
    {
        $db = $this->db();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $fn();
        }
        $name = 'item_parts:'.$db->getDatabaseName();
        $got = (int) ($db->selectOne('SELECT GET_LOCK(?, 10) AS l', [$name])->l ?? 0);
        if ($got !== 1) {
            abort(409, __('inventory.item_parts.busy'));
        }
        try {
            return $fn();
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS l', [$name]);
        }
    }
}
