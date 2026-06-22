<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\StockBalance;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

/**
 * Costing strategies. Decimal-safe (bcmath only). Used ONLY by StockLedgerService.
 *
 * Supported: 'average' (weighted average), 'fifo' (cost layers).
 * 'standard' is intentionally unsupported in Phase 1 but structured so it can be
 * added as another branch later.
 *
 * Returns, for each movement, the unit_cost/total_cost to record on the ledger,
 * and (for FIFO) mutates cost layers' remaining quantities. Layer mutations are
 * permitted here because this class IS part of the approved stock engine
 * namespace (App\Services\Stock\*) — see the architecture guard test.
 */
class CostingEngine
{
    /**
     * Cost an INBOUND movement.
     * - average: caller-supplied unit cost is used; the new average is computed by
     *   StockLedgerService from the resulting balance (kept there to stay atomic).
     * - fifo: create a cost layer for the received quantity at the received cost.
     *
     * @return array{unit_cost:string,total_cost:string,cost_layer_id:?int}
     */
    public function costInbound(
        string $method,
        int $organizationId,
        int $itemId,
        ?int $variantId,
        int $warehouseId,
        ?int $lotId,
        string $quantity,
        string $unitCost,
        string $movedAt
    ): array {
        $this->assertSupported($method);

        $unitCost = Decimal::cost($unitCost);
        $totalCost = Decimal::money(Decimal::mul($quantity, $unitCost));

        $layerId = null;
        if ($method === 'fifo') {
            $layer = new CostLayer([
                'organization_id' => $organizationId,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'received_at' => $movedAt,
                'unit_cost' => $unitCost,
                'original_qty' => Decimal::qty($quantity),
                'remaining_qty' => Decimal::qty($quantity),
            ]);
            $layer->save();
            $layerId = (int) $layer->id;
        }

        return [
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'cost_layer_id' => $layerId,
        ];
    }

    /**
     * Cost an OUTBOUND movement.
     * - average: use the balance's current average cost.
     * - fifo: consume oldest layers first (partial allowed); COGS is the sum of
     *   consumed_qty * layer_unit_cost.
     *
     * @param  StockBalance|null  $balance  current balance row (for average cost)
     * @return array{unit_cost:string,total_cost:string,consumed:array<int,array{layer_id:int,qty:string,unit_cost:string}>}
     */
    public function costOutbound(
        string $method,
        int $organizationId,
        int $itemId,
        ?int $variantId,
        int $warehouseId,
        ?int $lotId,
        string $quantity,
        ?StockBalance $balance,
        bool $allowNegative
    ): array {
        $this->assertSupported($method);

        if ($method === 'average') {
            $avg = $balance ? (string) $balance->average_cost : '0';
            $unitCost = Decimal::cost($avg);
            $totalCost = Decimal::money(Decimal::mul($quantity, $unitCost));

            return ['unit_cost' => $unitCost, 'total_cost' => $totalCost, 'consumed' => []];
        }

        // FIFO consumption.
        $remainingToConsume = Decimal::qty($quantity);
        $consumed = [];
        $totalCost = '0';

        $layers = CostLayer::query()
            ->where('organization_id', $organizationId)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->when($variantId === null, fn ($q) => $q->whereNull('variant_id'))
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId))
            ->where('remaining_qty', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if (Decimal::isZero($remainingToConsume)) {
                break;
            }

            $available = (string) $layer->remaining_qty;
            $take = Decimal::gte($available, $remainingToConsume) ? $remainingToConsume : $available;

            $layerCost = Decimal::mul($take, (string) $layer->unit_cost);
            $totalCost = Decimal::add($totalCost, $layerCost);

            // Mutate layer remaining (allowed: inside stock engine).
            $layer->remaining_qty = Decimal::qty(Decimal::sub($available, $take));
            $layer->save();

            $consumed[] = [
                'layer_id' => (int) $layer->id,
                'qty' => Decimal::qty($take),
                'unit_cost' => Decimal::cost((string) $layer->unit_cost),
            ];

            $remainingToConsume = Decimal::sub($remainingToConsume, $take);
        }

        if (Decimal::gt($remainingToConsume, '0')) {
            if (! $allowNegative) {
                throw new RuntimeException(
                    'FIFO: insufficient cost layers to satisfy outbound quantity '
                    ."(short by {$remainingToConsume}). Negative stock is disabled."
                );
            }
            // Negative allowed: cost the shortfall at last known layer cost or 0.
            $fallback = $layers->last()?->unit_cost ?? ($balance->average_cost ?? '0');
            $shortCost = Decimal::mul($remainingToConsume, (string) $fallback);
            $totalCost = Decimal::add($totalCost, $shortCost);
        }

        $totalCost = Decimal::money($totalCost);
        $unitCost = Decimal::isZero($quantity)
            ? '0'
            : Decimal::cost(Decimal::div($totalCost, $quantity));

        return ['unit_cost' => $unitCost, 'total_cost' => $totalCost, 'consumed' => $consumed];
    }

    /**
     * New weighted average after an inbound: (prevQty*prevAvg + inQty*inCost) / (prevQty+inQty).
     * Deterministic decimal math.
     */
    public function newWeightedAverage(
        string $prevQty,
        string $prevAvg,
        string $inQty,
        string $inCost
    ): string {
        $prevValue = Decimal::mul($prevQty, $prevAvg);
        $inValue = Decimal::mul($inQty, $inCost);
        $totalQty = Decimal::add($prevQty, $inQty);

        if (Decimal::isZero($totalQty)) {
            return '0';
        }

        return Decimal::cost(Decimal::div(Decimal::add($prevValue, $inValue), $totalQty));
    }

    private function assertSupported(string $method): void
    {
        if (! in_array($method, ['average', 'fifo'], true)) {
            throw new RuntimeException(
                "Costing method '{$method}' is not supported in Phase 1 (only 'average' and 'fifo')."
            );
        }
    }
}
