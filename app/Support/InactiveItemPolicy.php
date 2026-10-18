<?php

namespace App\Support;

use App\Models\Tenant\InternalConsumptionLine;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockAdjustmentLine;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Q12.3 / Q12.6 (owner decisions 2026-10-18): server-side inactive-item policy for SolaStock.
 *
 * Locking contract (shared with ItemDeactivationGuard and StockLedgerService::lockItems): every writer
 * that creates a stock document line / reservation for an item, or re-points a line to another item,
 * first takes SELECT ... FOR UPDATE on that item row inside the same transaction as the insert. A
 * deactivation takes the same lock before it checks for open documents, so the two serialize:
 *   - deactivation first  -> the writer waits, then reads is_active = 0 and is refused;
 *   - writer first        -> the deactivation waits, then sees the committed open line and is refused.
 * is_active is always read by the locking read itself (never a plain read that could come from an older
 * REPEATABLE READ snapshot).
 *
 * Inactive items:
 *   - refused: every new document line / reservation (adjustment increase, transfer, count, opening,
 *     goods receipt, shipment, internal consumption, supplier/sales return, purchase-order line, receiving
 *     request, pick list, reservation) and every ordinary ledger movement (StockLedgerService::post);
 *   - allowed (controlled corrective workflows):
 *       1. reversal of an existing posted document (StockLedgerService::reverse / applyExactReversal,
 *          incl. InventoryReversalService), which never passes through post(); and the internal-consumption
 *          return of a line of an already POSTED issue of the same item (the return document line is
 *          allowed here, its stock effect is applyExactReversal via returnIssuedQuantity);
 *       2. a stock-adjustment DECREASE (write-off / reduction) out of positive on-hand stock at that
 *          coordinate, never below zero even when negative stock is enabled, posted through the existing
 *          draft -> post workflow (perm:inventory.manage_adjustments on POST /adjustments/{id}/post); every such posting writes an
 *          inventory_audit_logs row "stock_adjustment.inactive_item_write_off". Mirrors Finance C7
 *          InactiveItemAdjustmentPolicy (NF-1198-03).
 */
final class InactiveItemPolicy
{
    /** Lock the item row (same lock as a deactivation) and return its CURRENT active flag; null when absent. */
    public static function lockedIsActive(int $itemId): ?bool
    {
        $value = Item::query()->withoutGlobalScopes()->whereKey($itemId)->lockForUpdate()->value('is_active');

        return $value === null ? null : (bool) $value;
    }

    /** Called by GuardsInactiveItem inside the insert/update transaction of a document line or reservation. */
    public static function guardDocumentLine(Model $line): void
    {
        $itemId = (int) $line->getAttribute('item_id');
        if ($itemId <= 0) {
            return;
        }
        $active = self::lockedIsActive($itemId);
        if ($active !== false) {
            return; // active, or unknown item (the FK / document validation reports it)
        }
        if ($line instanceof StockAdjustmentLine && $line->getAttribute('direction') === 'decrease') {
            self::assertWriteOffWithinStock($itemId, $line);

            return;
        }
        if ($line instanceof InternalConsumptionLine && self::returnsPostedIssueLine($line, $itemId)) {
            return; // partial reversal (return) of an already posted issue of this item
        }
        throw self::rejected($itemId);
    }

    /**
     * Called by StockLedgerService::applyMovement (ordinary post, never a reversal) after the item and
     * balance rows are locked. $onHand is the locked balance at the movement's coordinate.
     */
    public static function assertLedgerMovementAllowed(StockMovement $m, string $onHand, string $qty, int $orgId): void
    {
        if (self::lockedIsActive($m->itemId) !== false) {
            return;
        }
        if ($m->direction !== 'out' || $m->sourceType !== StockAdjustment::class) {
            throw self::rejected($m->itemId);
        }
        if (! Decimal::gt($onHand, '0') || Decimal::lt(Decimal::sub($onHand, $qty), '0')) {
            throw self::writeOffExceeds($m->itemId);
        }
        InventoryAuditLog::create([
            'organization_id' => $orgId,
            'actor_user_id' => auth()->id(),
            'action' => 'stock_adjustment.inactive_item_write_off',
            'entity_type' => 'stock_adjustment',
            'entity_id' => $m->sourceId,
            'before' => ['item_id' => $m->itemId, 'warehouse_id' => $m->warehouseId, 'on_hand_qty' => Decimal::qty($onHand)],
            'after' => ['on_hand_qty' => Decimal::qty(Decimal::sub($onHand, $qty)), 'written_off_qty' => Decimal::qty($qty), 'source_line_id' => $m->sourceLineId],
            'document_ref' => 'stock_adjustment:'.$m->sourceId,
            'created_at' => now(),
        ]);
    }

    /**
     * Unit normalisation of a document line (UnitConversionResolver) for an inactive item: refuse with the
     * inactive-item error, except a stock-adjustment decrease line, which the locked line guard and the
     * ledger re-check against positive stock.
     */
    public static function assertConversionAllowed(Item $item, array $line): void
    {
        if (($line['direction'] ?? null) !== 'decrease') {
            throw self::rejected((int) $item->id);
        }
    }

    private static function returnsPostedIssueLine(InternalConsumptionLine $line, int $itemId): bool
    {
        $original = $line->getAttribute('original_line_id');
        if ($original === null) {
            return false;
        }

        return DB::connection('tenant')->table('internal_consumption_lines as l')
            ->join('internal_consumptions as h', 'h.id', '=', 'l.internal_consumption_id')
            ->where('l.id', $original)->where('l.item_id', $itemId)
            ->where('h.kind', 'issue')->where('h.status', 'posted')->exists();
    }

    private static function assertWriteOffWithinStock(int $itemId, StockAdjustmentLine $line): void
    {
        $warehouseId = StockAdjustment::query()->withoutGlobalScopes()->whereKey($line->getAttribute('stock_adjustment_id'))->value('warehouse_id');
        $query = DB::connection('tenant')->table('stock_balances')->where('item_id', $itemId)->where('warehouse_id', $warehouseId);
        foreach (['variant_id', 'lot_id', 'bin_id'] as $column) {
            if ($line->getAttribute($column) !== null) {
                $query->where($column, $line->getAttribute($column));
            }
        }
        $onHand = (string) ($query->sharedLock()->sum('on_hand_qty') ?? '0');
        $qty = (string) $line->getAttribute('quantity');
        if (! Decimal::gt($onHand, '0') || Decimal::lt(Decimal::sub($onHand, $qty), '0')) {
            throw self::writeOffExceeds($itemId);
        }
    }

    private static function rejected(int $itemId): ValidationException
    {
        return ValidationException::withMessages([
            'item_id' => [__('inventory.catalog.inactive_item_rejected')],
            'inactive_item_ids' => [$itemId],
        ]);
    }

    private static function writeOffExceeds(int $itemId): ValidationException
    {
        return ValidationException::withMessages([
            'item_id' => [__('inventory.catalog.inactive_write_off_exceeds_stock')],
            'inactive_item_ids' => [$itemId],
        ]);
    }
}
