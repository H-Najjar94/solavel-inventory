<?php

namespace App\Services\Integration;

use App\Models\Tenant\InventoryAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * SolaStock half of a SolaCount party merge: re-points every Stock-owned reference
 * from the source party to the target and archives the source. Idempotent.
 * Writes only Stock tables (never Finance tables) through the tenant connection.
 */
final class LinkedPartyMerge
{
    /** table => column, per entity. */
    public const REFERENCES = [
        'supplier' => [
            'inventory_purchase_orders' => 'supplier_id',
            'goods_receipts' => 'supplier_id',
            'purchase_order_backorders' => 'supplier_id',
            'lots' => 'supplier_id',
            'supplier_returns' => 'supplier_id',
            'purchasing_receiving_requests' => 'supplier_id',
            'supplier_price_lists' => 'supplier_id',
            'items' => 'preferred_supplier_id',
        ],
        'customer' => [
            'inventory_sales_orders' => 'customer_id',
            'sales_returns' => 'customer_id',
            'sales_fulfillment_requests' => 'customer_id',
        ],
    ];

    private const PARTY_TABLE = ['supplier' => 'inventory_suppliers', 'customer' => 'inventory_customers'];

    public function merge(int $orgId, string $type, int $sourceId, int $targetId): array
    {
        $this->fail($type !== 'supplier' && $type !== 'customer', 422, 'party_merge_type_mismatch');
        $this->fail($sourceId === $targetId, 422, 'party_merge_same_party');
        $db = DB::connection('tenant');
        $table = self::PARTY_TABLE[$type];

        return PartySyncLedger::withoutTracking(fn () => $db->transaction(function () use ($db, $table, $type, $orgId, $sourceId, $targetId) {
            $rows = $db->table($table)->where('organization_id', $orgId)->whereIn('id', [$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $rows->get($sourceId);
            $target = $rows->get($targetId);
            $this->fail(! $source || ! $target, 404, 'party_merge_not_found');
            $marker = (array) (json_decode((string) $source->contact, true) ?: []);
            if (! $source->is_active && (int) ($marker['merged_into'] ?? 0) === $targetId) {
                return ['ok' => true, 'already_merged' => true, 'counts' => [], 'dropped' => []];
            }
            $this->fail(! $target->is_active || $target->deleted_at !== null, 409, 'party_merge_target_archived');
            $this->fail(($marker['merged_into'] ?? null) !== null && (int) $marker['merged_into'] !== $targetId, 409, 'party_merge_source_merged_elsewhere');

            $counts = [];
            $dropped = [];
            if ($type === 'supplier') {
                $this->mergeItemSuppliers($db, $orgId, $sourceId, $targetId, $counts, $dropped);
            }
            foreach (self::REFERENCES[$type] as $ref => $column) {
                if (! Schema::connection('tenant')->hasTable($ref) || ! Schema::connection('tenant')->hasColumn($ref, $column)) {
                    continue;
                }
                $n = $db->table($ref)->where('organization_id', $orgId)->where($column, $sourceId)->update([$column => $targetId]);
                if ($n) {
                    $counts[$ref] = ($counts[$ref] ?? 0) + $n;
                }
            }
            $n = $this->retargetScheduledReports($db, $orgId, $type.'_id', $sourceId, $targetId);
            if ($n) {
                $counts['inventory_scheduled_reports'] = $n;
            }

            $marker['merged_into'] = $targetId;
            $marker['merged_at'] = now()->toIso8601String();
            $db->table($table)->where('id', $sourceId)->update(['is_active' => false, 'contact' => json_encode($marker), 'updated_at' => now()]);
            $counts[$table] = ($counts[$table] ?? 0) + 1;

            InventoryAuditLog::create([
                'organization_id' => $orgId, 'actor_user_id' => null, 'action' => $type.'.party_merge',
                'entity_type' => $type, 'entity_id' => $sourceId,
                'before' => ['source_id' => $sourceId, 'is_active' => (bool) $source->is_active],
                'after' => ['target_id' => $targetId, 'counts' => $counts, 'dropped' => $dropped],
                'document_ref' => 'party-merge:'.$type.':'.$sourceId.':'.$targetId, 'created_at' => now(),
            ]);

            return ['ok' => true, 'already_merged' => false, 'counts' => $counts, 'dropped' => $dropped];
        }));
    }

    /** Unique (org,item,supplier): keep the target's row, move the source's price tiers to it, drop the duplicate. */
    private function mergeItemSuppliers($db, int $orgId, int $sourceId, int $targetId, array &$counts, array &$dropped): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('item_suppliers')) {
            return;
        }
        $hasTiers = $schema->hasTable('supplier_price_lists') && $schema->hasColumn('supplier_price_lists', 'item_supplier_id');
        foreach ($db->table('item_suppliers')->where('organization_id', $orgId)->where('supplier_id', $sourceId)->get() as $row) {
            $keep = $db->table('item_suppliers')->where('organization_id', $orgId)->where('supplier_id', $targetId)->where('item_id', $row->item_id)->first();
            if ($keep) {
                if ($hasTiers) {
                    $db->table('supplier_price_lists')->where('item_supplier_id', $row->id)->update(['item_supplier_id' => $keep->id]);
                }
                $db->table('item_suppliers')->where('id', $row->id)->delete();
                $dropped['item_suppliers'] = ($dropped['item_suppliers'] ?? 0) + 1;
            } else {
                $db->table('item_suppliers')->where('id', $row->id)->update(['supplier_id' => $targetId]);
                $counts['item_suppliers'] = ($counts['item_suppliers'] ?? 0) + 1;
            }
        }
    }

    private function retargetScheduledReports($db, int $orgId, string $key, int $sourceId, int $targetId): int
    {
        if (! Schema::connection('tenant')->hasTable('inventory_scheduled_reports')) {
            return 0;
        }
        $n = 0;
        foreach ($db->table('inventory_scheduled_reports')->where('organization_id', $orgId)->whereNotNull('filters')->get(['id', 'filters']) as $r) {
            $f = json_decode((string) $r->filters, true);
            if (is_array($f) && isset($f[$key]) && (int) $f[$key] === $sourceId) {
                $f[$key] = $targetId;
                $db->table('inventory_scheduled_reports')->where('id', $r->id)->update(['filters' => json_encode($f)]);
                $n++;
            }
        }

        return $n;
    }

    private function fail(bool $condition, int $status, string $code): void
    {
        if ($condition) {
            throw new HttpException($status, $code);
        }
    }
}
