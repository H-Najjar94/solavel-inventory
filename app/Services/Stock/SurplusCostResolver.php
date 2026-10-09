<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

/**
 * One valuation policy for stock gains that carry no entered cost: an
 * adjustment increase line without unit_cost and every stock-count surplus.
 *
 * - average: the coordinate's current average cost (on hand > 0), else the
 *   item's warehouse-wide average (value / quantity) for that variant.
 * - fifo: the latest cost layer for the item/warehouse/variant (and lot when
 *   one is given) — the same "last known layer cost" CostingEngine uses for a
 *   negative-stock FIFO shortfall.
 * - fallback for both: the latest inbound ledger cost for the item/variant.
 *
 * A gain is never silently valued at zero because no cost exists: with no cost
 * history at all it fails, so the user enters a unit cost instead. A recorded
 * zero cost (e.g. free samples) is a real basis and is reused as is.
 */
final class SurplusCostResolver
{
    public function unitCost(int $itemId, int $warehouseId, ?int $variantId = null, ?int $lotId = null, ?int $binId = null): string
    {
        $item = Item::query()->findOrFail($itemId);
        $method = $item->effectiveCostingMethod();

        $cost = $method === 'fifo'
            ? $this->latestLayerCost($itemId, $warehouseId, $variantId, $lotId)
            : $this->averageCost($itemId, $warehouseId, $variantId, $lotId, $binId);
        $cost ??= $this->latestLayerCost($itemId, $warehouseId, $variantId, null);
        $cost ??= $this->latestInboundCost($itemId, $variantId);
        if ($cost === null) {
            throw new RuntimeException(__('inventory.stock.surplus_cost_required', ['sku' => (string) $item->sku]));
        }

        return Decimal::cost($cost);
    }

    private function averageCost(int $itemId, int $warehouseId, ?int $variantId, ?int $lotId, ?int $binId): ?string
    {
        $coordinate = StockBalance::query()
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId), fn ($q) => $q->whereNull('lot_id'))
            ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
            ->where('on_hand_qty', '>', 0)
            ->first();
        if ($coordinate) {
            return (string) $coordinate->average_cost;
        }

        $rows = StockBalance::query()
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->where('on_hand_qty', '>', 0)
            ->get(['on_hand_qty', 'total_value']);
        $qty = '0';
        $value = '0';
        foreach ($rows as $row) {
            $qty = Decimal::add($qty, (string) $row->on_hand_qty);
            $value = Decimal::add($value, (string) $row->total_value);
        }

        return Decimal::gt($qty, '0') ? Decimal::cost(Decimal::div($value, $qty)) : null;
    }

    private function latestLayerCost(int $itemId, int $warehouseId, ?int $variantId, ?int $lotId): ?string
    {
        $cost = CostLayer::query()
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->value('unit_cost');

        return $cost === null ? null : (string) $cost;
    }

    private function latestInboundCost(int $itemId, ?int $variantId): ?string
    {
        $cost = StockLedger::query()
            ->where('item_id', $itemId)->where('direction', 'in')
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->orderByDesc('id')
            ->value('unit_cost');

        return $cost === null ? null : (string) $cost;
    }
}
