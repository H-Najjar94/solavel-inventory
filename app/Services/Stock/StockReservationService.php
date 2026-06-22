<?php

namespace App\Services\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\StockBalance;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
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
    public function reserve(int $itemId, int $warehouseId, string $qty, string $sourceType, int $sourceId, ?int $binId = null, ?int $lotId = null): Reservation
    {
        $orgId = $this->context->idOrFail();
        $qty = Decimal::qty($qty);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException('Reservation quantity must be greater than zero.');
        }

        return DB::connection($this->conn())->transaction(function () use ($orgId, $itemId, $warehouseId, $qty, $sourceType, $sourceId, $binId, $lotId) {
            $balance = $this->lockBalance($orgId, $itemId, $warehouseId, $binId, $lotId);

            $available = Decimal::sub((string) $balance->on_hand_qty, (string) $balance->reserved_qty);
            $allowNegative = (bool) (InventorySetting::query()->first()->allow_negative_stock ?? false);
            if (! $allowNegative && Decimal::gt($qty, $available)) {
                throw new RuntimeException("Cannot reserve {$qty}: only {$available} available for item #{$itemId} at warehouse #{$warehouseId}.");
            }

            // Idempotent active reservation per source+coordinate.
            $existing = Reservation::query()
                ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', 'active')
                ->when($binId !== null, fn ($q) => $q->where('bin_id', $binId), fn ($q) => $q->whereNull('bin_id'))
                ->first();

            if ($existing) {
                // adjust delta into reserved_qty
                $delta = Decimal::sub($qty, (string) $existing->qty);
                $existing->qty = $qty;
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
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'active',
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
                $res->save();
                $count++;
            }

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
                $res->save();
                $count++;
            }

            return $count;
        });
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
