<?php

namespace App\Services\Stock\Support;

use Illuminate\Support\Collection;

/**
 * Item stock status from balance rows (one per item/warehouse/...), each with
 * item_id, available (on hand − reserved) and the effective reorder point
 * (warehouse rule, else item). Statuses are per ITEM, never per balance row:
 *   out — no available stock in any warehouse in scope;
 *   low — otherwise, some warehouse is at or below its reorder point;
 *   ok  — everything else (no reorder point means never "low").
 */
final class StockStatus
{
    /** @return array<int, string> item_id => out|low|ok */
    public static function byItem(iterable $rows): array
    {
        $perWarehouse = [];
        $totals = [];
        foreach ($rows as $row) {
            $item = (int) $row->item_id;
            $key = $item.':'.(int) ($row->warehouse_id ?? 0);
            $available = (float) $row->available;
            $totals[$item] = ($totals[$item] ?? 0) + $available;
            $perWarehouse[$key] ??= ['item' => $item, 'available' => 0.0, 'reorder' => $row->reorder_point];
            $perWarehouse[$key]['available'] += $available;
        }

        $status = [];
        foreach ($totals as $item => $total) {
            $status[$item] = $total <= 0 ? 'out' : 'ok';
        }
        foreach ($perWarehouse as $wh) {
            if ($status[$wh['item']] === 'ok' && $wh['reorder'] !== null && $wh['available'] <= (float) $wh['reorder']) {
                $status[$wh['item']] = 'low';
            }
        }

        return $status;
    }

    /** Balance rows with the effective reorder point, for StockStatus::byItem. */
    public static function balanceRows($query, string $b = 'b'): Collection
    {
        return $query
            ->join('items as i', 'i.id', '=', $b.'.item_id')
            ->leftJoin('warehouse_reorder_rules as rr', function ($join) use ($b) {
                $join->on('rr.item_id', '=', $b.'.item_id')
                    ->on('rr.warehouse_id', '=', $b.'.warehouse_id')
                    ->on('rr.organization_id', '=', $b.'.organization_id');
            })
            ->where('i.item_type', 'inventory')
            ->selectRaw("{$b}.item_id, {$b}.warehouse_id, ({$b}.on_hand_qty - {$b}.reserved_qty) available, COALESCE(rr.reorder_point, i.reorder_point) reorder_point")
            ->get();
    }
}
