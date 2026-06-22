<?php

namespace App\Services\Stock;

/**
 * Immutable value object describing ONE intended stock movement. Documents build
 * these and hand them to StockLedgerService::post(); they never write stock
 * themselves.
 */
final class StockMovement
{
    public function __construct(
        public readonly string $direction,      // 'in' | 'out'
        public readonly int $itemId,
        public readonly int $warehouseId,
        public readonly string $quantity,       // decimal string, > 0
        public readonly string $sourceType,     // document class
        public readonly int $sourceId,
        public readonly ?int $sourceLineId = null,
        public readonly ?int $variantId = null,
        public readonly ?int $zoneId = null,
        public readonly ?int $binId = null,
        public readonly ?int $lotId = null,
        public readonly ?int $serialId = null,
        public readonly ?string $unitCost = null,   // required for 'in'
        public readonly ?string $movedAt = null,    // business date; defaults to now
        public readonly ?string $expiryDate = null, // carried onto the ledger row for IN of an expiry-tracked lot
        public readonly bool $allowExpiredLot = false,   // policy override: ship an expired lot
        public readonly bool $allowQuarantinedLot = false, // policy override: ship a quarantined/recalled lot
    ) {}
}
