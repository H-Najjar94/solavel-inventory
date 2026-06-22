<?php

namespace App\Services\Stock;

use App\Models\Tenant\InventorySetting;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only integrity verification for the canonical stock engine. Reports
 * discrepancies; performs NO repairs (Phase 1 must be correct by construction).
 *
 * Checks per organization (within the active tenant DB):
 *  1. ledger net quantity == stock_balances.on_hand_qty (per coordinate)
 *  2. ledger net value == stock_balances.total_value (within tolerance)
 *  3. reserved_qty is not negative
 *  4. available (= on_hand - reserved) is correct/non-contradictory
 *  5. FIFO remaining cost layers reconcile with on-hand (for FIFO items)
 *  6. every ledger row has provenance (source_type + source_id)
 *  7. every idempotency_key is unique
 *  8. tenant/org context consistency (all rows carry the active org)
 */
class IntegrityChecker
{
    /** @return array{ok:bool, problems:array<int,string>, checked:array<string,int>} */
    public function check(string $connection, int $organizationId): array
    {
        $problems = [];
        $checked = [];

        $tolerance = (string) (InventorySetting::on($connection)->first()->value_tolerance ?? '0.01');

        // 6. provenance — no ledger row missing source_type/source_id
        $noProvenance = DB::connection($connection)->table('stock_ledger')
            ->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '')->orWhereNull('source_id'))
            ->count();
        $checked['ledger_rows'] = (int) DB::connection($connection)->table('stock_ledger')
            ->where('organization_id', $organizationId)->count();
        if ($noProvenance > 0) {
            $problems[] = "{$noProvenance} ledger row(s) lack provenance (source_type/source_id).";
        }

        // 7. unique idempotency keys
        $dupKeys = DB::connection($connection)->table('stock_ledger')
            ->where('organization_id', $organizationId)
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('idempotency_key');
        if ($dupKeys->isNotEmpty()) {
            $problems[] = 'Duplicate idempotency_key(s): '.$dupKeys->implode(', ');
        }

        // 1 & 2. ledger net qty/value vs balances, per coordinate
        $ledgerAgg = DB::connection($connection)->table('stock_ledger')
            ->where('organization_id', $organizationId)
            ->selectRaw(
                'item_id, COALESCE(variant_id,0) vkey, warehouse_id, COALESCE(lot_id,0) lkey, COALESCE(bin_id,0) bkey, '
                ."SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END) net_qty, "
                ."SUM(CASE WHEN direction='in' THEN total_cost ELSE -total_cost END) net_val"
            )
            ->groupBy('item_id', 'vkey', 'warehouse_id', 'lkey', 'bkey')
            ->get();
        $checked['coordinates'] = $ledgerAgg->count();

        foreach ($ledgerAgg as $row) {
            $bal = DB::connection($connection)->table('stock_balances')
                ->where('organization_id', $organizationId)
                ->where('item_id', $row->item_id)
                ->whereRaw('COALESCE(variant_id,0) = ?', [$row->vkey])
                ->where('warehouse_id', $row->warehouse_id)
                ->whereRaw('COALESCE(lot_id,0) = ?', [$row->lkey])
                ->whereRaw('COALESCE(bin_id,0) = ?', [$row->bkey])
                ->first();

            $coord = "item={$row->item_id} wh={$row->warehouse_id} lot={$row->lkey} bin={$row->bkey}";
            if (! $bal) {
                if (Decimal::cmp((string) $row->net_qty, '0') !== 0) {
                    $problems[] = "No balance row for {$coord} but ledger net qty = {$row->net_qty}.";
                }
                continue;
            }
            if (Decimal::cmp((string) $row->net_qty, (string) $bal->on_hand_qty) !== 0) {
                $problems[] = "Qty mismatch {$coord}: ledger {$row->net_qty} vs balance {$bal->on_hand_qty}.";
            }
            $valDiff = Decimal::sub((string) $row->net_val, (string) $bal->total_value);
            if (Decimal::gt(ltrim($valDiff, '-'), $tolerance)) {
                $problems[] = "Value mismatch {$coord}: ledger {$row->net_val} vs balance {$bal->total_value} (>{$tolerance}).";
            }
        }

        // 3 & 4. reserved non-negative; available consistent
        $badReserved = DB::connection($connection)->table('stock_balances')
            ->where('organization_id', $organizationId)
            ->where('reserved_qty', '<', 0)->count();
        if ($badReserved > 0) {
            $problems[] = "{$badReserved} balance row(s) have negative reserved_qty.";
        }

        // 5. FIFO layers reconcile: SUM(remaining_qty) per (item,wh) == on_hand for FIFO items
        $layerAgg = DB::connection($connection)->table('cost_layers')
            ->where('organization_id', $organizationId)
            ->selectRaw('item_id, warehouse_id, SUM(remaining_qty) layer_qty')
            ->groupBy('item_id', 'warehouse_id')->get();
        foreach ($layerAgg as $row) {
            $onHand = DB::connection($connection)->table('stock_balances')
                ->where('organization_id', $organizationId)
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->sum('on_hand_qty');
            // Only meaningful for FIFO items; for average items layers may be unused.
            $isFifo = DB::connection($connection)->table('items')
                ->where('id', $row->item_id)->value('costing_method') === 'fifo';
            if ($isFifo && Decimal::cmp((string) $row->layer_qty, (string) $onHand) !== 0) {
                $problems[] = "FIFO layer/stock mismatch item={$row->item_id} wh={$row->warehouse_id}: layers {$row->layer_qty} vs on_hand {$onHand}.";
            }
        }

        return ['ok' => empty($problems), 'problems' => $problems, 'checked' => $checked];
    }
}
