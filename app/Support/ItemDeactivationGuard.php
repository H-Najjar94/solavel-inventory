<?php

namespace App\Support;

use App\Models\Tenant\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Q12.3 / NF-1194-02 (owner decision 2026-10-18): an item cannot be deactivated while any
 * warehouse/bin/lot/variant balance is nonzero (positive OR negative), stock is reserved,
 * or an open stock document still references it. Quantities are never changed here.
 *
 * Callers must hold the item row lock (Item::lockForUpdate()) inside the same tenant
 * transaction. Every StockLedgerService movement locks the same item row first
 * (PurchaseValuationHoldService::lockItems), and every document line / reservation insert locks it
 * too (GuardsInactiveItem -> InactiveItemPolicy), so a writer and a deactivation serialize:
 * whichever commits second sees the other's effect. Document lines/headers are plain reads: when the
 * item lock is the transaction's first statement (ItemController update/bulkUpdate), the REPEATABLE READ
 * snapshot opens after the lock wait and includes the writer's commit. (S-locking document headers was
 * rejected: it can deadlock with a posting that locks its header before its items, and several line tables
 * have no item_id index.) A draft that a stale snapshot misses still cannot move stock: StockLedgerService
 * refuses ordinary movements for an inactive item.
 */
final class ItemDeactivationGuard
{
    /**
     * Open (non-terminal) states per document. Explicit lists where the column is an enum or
     * the state machine is known; for free-string statuses, every state except the terminal
     * ones counts as open so an unknown/new in-progress state blocks rather than slips through.
     *
     * Optional 6th element [received, ordered]: only lines whose received column is still below the
     * ordered column are outstanding (a partially received PO / receiving request does not block on a
     * line that is already fully received).
     *
     * @var list<array{0:string,1:string,2:string,3:'in'|'not_in',4:list<string>,5?:array{0:string,1:string}}>
     */
    private const DOCUMENTS = [
        ['stock_adjustment_lines', 'stock_adjustments', 'stock_adjustment_id', 'in', ['draft']],
        // StockTransferService: draft -> posted (dispatched) -> in_transit -> received.
        ['stock_transfer_lines', 'stock_transfers', 'stock_transfer_id', 'in', ['draft', 'posted', 'in_transit']],
        ['stock_count_lines', 'stock_counts', 'stock_count_id', 'not_in', ['posted', 'cancelled', 'reversed']],
        ['opening_stock_entry_lines', 'opening_stock_entries', 'opening_stock_entry_id', 'in', ['draft']],
        ['goods_receipt_lines', 'goods_receipts', 'goods_receipt_id', 'in', ['draft']],
        ['shipment_lines', 'shipments', 'shipment_id', 'not_in', ['posted', 'shipped', 'cancelled', 'abandoned', 'reversed']],
        ['internal_consumption_lines', 'internal_consumptions', 'internal_consumption_id', 'not_in', ['posted', 'cancelled', 'reversed', 'rejected']],
        ['supplier_return_lines', 'supplier_returns', 'supplier_return_id', 'not_in', ['posted', 'cancelled', 'reversed']],
        ['sales_return_lines', 'sales_returns', 'sales_return_id', 'not_in', ['posted', 'cancelled', 'reversed']],
        // Purchase order enum: draft, approved, partially_received, received, closed, cancelled. The open set is
        // the one the app already uses for pending POs (DashboardMetricsService / SupplierController).
        ['purchase_order_lines', 'inventory_purchase_orders', 'purchase_order_id', 'in', ['draft', 'approved', 'partially_received'], ['received_qty', 'ordered_qty']],
        // ReceivingRequestService: pending -> partial -> complete; cancelled (may reopen to pending/partial).
        ['purchasing_receiving_request_lines', 'purchasing_receiving_requests', 'receiving_request_id', 'in', ['pending', 'partial'], ['received_qty', 'requested_qty']],
        // PickListService: draft -> picking -> picked (terminal; the stock stays reserved until the shipment, which
        // the reservation + shipment blockers cover); cancelled.
        ['pick_list_lines', 'pick_lists', 'pick_list_id', 'in', ['draft', 'picking']],
    ];

    /** @return list<string> machine-readable blocker codes, empty when deactivation is safe */
    public static function blockers(int $itemId): array
    {
        $db = DB::connection('tenant');
        $schema = $db->getSchemaBuilder();
        $blockers = [];

        // Exact DECIMAL comparison in SQL; a single nonzero row in any warehouse or bin blocks.
        // Balances and reservations are read with LOCK IN SHARE MODE (current committed rows, independent of
        // when the caller's snapshot was opened, e.g. inside the Finance workspace dispatcher transaction). Their
        // writers hold the same item lock first, so these share locks never wait on them.
        $found = fn ($query) => $query->sharedLock()->limit(1)->value('id') !== null;
        $balances = $db->table('stock_balances')->where('item_id', $itemId);
        if ($found((clone $balances)->where('on_hand_qty', '<', 0))) {
            $blockers[] = 'negative_stock_on_hand';
        } elseif ($found((clone $balances)->where('on_hand_qty', '<>', 0))) {
            $blockers[] = 'stock_on_hand';
        }
        if ($found((clone $balances)->where('reserved_qty', '<>', 0))
            || ($schema->hasTable('reservations') && $found($db->table('reservations')->where('item_id', $itemId)->where('status', 'active')))) {
            $blockers[] = 'active_reservation';
        }

        foreach (self::DOCUMENTS as $document) {
            [$lines, $headers, $fk, $mode, $statuses] = $document;
            if (! $schema->hasTable($lines) || ! $schema->hasTable($headers)) {
                continue;
            }
            $query = $db->table($lines.' as l')->join($headers.' as h', 'h.id', '=', 'l.'.$fk)->where('l.item_id', $itemId);
            $mode === 'in' ? $query->whereIn('h.status', $statuses) : $query->whereNotIn('h.status', $statuses);
            if (isset($document[5])) {
                $query->whereColumn('l.'.$document[5][0], '<', 'l.'.$document[5][1]);
            }
            if ($schema->hasColumn($headers, 'deleted_at')) {
                $query->whereNull('h.deleted_at');
            }
            if ($query->exists()) {
                $blockers[] = 'open_'.$headers;
            }
        }

        return $blockers;
    }

    /** Throws a 422 ValidationException when the (already locked) item cannot be deactivated. */
    public static function assertCanDeactivate(Item $locked): void
    {
        $blockers = self::blockers((int) $locked->id);
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'is_active' => [__('inventory.catalog.deactivation_blocked')],
                'deactivation_blockers' => $blockers,
            ]);
        }
    }
}
