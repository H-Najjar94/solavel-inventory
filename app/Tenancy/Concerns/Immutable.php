<?php

namespace App\Tenancy\Concerns;

use RuntimeException;

/**
 * Makes a model append-only: once a row exists it cannot be updated or deleted.
 * Used by StockLedger and InventoryAuditLog. Corrections happen by appending new
 * (reversing) rows, never by mutating history.
 *
 * This is application-level enforcement. A DB-level trigger guard is also
 * installed by the stock_ledger migration for defense in depth.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model) {
            throw new RuntimeException(
                static::class.' is immutable (append-only); updates are not allowed. '
                .'Append a reversing row instead.'
            );
        });

        static::deleting(function ($model) {
            throw new RuntimeException(
                static::class.' is immutable (append-only); deletes are not allowed.'
            );
        });
    }
}
