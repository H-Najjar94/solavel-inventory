<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\CostLayerConsumption;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\Lot;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Warehouse;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE ONLY class permitted to write stock_ledger, stock_balances, and cost layer
 * remaining quantities. Everything else (documents, controllers, jobs) calls
 * post()/reverse(); nothing else mutates stock.
 *
 * post() guarantees, in ONE transaction:
 *   1. begin transaction
 *   2. resolve + lockForUpdate the affected balance rows
 *   3. validate organization ownership
 *   4. validate item / warehouse / bin / lot / serial / unit compatibility
 *   5. enforce negative-stock policy
 *   6. enforce serial uniqueness + quantity rules
 *   7. apply costing (CostingEngine)
 *   8. append immutable ledger rows
 *   9. update stock_balances in the same transaction
 *  10. update FIFO cost layers when applicable
 *  11. write audit logs
 *  12. enforce idempotency (unique idempotency_key; safe retry returns prior rows)
 *  13. reversal supported via opposite ledger rows
 *  14. NEVER updates/deletes existing ledger rows
 */
class StockLedgerService
{
    public function __construct(
        private OrganizationContext $context,
        private CostingEngine $costing,
    ) {}

    /** Tenant connection name. */
    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * Post a batch of movements atomically under a shared idempotency namespace.
     * If the namespace was already posted, returns the existing ledger rows
     * (idempotent retry — no duplication).
     *
     * @param  StockMovement[]  $movements
     * @param  string  $idempotencyNamespace  e.g. "opening_stock:42:post"
     * @return StockLedger[]
     */
    public function post(array $movements, string $idempotencyNamespace, array $audit = []): array
    {
        $orgId = $this->context->idOrFail();
        $connection = $this->connection();

        return DB::connection($connection)->transaction(function () use ($movements, $idempotencyNamespace, $orgId, $audit) {
            // Idempotency: if any row for this namespace exists, this batch already ran.
            $existing = StockLedger::query()
                ->where('idempotency_key', 'like', $idempotencyNamespace.'#%')
                ->orderBy('id')
                ->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $created = [];
            $index = 0;
            foreach ($movements as $movement) {
                $created[] = $this->applyMovement($movement, $orgId, $idempotencyNamespace, $index);
                $index++;
            }

            $this->writeAudit($orgId, $audit, $idempotencyNamespace, count($created));

            return $created;
        });
    }

    /**
     * Reverse a previously-posted namespace by appending OPPOSITE movements.
     * Never edits/deletes the original rows. Idempotent on the reversal namespace.
     *
     * @return StockLedger[]
     */
    public function reverse(string $originalNamespace, string $reversalNamespace, array $audit = []): array
    {
        $orgId = $this->context->idOrFail();
        $connection = $this->connection();

        return DB::connection($connection)->transaction(function () use ($originalNamespace, $reversalNamespace, $orgId, $audit) {
            $alreadyReversed = StockLedger::query()
                ->where('idempotency_key', 'like', $reversalNamespace.'#%')
                ->orderBy('id')
                ->get();
            if ($alreadyReversed->isNotEmpty()) {
                return $alreadyReversed->all();
            }

            $originals = StockLedger::query()
                ->where('idempotency_key', 'like', $originalNamespace.'#%')
                ->orderBy('id')
                ->get();

            if ($originals->isEmpty()) {
                throw new RuntimeException("Cannot reverse: no posted ledger rows for '{$originalNamespace}'.");
            }

            $created = [];
            $index = 0;
            // Reverse in REVERSE order so balances/layers unwind cleanly (LIFO of
            // the original sequence).
            foreach ($originals->reverse()->values() as $orig) {
                // FIFO must restore the EXACT layers the original movement touched
                // (preserving layer order + valuation), not re-cost into a blended
                // layer. Average re-costs naturally via the generic path.
                if ((string) $orig->costing_method === 'fifo') {
                    $created[] = $this->applyFifoReversal($orig, $reversalNamespace, $index);
                    $index++;
                    continue;
                }

                $reverseDirection = $orig->direction === 'in' ? 'out' : 'in';
                $movement = new StockMovement(
                    direction: $reverseDirection,
                    itemId: (int) $orig->item_id,
                    warehouseId: (int) $orig->warehouse_id,
                    quantity: (string) $orig->quantity,
                    sourceType: $orig->source_type,
                    sourceId: (int) $orig->source_id,
                    sourceLineId: $orig->source_line_id ? (int) $orig->source_line_id : null,
                    variantId: $orig->variant_id ? (int) $orig->variant_id : null,
                    zoneId: $orig->zone_id ? (int) $orig->zone_id : null,
                    binId: $orig->bin_id ? (int) $orig->bin_id : null,
                    lotId: $orig->lot_id ? (int) $orig->lot_id : null,
                    serialId: $orig->serial_id ? (int) $orig->serial_id : null,
                    unitCost: (string) $orig->unit_cost,
                    movedAt: now()->toDateTimeString(),
                );

                $created[] = $this->applyMovement(
                    $movement, $orgId, $reversalNamespace, $index, isReversal: true
                );
                $index++;
            }

            $this->writeAudit($orgId, $audit + ['action' => 'reverse'], $reversalNamespace, count($created));

            return $created;
        });
    }

    /**
     * Apply one movement: validate, lock balance, cost, append ledger, update
     * balance + layers. Assumes it runs inside an open transaction.
     */
    private function applyMovement(
        StockMovement $m,
        int $orgId,
        string $namespace,
        int $index,
        bool $isReversal = false
    ): StockLedger {
        // ── validate direction & quantity ──
        if (! in_array($m->direction, ['in', 'out'], true)) {
            throw new RuntimeException("Invalid movement direction '{$m->direction}'.");
        }
        $qty = Decimal::qty($m->quantity);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException('Movement quantity must be greater than zero.');
        }

        // ── validate item & warehouse belong to this org (defense in depth) ──
        $item = Item::query()->find($m->itemId);
        if (! $item) {
            throw new RuntimeException("Item {$m->itemId} not found in this organization.");
        }
        if ((int) $item->organization_id !== $orgId) {
            throw new RuntimeException('Cross-organization item reference rejected.');
        }
        $warehouse = Warehouse::query()->find($m->warehouseId);
        if (! $warehouse) {
            throw new RuntimeException("Warehouse {$m->warehouseId} not found in this organization.");
        }
        if ((int) $warehouse->organization_id !== $orgId) {
            throw new RuntimeException('Cross-organization warehouse reference rejected.');
        }

        // ── tracking compatibility (relaxed for reversals: original coords carried) ──
        if (! $isReversal) {
            if ($item->tracksSerials() && $m->serialId === null) {
                throw new RuntimeException("Item {$item->sku} is serial-tracked; a serial is required.");
            }
            if ($item->tracksLots() && $m->lotId === null) {
                throw new RuntimeException("Item {$item->sku} is lot-tracked; a lot is required.");
            }
        }
        if ($item->tracksSerials() && Decimal::cmp($qty, '1') !== 0) {
            throw new RuntimeException('Serial-tracked movements must have quantity exactly 1.');
        }

        // ── lot trace policy: block OUT of expired / quarantined / recalled lots
        // unless the caller carries the matching override (a permission-gated flag
        // resolved by the document service). Reversals are exempt. ──
        if (! $isReversal && $m->direction === 'out' && $m->lotId !== null) {
            $this->enforceLotPolicy($orgId, $m, $item);
        }

        $method = $item->effectiveCostingMethod();
        $settings = InventorySetting::query()->first();
        $allowNegative = (bool) ($settings->allow_negative_stock ?? false);

        // ── lock + resolve the balance row (coordinate granularity) ──
        $balance = $this->lockBalance($orgId, $m, $item);

        // ── cost ──
        $costLayerId = null;
        if ($m->direction === 'in') {
            if ($m->unitCost === null) {
                throw new RuntimeException('Inbound movements require a unit cost.');
            }
            $costed = $this->costing->costInbound(
                $method, $orgId, $m->itemId, $m->variantId, $m->warehouseId, $m->lotId,
                $qty, $m->unitCost, $m->movedAt ?? now()->toDateTimeString()
            );
            $unitCost = $costed['unit_cost'];
            $totalCost = $costed['total_cost'];
            $consumed = [];
            $costLayerId = $costed['cost_layer_id'];
        } else {
            // A reversing 'out' (undoing a prior 'in') may take stock to zero even
            // when negative stock is disabled — pass allowNegative=true in that case.
            $costed = $this->costing->costOutbound(
                $method, $orgId, $m->itemId, $m->variantId, $m->warehouseId, $m->lotId,
                $qty, $balance, $allowNegative || $isReversal
            );
            $unitCost = $costed['unit_cost'];
            $totalCost = $costed['total_cost'];
            $consumed = $costed['consumed'];
        }

        // ── negative-stock policy (skip for reversals, which undo prior stock) ──
        if ($m->direction === 'out' && ! $isReversal) {
            $newQty = Decimal::sub((string) $balance->on_hand_qty, $qty);
            if (! $allowNegative && Decimal::lt($newQty, '0')) {
                throw new RuntimeException(
                    "Insufficient stock for item {$item->sku} at warehouse {$m->warehouseId}: "
                    ."have {$balance->on_hand_qty}, need {$qty}. Negative stock disabled."
                );
            }
        }

        // ── serial uniqueness / status ──
        if ($item->tracksSerials() && $m->serialId !== null) {
            $this->applySerial($orgId, $m, $item);
        }

        // ── update balance (same transaction) ──
        [$newOnHand, $newAvg, $newValue] = $this->projectBalance($balance, $m->direction, $qty, $unitCost, $totalCost, $method);
        $balance->on_hand_qty = $newOnHand;
        $balance->average_cost = $newAvg;
        $balance->total_value = $newValue;
        $balance->last_movement_at = $m->movedAt ?? now();
        $balance->save();

        // ── append immutable ledger row ──
        $ledger = new StockLedger([
            'organization_id' => $orgId,
            'item_id' => $m->itemId,
            'variant_id' => $m->variantId,
            'warehouse_id' => $m->warehouseId,
            'zone_id' => $m->zoneId,
            'bin_id' => $m->binId,
            'lot_id' => $m->lotId,
            'serial_id' => $m->serialId,
            'expiry_date' => $m->expiryDate ?? $this->lotExpiry($orgId, $m->lotId),
            'direction' => $m->direction,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'costing_method' => $method,
            'cost_layer_id' => $consumed[0]['layer_id'] ?? ($costLayerId ?? null),
            'source_type' => $m->sourceType,
            'source_id' => $m->sourceId,
            'source_line_id' => $m->sourceLineId,
            'moved_at' => $m->movedAt ?? now(),
            'posted_at' => now(),
            'idempotency_key' => $namespace.'#'.$index,
            'balance_qty_after' => $newOnHand,
            'balance_value_after' => $newValue,
            'created_by' => auth()->id(),
        ]);
        $ledger->save();

        // Record the exact FIFO layers this OUT consumed so a reversal can
        // restore them precisely (preserving layer order + valuation) instead of
        // recreating one blended layer. A transfer also reads these to recreate
        // one destination layer per consumed source layer.
        if ($m->direction === 'out' && $consumed !== []) {
            foreach ($consumed as $c) {
                CostLayerConsumption::query()->create([
                    'organization_id' => $orgId,
                    'ledger_id' => $ledger->id,
                    'cost_layer_id' => $c['layer_id'],
                    'qty' => $c['qty'],
                    'unit_cost' => $c['unit_cost'],
                ]);
            }
        }

        return $ledger;
    }

    /**
     * Reverse a single ORIGINAL FIFO ledger movement by restoring/removing the
     * EXACT cost layers it touched (never re-costing into a blended layer):
     *   - undo an OUT → add the consumed qty back to each layer it drew from (from
     *     cost_layer_consumptions) and append a reversing IN;
     *   - undo an IN  → remove the qty from the layer it created and append a
     *     reversing OUT.
     * Balances are projected via the shared projectBalance() to stay consistent.
     */
    private function applyFifoReversal(StockLedger $orig, string $namespace, int $index): StockLedger
    {
        $orgId = $this->context->idOrFail();
        $item = Item::query()->find((int) $orig->item_id);
        if (! $item || (int) $item->organization_id !== $orgId) {
            throw new RuntimeException('Cross-organization item reference rejected during reversal.');
        }

        $reverseDirection = $orig->direction === 'in' ? 'out' : 'in';
        $qty = Decimal::qty((string) $orig->quantity);
        $unitCost = Decimal::cost((string) $orig->unit_cost);
        $totalCost = Decimal::money((string) $orig->total_cost);

        $movement = new StockMovement(
            direction: $reverseDirection,
            itemId: (int) $orig->item_id,
            warehouseId: (int) $orig->warehouse_id,
            quantity: $qty,
            sourceType: $orig->source_type,
            sourceId: (int) $orig->source_id,
            sourceLineId: $orig->source_line_id ? (int) $orig->source_line_id : null,
            variantId: $orig->variant_id ? (int) $orig->variant_id : null,
            zoneId: $orig->zone_id ? (int) $orig->zone_id : null,
            binId: $orig->bin_id ? (int) $orig->bin_id : null,
            lotId: $orig->lot_id ? (int) $orig->lot_id : null,
            serialId: $orig->serial_id ? (int) $orig->serial_id : null,
            unitCost: $unitCost,
            movedAt: now()->toDateTimeString(),
        );

        $balance = $this->lockBalance($orgId, $movement, $item);
        $layerForLedger = null;

        if ($orig->direction === 'out') {
            // RESTORE the exact layers this OUT consumed (add back per-layer qty).
            $consumptions = CostLayerConsumption::query()->where('ledger_id', $orig->id)->orderBy('id')->get();
            foreach ($consumptions as $c) {
                $layer = CostLayer::query()->lockForUpdate()->find($c->cost_layer_id);
                if ($layer) {
                    $layer->remaining_qty = Decimal::qty(Decimal::add((string) $layer->remaining_qty, (string) $c->qty));
                    $layer->save();
                }
            }
            $layerForLedger = $consumptions->first()?->cost_layer_id ? (int) $consumptions->first()->cost_layer_id : null;
        } else {
            // REMOVE the qty from the layer this IN created. If the layer no longer
            // holds the full received quantity, those units were already consumed
            // downstream (sold, transferred, adjusted out) — the receipt cannot be
            // un-received without corrupting FIFO. Reject cleanly and roll back the
            // whole reversal (atomic) instead of flooring at 0, which silently
            // collapsed layers and drove on-hand negative.
            $layerForLedger = $orig->cost_layer_id ? (int) $orig->cost_layer_id : null;
            if ($layerForLedger) {
                $layer = CostLayer::query()->lockForUpdate()->find($layerForLedger);
                if ($layer) {
                    $newRemaining = Decimal::sub((string) $layer->remaining_qty, $qty);
                    if (Decimal::lt($newRemaining, '0')) {
                        $consumedDownstream = Decimal::qty(Decimal::sub($qty, (string) $layer->remaining_qty));
                        throw new RuntimeException(
                            "Cannot reverse this inbound movement: {$consumedDownstream} unit(s) of the "
                            ."received cost layer (#{$layer->id}) were already consumed downstream "
                            .'(sold, transferred, or adjusted out). Reverse the downstream movement(s) '
                            .'first, or post a compensating adjustment.'
                        );
                    }
                    $layer->remaining_qty = Decimal::qty($newRemaining);
                    $layer->save();
                }
            }
        }

        [$newOnHand, $newAvg, $newValue] = $this->projectBalance($balance, $reverseDirection, $qty, $unitCost, $totalCost, 'fifo');
        $balance->on_hand_qty = $newOnHand;
        $balance->average_cost = $newAvg;
        $balance->total_value = $newValue;
        $balance->last_movement_at = now();
        $balance->save();

        $ledger = new StockLedger([
            'organization_id' => $orgId,
            'item_id' => $orig->item_id,
            'variant_id' => $orig->variant_id,
            'warehouse_id' => $orig->warehouse_id,
            'zone_id' => $orig->zone_id,
            'bin_id' => $orig->bin_id,
            'lot_id' => $orig->lot_id,
            'serial_id' => $orig->serial_id,
            'expiry_date' => $orig->expiry_date,
            'direction' => $reverseDirection,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'costing_method' => 'fifo',
            'cost_layer_id' => $layerForLedger,
            'source_type' => $orig->source_type,
            'source_id' => $orig->source_id,
            'source_line_id' => $orig->source_line_id,
            'moved_at' => now(),
            'posted_at' => now(),
            'idempotency_key' => $namespace.'#'.$index,
            'balance_qty_after' => $newOnHand,
            'balance_value_after' => $newValue,
            'created_by' => auth()->id(),
        ]);
        $ledger->save();

        return $ledger;
    }

    /** Lock (or create) the balance row for this movement's coordinate. */
    private function lockBalance(int $orgId, StockMovement $m, Item $item): StockBalance
    {
        // A FOR UPDATE fetch of the exact coordinate. Re-runnable so we can
        // re-acquire the lock after creating a brand-new row.
        $lockedFetch = static fn () => StockBalance::query()
            ->where('organization_id', $orgId)
            ->where('item_id', $m->itemId)
            ->where('warehouse_id', $m->warehouseId)
            ->when($m->variantId !== null, fn ($q) => $q->where('variant_id', $m->variantId), fn ($q) => $q->whereNull('variant_id'))
            ->when($m->lotId !== null, fn ($q) => $q->where('lot_id', $m->lotId), fn ($q) => $q->whereNull('lot_id'))
            ->when($m->binId !== null, fn ($q) => $q->where('bin_id', $m->binId), fn ($q) => $q->whereNull('bin_id'))
            ->lockForUpdate()
            ->first();

        if ($balance = $lockedFetch()) {
            return $balance;
        }

        // First movement at this coordinate → create the row. A CONCURRENT
        // transaction may insert the same coordinate first; its row wins the
        // (org,item,variant,warehouse,lot,bin) unique index and our INSERT throws
        // a duplicate-key error, which we swallow and then lock the existing row.
        try {
            StockBalance::query()->create([
                'organization_id' => $orgId,
                'item_id' => $m->itemId,
                'variant_id' => $m->variantId,
                'warehouse_id' => $m->warehouseId,
                'lot_id' => $m->lotId,
                'bin_id' => $m->binId,
                'on_hand_qty' => '0',
                'reserved_qty' => '0',
                'average_cost' => '0',
                'total_value' => '0',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // A concurrent transaction created it first — fall through to lock it.
        }

        // CRITICAL: re-fetch WITH the lock so the row is held FOR UPDATE before
        // any negative-stock / availability check reads on_hand/reserved off it.
        $balance = $lockedFetch();
        if (! $balance) {
            throw new RuntimeException('stock_balances row could not be locked after creation.');
        }

        return $balance;
    }

    /** Compute new on-hand, average cost, and total value after the movement. */
    private function projectBalance(StockBalance $balance, string $direction, string $qty, string $unitCost, string $totalCost, string $method): array
    {
        $prevQty = (string) $balance->on_hand_qty;
        $prevAvg = (string) $balance->average_cost;

        $newQty = $direction === 'in'
            ? Decimal::qty(Decimal::add($prevQty, $qty))
            : Decimal::qty(Decimal::sub($prevQty, $qty));

        // FIFO: total_value must track the ACTUAL cost that flowed in/out at this
        // coordinate, i.e. the running sum of ledger total_cost (in − out). Deriving
        // it from a running weighted average instead made balance.total_value drift
        // from the true FIFO value on every mixed-cost movement — the integrity
        // checker flags this as a per-coordinate "value mismatch", the visible
        // symptom of the cost-layer collapse. This matches the checker's own
        // reconciliation (ledger net total_cost vs balance.total_value) exactly, at
        // any coordinate granularity (bin-aware). average_cost is the blended unit
        // value for display only.
        if ($method === 'fifo') {
            $prevValue = (string) $balance->total_value;
            $signedCost = $direction === 'in' ? $totalCost : '-'.$totalCost;
            $newValue = Decimal::money(Decimal::add($prevValue, $signedCost));
            // Guard tiny negative dust at zero stock.
            if (Decimal::isZero($newQty)) {
                $newValue = '0.00';
            }
            $newAvg = Decimal::isZero($newQty) ? '0' : Decimal::cost(Decimal::div($newValue, $newQty));

            return [$newQty, $newAvg, $newValue];
        }

        // Average costing.
        if ($direction === 'in') {
            $newAvg = $this->costing->newWeightedAverage($prevQty, $prevAvg, $qty, $unitCost);
            $newValue = Decimal::money(Decimal::mul($newQty, $newAvg));

            return [$newQty, $newAvg, $newValue];
        }

        // out: average cost unchanged on issue; value = qty * avg.
        $newAvg = $prevAvg;
        $newValue = Decimal::money(Decimal::mul($newQty, $newAvg));

        return [$newQty, $newAvg, $newValue];
    }

    /**
     * Block issuing stock from an expired / quarantined / recalled lot unless the
     * movement carries the matching override. Keeps the lot's status authoritative
     * (a past expiry counts as expired even if the status flag lags).
     */
    private function enforceLotPolicy(int $orgId, StockMovement $m, Item $item): void
    {
        $lot = Lot::query()->where('organization_id', $orgId)->find($m->lotId);
        if (! $lot) {
            return; // lot rows are created by capture; a missing lot is caught elsewhere
        }
        $status = $lot->effectiveStatus();

        if (in_array($status, ['quarantined', 'recalled'], true) && ! $m->allowQuarantinedLot) {
            throw new RuntimeException(
                "Lot {$lot->lot_code} for item {$item->sku} is {$status} and cannot be shipped without an override."
            );
        }
        if ($status === 'expired' && ! $m->allowExpiredLot) {
            throw new RuntimeException(
                "Lot {$lot->lot_code} for item {$item->sku} is expired and cannot be shipped without an override."
            );
        }
    }

    /** Expiry date for a lot, if any — denormalized onto the ledger row. */
    private function lotExpiry(int $orgId, ?int $lotId): ?string
    {
        if ($lotId === null) {
            return null;
        }
        $date = Lot::query()->where('organization_id', $orgId)->where('id', $lotId)->value('expiry_date');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** Validate + update a serial's status/location for the movement. */
    private function applySerial(int $orgId, StockMovement $m, Item $item): void
    {
        $serial = SerialNumber::query()->where('organization_id', $orgId)->find($m->serialId);
        if (! $serial) {
            throw new RuntimeException("Serial {$m->serialId} not found.");
        }
        if ((int) $serial->item_id !== $m->itemId) {
            throw new RuntimeException('Serial does not belong to the movement item.');
        }

        if ($m->direction === 'in') {
            if ($serial->status === 'in_stock') {
                throw new RuntimeException("Serial {$serial->serial} is already in stock.");
            }
            $serial->status = 'in_stock';
            $serial->warehouse_id = $m->warehouseId;
            $serial->bin_id = $m->binId;
        } else {
            if ($serial->status !== 'in_stock') {
                throw new RuntimeException("Serial {$serial->serial} is not in stock (status: {$serial->status}).");
            }
            $serial->status = 'sold';
        }
        $serial->save();
    }

    private function writeAudit(int $orgId, array $audit, string $namespace, int $rows): void
    {
        InventoryAuditLog::create([
            'organization_id' => $orgId,
            'actor_user_id' => auth()->id(),
            'action' => $audit['action'] ?? 'stock.post',
            'entity_type' => $audit['entity_type'] ?? 'stock_ledger',
            'entity_id' => $audit['entity_id'] ?? null,
            'before' => null,
            'after' => ['namespace' => $namespace, 'rows' => $rows],
            'document_ref' => $audit['document_ref'] ?? $namespace,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
