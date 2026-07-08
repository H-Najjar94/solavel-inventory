<?php

namespace App\Services\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockBalance;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
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
            throw new RuntimeException('Reservation could not be created.');
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
        bool $partial
    ): ?Reservation
    {
        $orgId = $this->context->idOrFail();
        $qty = Decimal::qty($qty);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException('Reservation quantity must be greater than zero.');
        }
        $priority = max(1, min(999, $priority));
        $this->expireOverdue(null, null, $itemId, $warehouseId);

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $warehouseId, $qty, $sourceType, $sourceId, $binId, $lotId, $expiresAt, $priority, $partial) {
            $balance = $this->lockBalance($orgId, $itemId, $warehouseId, $binId, $lotId);

            // Idempotent active reservation per source+coordinate.
            $existing = Reservation::query()
                ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', 'active')
                ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
                ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId), fn ($q) => $q->whereNull('lot_id'))
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // concurrent insert won — fall through to lock the existing row
        }

        $balance = $lockedFetch();
        if (! $balance) {
            throw new \RuntimeException('stock_balances row could not be locked after creation.');
        }

        return $balance;
    }
}
