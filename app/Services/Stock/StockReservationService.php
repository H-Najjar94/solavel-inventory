<?php

namespace App\Services\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\Item;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manages soft stock holds. Reservation reduces AVAILABLE (on_hand − reserved)
 * but never moves on_hand — so NO stock_ledger rows are written. It does update
 * stock_balances.reserved_qty (a projection field), which is why it lives in the
 * approved App\Services\Stock namespace alongside StockLedgerService.
 *
 * Idempotent per (source_type, source_id, item, warehouse): re-reserving the same
 * source does not double-count.
 */
class StockReservationService
{
    public function __construct(private OrganizationContext $context) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * Reserve qty for an item at a warehouse against a source document.
     * Throws if it would exceed available and negative stock is disabled.
     */
    public function reserve(
        int $itemId,
        int $warehouseId,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?int $binId = null,
        ?int $lotId = null,
        ?Carbon $expiresAt = null,
        int $priority = 100
    ): Reservation {
        $reservation = $this->reserveInternal($itemId, $warehouseId, $qty, $sourceType, $sourceId, $binId, $lotId, $expiresAt, $priority, false);
        if (! $reservation) {
            throw new RuntimeException(__('inventory.stock.reservation_create_failed'));
        }

        return $reservation;
    }

    public function reserveAvailable(
        int $itemId,
        int $warehouseId,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?int $binId = null,
        ?int $lotId = null,
        ?Carbon $expiresAt = null,
        int $priority = 100
    ): ?Reservation {
        return $this->reserveInternal($itemId, $warehouseId, $qty, $sourceType, $sourceId, $binId, $lotId, $expiresAt, $priority, true);
    }

    public function reserveSerial(
        int $itemId,
        int $warehouseId,
        int $serialId,
        string $sourceType,
        int $sourceId,
        ?Carbon $expiresAt = null,
        int $priority = 100,
    ): Reservation {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $warehouseId, $serialId, $sourceType, $sourceId, $expiresAt, $priority) {
            if ($sourceType === 'sales_order') app(\App\Services\Sales\FulfillmentRequestService::class)->guardOrderDemand($sourceId);
            $serial = SerialNumber::query()->where('organization_id', $orgId)->lockForUpdate()->find($serialId);
            if (! $serial || (int) $serial->item_id !== $itemId || (int) $serial->warehouse_id !== $warehouseId) {
                throw new RuntimeException(__('inventory.stock.serial_unavailable'));
            }
            if (! in_array($serial->status, ['available', 'in_stock', 'returned'], true)) {
                throw new RuntimeException("Serial {$serial->serial} is not available (status: {$serial->status}).");
            }

            $active = Reservation::query()->where('serial_id', $serialId)->where('status', 'active')->lockForUpdate()->first();
            if ($active) {
                if ($active->source_type === $sourceType && (int) $active->source_id === $sourceId) {
                    return $active;
                }

                throw new RuntimeException("Serial {$serial->serial} is already reserved.");
            }

            return $this->reserveInternal(
                $itemId,
                $warehouseId,
                '1',
                $sourceType,
                $sourceId,
                $serial->bin_id ? (int) $serial->bin_id : null,
                $serial->lot_id ? (int) $serial->lot_id : null,
                $expiresAt,
                $priority,
                false,
                $serialId,
            );
        });
    }

    /**
     * Reserve across the item's balance coordinates. Traceable receipts are
     * projected one row per lot, so an item-level reservation must not inspect
     * only the NULL-lot aggregate. Allocations are ordered FEFO when an expiry
     * date exists and then by lot id as the deterministic receipt-order tie
     * break. The returned reservations are the concrete coordinates held.
     *
     * @return array<int, Reservation>
     */
    public function reserveAvailableAcrossLots(
        int $itemId,
        int $warehouseId,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?int $binId = null,
        ?Carbon $expiresAt = null,
        int $priority = 100,
    ): array {
        $orgId = $this->context->idOrFail();
        $requested = Decimal::qty($qty);
        if (! Decimal::gt($requested, '0')) {
            throw new RuntimeException(__('inventory.stock.reservation_positive'));
        }

        return DB::connection($this->conn())->transaction(function () use (
            $orgId, $itemId, $warehouseId, $requested, $sourceType, $sourceId,
            $binId, $expiresAt, $priority,
        ) {
            $this->expireOverdue(null, null, $itemId, $warehouseId);
            $balances = StockBalance::query()
                ->where('organization_id', $orgId)
                ->where('item_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
                ->with('lot')
                ->get()
                ->sortBy(function (StockBalance $balance): string {
                    $expiry = $balance->lot?->expiry_date?->toDateString() ?? '9999-12-31';

                    return $expiry.'|'.str_pad((string) ($balance->lot_id ?? 0), 12, '0', STR_PAD_LEFT);
                });

            $remaining = $requested;
            $allocations = [];
            foreach ($balances as $balance) {
                $available = Decimal::sub((string) $balance->on_hand_qty, (string) $balance->reserved_qty);
                if (! Decimal::gt($available, '0')) {
                    continue;
                }
                $take = ! Decimal::gt($remaining, $available) ? $remaining : $available;
                $allocations[] = [$balance->lot_id ? (int) $balance->lot_id : null, $take];
                $remaining = Decimal::sub($remaining, $take);
                if (! Decimal::gt($remaining, '0')) {
                    break;
                }
            }

            $allowNegative = (bool) (InventorySetting::query()->first()->allow_negative_stock ?? false);
            if (Decimal::gt($remaining, '0') && ! $allowNegative) {
                throw new RuntimeException("Cannot reserve {$requested}: only ".Decimal::sub($requested, $remaining).' available for item #'.$itemId.' at warehouse #'.$warehouseId.'.');
            }

            $result = [];
            foreach ($allocations as [$lotId, $take]) {
                $result[] = $this->reserveInternal(
                    $itemId, $warehouseId, $take, $sourceType, $sourceId,
                    $binId, $lotId, $expiresAt, $priority, false,
                );
            }

            return array_values(array_filter($result));
        });
    }

    /**
     * Release overdue reservations and reconcile their balance projections.
     * Existing status values are preserved by marking expired rows as released
     * with expired_at set, avoiding a destructive enum change.
     */
    public function expireOverdue(?string $sourceType = null, ?int $sourceId = null, ?int $itemId = null, ?int $warehouseId = null): int
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($orgId, $sourceType, $sourceId, $itemId, $warehouseId) {
            $now = now();
            $query = Reservation::query()
                ->where('organization_id', $orgId)
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->when($sourceType !== null, fn ($q) => $q->where('source_type', $sourceType))
                ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
                ->when($itemId !== null, fn ($q) => $q->where('item_id', $itemId))
                ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->lockForUpdate();

            $count = 0;
            $sources = [];
            foreach ($query->get() as $res) {
                $balance = $this->lockBalance($orgId, (int) $res->item_id, (int) $res->warehouse_id, $res->bin_id ? (int) $res->bin_id : null, $res->lot_id ? (int) $res->lot_id : null);
                $newReserved = Decimal::sub((string) $balance->reserved_qty, (string) $res->qty);
                $balance->reserved_qty = Decimal::lt($newReserved, '0') ? '0.0000' : Decimal::qty($newReserved);
                $balance->save();

                $res->status = 'released';
                $res->expired_at = $now;
                $res->released_at = $now;
                $res->save();
                $sources[$res->source_type.':'.$res->source_id] = [$res->source_type, (int) $res->source_id];
                $count++;
            }

            foreach ($sources as [$type, $id]) {
                $this->syncSourceProjection($type, $id);
            }

            return $count;
        });
    }

    private function reserveInternal(
        int $itemId,
        int $warehouseId,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?int $binId,
        ?int $lotId,
        ?Carbon $expiresAt,
        int $priority,
        bool $partial,
        ?int $serialId = null,
    ): ?Reservation {
        $orgId = $this->context->idOrFail();
        $qty = Decimal::qty($qty);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException(__('inventory.stock.reservation_positive'));
        }
        $priority = max(1, min(999, $priority));
        $this->expireOverdue(null, null, $itemId, $warehouseId);

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $warehouseId, $qty, $sourceType, $sourceId, $binId, $lotId, $expiresAt, $priority, $partial, $serialId) {
            if ($sourceType === 'sales_order') app(\App\Services\Sales\FulfillmentRequestService::class)->guardOrderDemand($sourceId);
            $balance = $this->lockBalance($orgId, $itemId, $warehouseId, $binId, $lotId);

            // Idempotent active reservation per source+coordinate.
            $existing = Reservation::query()
                ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', 'active')
                ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
                ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId), fn ($q) => $q->whereNull('lot_id'))
                ->when($serialId !== null, fn ($q) => $q->where('serial_id', $serialId), fn ($q) => $q->whereNull('serial_id'))
                ->first();

            $available = Decimal::sub((string) $balance->on_hand_qty, (string) $balance->reserved_qty);
            $effectiveAvailable = $existing
                ? Decimal::add($available, (string) $existing->qty)
                : $available;
            $allowNegative = (bool) (InventorySetting::query()->first()->allow_negative_stock ?? false);
            if (! $allowNegative && Decimal::gt($qty, $effectiveAvailable)) {
                if (! $partial || ! Decimal::gt($effectiveAvailable, '0')) {
                    throw new RuntimeException("Cannot reserve {$qty}: only {$effectiveAvailable} available for item #{$itemId} at warehouse #{$warehouseId}.");
                }
                $qty = Decimal::qty($effectiveAvailable);
            }

            if ($existing) {
                // adjust delta into reserved_qty
                $delta = Decimal::sub($qty, (string) $existing->qty);
                $existing->qty = $qty;
                $existing->expires_at = $expiresAt;
                $existing->priority = $priority;
                $existing->save();
                $balance->reserved_qty = Decimal::qty(Decimal::add((string) $balance->reserved_qty, $delta));
                $balance->save();

                return $existing;
            }

            $reservation = Reservation::create([
                'organization_id' => $orgId,
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'bin_id' => $binId,
                'lot_id' => $lotId,
                'serial_id' => $serialId,
                'qty' => $qty,
                'priority' => $priority,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            $balance->reserved_qty = Decimal::qty(Decimal::add((string) $balance->reserved_qty, $qty));
            $balance->save();

            return $reservation;
        });
    }

    /** Release a reservation (or all active reservations for a source). */
    public function release(string $sourceType, int $sourceId, ?int $reservationId = null): int
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($orgId, $sourceType, $sourceId, $reservationId) {
            if ($sourceType === 'sales_order') app(\App\Services\Sales\FulfillmentRequestService::class)->guardOrderDemand($sourceId);
            $query = Reservation::query()->where('organization_id', $orgId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', 'active')
                ->when($reservationId, fn ($q) => $q->where('id', $reservationId));

            $count = 0;
            foreach ($query->get() as $res) {
                $balance = $this->lockBalance($orgId, (int) $res->item_id, (int) $res->warehouse_id, $res->bin_id ? (int) $res->bin_id : null, $res->lot_id ? (int) $res->lot_id : null);
                $newReserved = Decimal::sub((string) $balance->reserved_qty, (string) $res->qty);
                $balance->reserved_qty = Decimal::lt($newReserved, '0') ? '0.0000' : Decimal::qty($newReserved);
                $balance->save();

                $res->status = 'released';
                $res->released_at = now();
                $res->save();
                $count++;
            }

            $this->syncSourceProjection($sourceType, $sourceId);

            return $count;
        });
    }

    /** Mark reservations consumed (called when a shipment posts the OUT). */
    public function consume(string $sourceType, int $sourceId): int
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($orgId, $sourceType, $sourceId) {
            $count = 0;
            $reservations = Reservation::query()->where('organization_id', $orgId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', 'active')->get();
            foreach ($reservations as $res) {
                // Releasing the hold; the shipment's ledger OUT reduces on_hand.
                $balance = $this->lockBalance($orgId, (int) $res->item_id, (int) $res->warehouse_id, $res->bin_id ? (int) $res->bin_id : null, $res->lot_id ? (int) $res->lot_id : null);
                $newReserved = Decimal::sub((string) $balance->reserved_qty, (string) $res->qty);
                $balance->reserved_qty = Decimal::lt($newReserved, '0') ? '0.0000' : Decimal::qty($newReserved);
                $balance->save();
                $res->status = 'consumed';
                $res->released_at = now();
                $res->save();
                $count++;
            }

            $this->syncSourceProjection($sourceType, $sourceId);

            return $count;
        });
    }

    /** Release only this shipment's held coordinates; unshipped SO reservations stay active. */
    public function consumeForShipment(Shipment $shipment): int
    {
        $orgId = $this->context->idOrFail();
        if (! $shipment->sales_order_id) return 0;
        return DB::connection($this->conn())->transaction(function () use ($shipment, $orgId) {
            SalesOrder::query()->where('organization_id', $orgId)->whereKey($shipment->sales_order_id)->lockForUpdate()->firstOrFail();
            $shipment->loadMissing('lines');
            $coordinates = [];
            foreach ($shipment->lines as $line) {
                $key = implode(':', [$line->item_id, $shipment->warehouse_id, $line->bin_id ?: '', $line->lot_id ?: '', $line->serial_id ?: '']);
                $coordinates[$key] ??= ['item'=>$line->item_id, 'warehouse'=>$shipment->warehouse_id, 'bin'=>$line->bin_id, 'lot'=>$line->lot_id, 'serial'=>$line->serial_id, 'qty'=>'0'];
                $coordinates[$key]['qty'] = Decimal::add($coordinates[$key]['qty'], (string) $line->quantity);
            }
            $itemIds = collect($coordinates)->pluck('item')->unique()->sort()->values()->all();
            Item::query()->where('organization_id', $orgId)->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            ksort($coordinates);
            $count = 0;
            foreach ($coordinates as $coordinate) {
                $query = Reservation::query()->where('organization_id', $orgId)->where('source_type', 'sales_order')->where('source_id', $shipment->sales_order_id)->where('status', 'active')->where('item_id', $coordinate['item'])->where('warehouse_id', $coordinate['warehouse']);
                foreach (['bin'=>'bin_id', 'lot'=>'lot_id', 'serial'=>'serial_id'] as $field=>$column) {
                    $coordinate[$field] ? $query->where($column, $coordinate[$field]) : $query->whereNull($column);
                }
                $remaining = $coordinate['qty'];
                foreach ($query->orderBy('id')->lockForUpdate()->get() as $reservation) {
                    if (! Decimal::gt($remaining, '0')) break;
                    $consumed = Decimal::lt($remaining, (string) $reservation->qty) ? $remaining : (string) $reservation->qty;
                    $balance = $this->lockBalance($orgId, (int) $reservation->item_id, (int) $reservation->warehouse_id, $reservation->bin_id ? (int) $reservation->bin_id : null, $reservation->lot_id ? (int) $reservation->lot_id : null);
                    $balance->reserved_qty = Decimal::qty(Decimal::sub((string) $balance->reserved_qty, $consumed));
                    $balance->save();
                    $reservation->qty = Decimal::qty(Decimal::sub((string) $reservation->qty, $consumed));
                    if (! Decimal::gt((string) $reservation->qty, '0')) {
                        $reservation->status = 'consumed';
                        $reservation->released_at = now();
                    }
                    $reservation->save();
                    $remaining = Decimal::sub($remaining, $consumed);
                    $count++;
                }
            }
            $this->syncSourceProjection('sales_order', (int) $shipment->sales_order_id);
            return $count;
        });
    }

    /** Release only aggregate surplus after an independently committed credit demand reduction. */
    public function releaseExcessForSalesOrder(SalesOrder $order, \App\Services\Sales\CreditDemandScope|\App\Services\FinancialOrigins\CashDemandScope|null $scope = null): int
    {
        return $this->transitionExcessForSalesOrder($order, [], true, $scope);
    }

    public function validateExcessReleaseForSalesOrder(SalesOrder $order, array $baseReductions, \App\Services\Sales\CreditDemandScope|\App\Services\FinancialOrigins\CashDemandScope|null $scope = null): int
    {
        return $this->transitionExcessForSalesOrder($order, $baseReductions, false, $scope);
    }

    private function transitionExcessForSalesOrder(SalesOrder $order, array $baseReductions, bool $apply, \App\Services\Sales\CreditDemandScope|\App\Services\FinancialOrigins\CashDemandScope|null $scope): int
    {
        $orgId = $this->context->idOrFail();
        return DB::connection($this->conn())->transaction(function () use ($order, $orgId, $baseReductions, $apply, $scope) {
            $scope?->assertOrder($orgId,(int)$order->id);
            $query = $scope ? SalesOrder::withoutGlobalScope('warehouse_access') : SalesOrder::query();
            $order = $query->where('organization_id', $orgId)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lines = $order->lines()->orderBy('id')->lockForUpdate()->get();
            $remaining = [];
            foreach ($lines as $line) {
                $key = (int) $line->item_id;
                $qty = Decimal::sub(Decimal::sub((string) $line->ordered_qty, (string) $line->shipped_qty), Decimal::add((string) ($line->cancelled_qty ?? '0'), (string) ($baseReductions[$line->id] ?? '0')));
                if (Decimal::lt($qty, '0')) throw new RuntimeException('Credited demand exceeds the original order quantity.');
                $remaining[$key] = Decimal::add($remaining[$key] ?? '0', $qty);
            }
            Item::query()->where('organization_id', $orgId)->whereIn('id', array_keys($remaining))->orderBy('id')->lockForUpdate()->get();
            $reservationQuery = $scope ? Reservation::withoutGlobalScope('warehouse_access') : Reservation::query();
            $reservations = $reservationQuery->where('organization_id', $orgId)->where('source_type', 'sales_order')->where('source_id', $order->id)->where('status', 'active')->orderBy('item_id')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach ($reservations->groupBy('item_id') as $itemId => $held) {
                $total = $held->reduce(fn ($sum, $r) => Decimal::add($sum, (string) $r->qty), '0');
                $excess = Decimal::sub($total, $remaining[$itemId] ?? '0');
                foreach ($held->reverse() as $reservation) {
                    if (!Decimal::gt($excess, '0')) break;
                    if ((int) $reservation->warehouse_id !== (int) $order->warehouse_id) throw new RuntimeException('Review reservations assigned to another warehouse before crediting demand.');
                    $released = Decimal::lt($excess, (string) $reservation->qty) ? $excess : (string) $reservation->qty;
                    if ($reservation->serial_id) {
                        $serial = SerialNumber::query()->where('organization_id', $orgId)->where('item_id', $itemId)->where('warehouse_id', $order->warehouse_id)->whereKey($reservation->serial_id)->lockForUpdate()->first();
                        if (!$serial || Decimal::cmp($released, '1') !== 0 || Decimal::cmp((string) $reservation->qty, '1') !== 0) throw new RuntimeException('Serialized reservations require an exact whole-unit demand reduction.');
                    }
                    $balanceQuery = $scope ? StockBalance::withoutGlobalScope('warehouse_access') : StockBalance::query();
                    $balances = $balanceQuery->where('organization_id', $orgId)->where('item_id', $itemId)->where('warehouse_id', $reservation->warehouse_id)
                        ->when($reservation->bin_id, fn($q)=>$q->where('bin_id',$reservation->bin_id), fn($q)=>$q->whereNull('bin_id'))
                        ->when($reservation->lot_id, fn($q)=>$q->where('lot_id',$reservation->lot_id), fn($q)=>$q->whereNull('lot_id'))->orderBy('id')->lockForUpdate()->get();
                    if ($balances->count() !== 1 || $balances->first()->variant_id) throw new RuntimeException('Review variant-specific reservations before crediting demand.');
                    $balance = $balances->first();
                    if (Decimal::lt((string) $balance->reserved_qty, $released)) throw new RuntimeException('Reservation balance must be reviewed before crediting demand.');
                    if ($apply) { $balance->reserved_qty = Decimal::qty(Decimal::sub((string) $balance->reserved_qty, $released)); $balance->save();
                    $reservation->qty = Decimal::qty(Decimal::sub((string) $reservation->qty, $released));
                    if (!Decimal::gt((string) $reservation->qty, '0')) { $reservation->status = 'released'; $reservation->released_at = now(); }
                    $reservation->save(); } $excess = Decimal::sub($excess, $released); $count++;
                }
            }
            if ($apply) {
                if($scope){$active=Reservation::withoutGlobalScope('warehouse_access')->where('organization_id',$orgId)->where('source_type','sales_order')->where('source_id',$order->id)->where('status','active')->get();foreach($lines as$line){$total=$active->where('item_id',$line->item_id)->reduce(fn($sum,$r)=>Decimal::add($sum,(string)$r->qty),'0');$line->update(['reserved_qty'=>Decimal::qty($total)]);}}
                else $this->syncSourceProjection('sales_order', (int) $order->id);
            }
            return $count;
        });
    }

    private function syncSourceProjection(string $sourceType, int $sourceId): void
    {
        if ($sourceType !== 'sales_order') {
            return;
        }

        $so = SalesOrder::query()->with('lines')->find($sourceId);
        if (! $so) {
            return;
        }

        $activeReservations = Reservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $sourceId)
            ->where('status', 'active')
            ->get()
            ->groupBy(fn (Reservation $r) => implode(':', [
                $r->item_id,
                $r->warehouse_id,
                $r->bin_id ?: '',
                $r->lot_id ?: '',
            ]));

        $allReserved = true;
        $anyReserved = false;
        foreach ($so->lines as $line) {
            $key = implode(':', [
                $line->item_id,
                $line->warehouse_id ?? $so->warehouse_id,
                $line->bin_id ?: '',
                '',
            ]);
            $reserved = Decimal::qty((string) (($activeReservations[$key] ?? collect())->sum(fn ($r) => (float) $r->qty)));
            $line->reserved_qty = $reserved;
            $line->save();
            $anyReserved = $anyReserved || Decimal::gt($reserved, '0');
            $allReserved = $allReserved && Decimal::gte($reserved, (string) $line->ordered_qty);
        }

        if (! in_array($so->status, ['shipped', 'cancelled', 'draft'], true)) {
            $so->status = $allReserved ? 'reserved' : ($anyReserved ? 'partially_reserved' : 'confirmed');
            $so->save();
        }
    }

    private function lockBalance(int $orgId, int $itemId, int $warehouseId, ?int $binId, ?int $lotId): StockBalance
    {
        $lockedFetch = static fn () => StockBalance::query()
            ->where('organization_id', $orgId)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId), fn ($q) => $q->whereNull('lot_id'))
            ->lockForUpdate()->first();

        if ($balance = $lockedFetch()) {
            return $balance;
        }

        // First reservation at this coordinate → create. Swallow a concurrent
        // duplicate insert, then re-fetch WITH the lock so availability checks
        // (available = on_hand - reserved) read a row held FOR UPDATE.
        try {
            StockBalance::create([
                'organization_id' => $orgId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
                'bin_id' => $binId, 'lot_id' => $lotId,
                'on_hand_qty' => '0', 'reserved_qty' => '0', 'average_cost' => '0', 'total_value' => '0',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // concurrent insert won — fall through to lock the existing row
        }

        $balance = $lockedFetch();
        if (! $balance) {
            throw new RuntimeException('stock_balances row could not be locked after creation.');
        }

        return $balance;
    }
}
