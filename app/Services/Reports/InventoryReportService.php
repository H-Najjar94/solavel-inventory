<?php

namespace App\Services\Reports;

use App\Services\Documents\SourceDocumentPresenter;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only inventory reporting. A single registry maps a report key to a builder
 * that returns { key, columns, rows, summary }. Reads ONLY from canonical
 * projections/documents — never mutates stock. Used by both the screen and the
 * CSV export so they share filters and shape.
 */
class InventoryReportService
{
    public const REPORTS = [
        'inventory-valuation' => 'Inventory Valuation',
        'stock-movement' => 'Stock Movement',
        'item-ledger' => 'Item Ledger',
        'warehouse-stock' => 'Warehouse Stock',
        'low-stock' => 'Low Stock',
        'out-of-stock' => 'Out of Stock',
        'dead-stock' => 'Dead Stock',
        'fast-moving' => 'Fast Moving Items',
        'stock-aging' => 'Stock Aging',
        'lot-expiry' => 'Lot / Expiry',
        'serial' => 'Serial Report',
        'adjustment' => 'Adjustment Report',
        'receiving' => 'Receiving Report',
        'transfer' => 'Transfer Report',
        'count-variance' => 'Stock Count Variance',
        'fulfillment-status' => 'Sales Fulfillment Status',
        'pick-list' => 'Pick List Report',
        'shipment' => 'Shipment Report',
        'reservation' => 'Reservation Report',
        'lot-trace' => 'Lot Trace Report',
        'serial-lifecycle' => 'Serial Lifecycle Report',
        'expiry-risk' => 'Expiry Risk Report',
        'recall-impact' => 'Recall Impact Report',
    ];

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::REPORTS);
    }

    /** @return array{key:string,title:string,columns:array,rows:array,summary:array} */
    public function run(string $key, ReportFilters $f): array
    {
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Unknown report: {$key}");
        }
        $method = 'report'.str_replace(' ', '', ucwords(str_replace('-', ' ', $key)));
        $payload = $this->{$method}($f);

        return array_merge(['key' => $key, 'title' => self::REPORTS[$key]], $payload);
    }

    private function db()
    {
        return DB::connection($this->conn());
    }

    /** Active org id, or -1 (matches no rows → fail closed) when no context. */
    private function orgId(): int
    {
        $ctx = app(OrganizationContext::class);

        return $ctx->has() ? (int) $ctx->id() : -1;
    }

    /**
     * SECURITY: every report read MUST be constrained to the active org —
     * these raw query-builder reads bypass the Eloquent OrganizationScope.
     * Accepts "table" or "table as alias" and qualifies organization_id with the
     * alias so it is safe to join. Fails closed when no org context is set.
     */
    private function scoped(string $table)
    {
        $alias = preg_match('/\s+as\s+(\w+)\s*$/i', $table, $m) ? $m[1] : $table;

        return $this->db()->table($table)->where($alias.'.organization_id', $this->orgId());
    }

    // ── 1. Inventory Valuation ──
    private function reportInventoryValuation(ReportFilters $f): array
    {
        if ($f->asAt) {
            return $this->reportInventoryValuationAsAt($f);
        }

        $q = $this->scoped('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('lots as lo', 'lo.id', '=', 'b.lot_id')
            ->when($f->itemId, fn ($x) => $x->where('b.item_id', $f->itemId))
            ->when($f->warehouseId, fn ($x) => $x->where('b.warehouse_id', $f->warehouseId))
            ->when($f->categoryId, fn ($x) => $x->where('i.category_id', $f->categoryId))
            ->selectRaw('i.sku, i.name item, c.name category, w.name warehouse, lo.lot_code, lo.expiry_date, b.on_hand_qty, b.average_cost, b.total_value,
                (CASE WHEN (b.on_hand_qty - b.reserved_qty) <= 0 THEN "out" WHEN (b.on_hand_qty - b.reserved_qty) <= 5 THEN "low" ELSE "ok" END) stock_status')
            ->orderByDesc('b.total_value');
        $rows = $q->get();
        $currency = $this->currencyContext($f);
        $rows = $rows->map(fn ($row) => $this->withConvertedValue($row, $currency))->values();

        return [
            'columns' => ['sku', 'item', 'category', 'warehouse', 'lot_code', 'expiry_date', 'on_hand_qty', 'average_cost', 'total_value', 'value_currency', 'converted_total_value', 'stock_status'],
            'rows' => $rows,
            'summary' => [
                'total_value' => (string) $rows->sum(fn ($r) => (float) $r->total_value),
                'value_currency' => $currency['currency'],
                'converted_total_value' => Decimal::money((string) $rows->sum(fn ($r) => (float) ($r->converted_total_value ?? $r->total_value))),
                'as_at' => null,
                'total_lines' => $rows->count(),
                'low' => $rows->where('stock_status', 'low')->count(),
                'out' => $rows->where('stock_status', 'out')->count(),
            ],
        ];
    }

    private function reportInventoryValuationAsAt(ReportFilters $f): array
    {
        $asAt = $f->asAt.' 23:59:59';
        $rows = $this->scoped('stock_ledger as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('lots as lo', 'lo.id', '=', 'l.lot_id')
            ->when($f->itemId, fn ($x) => $x->where('l.item_id', $f->itemId))
            ->when($f->warehouseId, fn ($x) => $x->where('l.warehouse_id', $f->warehouseId))
            ->when($f->categoryId, fn ($x) => $x->where('i.category_id', $f->categoryId))
            ->where('l.moved_at', '<=', $asAt)
            ->selectRaw('i.sku, i.name item, c.name category, w.name warehouse, lo.lot_code, lo.expiry_date,
                SUM(CASE WHEN l.direction = "in" THEN l.quantity ELSE -l.quantity END) on_hand_qty,
                CASE WHEN SUM(CASE WHEN l.direction = "in" THEN l.quantity ELSE -l.quantity END) = 0 THEN 0
                    ELSE SUM(CASE WHEN l.direction = "in" THEN l.total_cost ELSE -l.total_cost END) / SUM(CASE WHEN l.direction = "in" THEN l.quantity ELSE -l.quantity END) END average_cost,
                SUM(CASE WHEN l.direction = "in" THEN l.total_cost ELSE -l.total_cost END) total_value,
                "as_at" stock_status')
            ->groupBy('i.sku', 'i.name', 'c.name', 'w.name', 'lo.lot_code', 'lo.expiry_date')
            ->havingRaw('ABS(on_hand_qty) > 0.00001')
            ->orderByDesc('total_value')
            ->get();

        $currency = $this->currencyContext($f);
        $rows = $rows->map(fn ($row) => $this->withConvertedValue($row, $currency))->values();

        return [
            'columns' => ['sku', 'item', 'category', 'warehouse', 'lot_code', 'expiry_date', 'on_hand_qty', 'average_cost', 'total_value', 'value_currency', 'converted_total_value', 'stock_status'],
            'rows' => $rows,
            'summary' => [
                'total_value' => Decimal::money((string) $rows->sum(fn ($r) => (float) $r->total_value)),
                'value_currency' => $currency['currency'],
                'converted_total_value' => Decimal::money((string) $rows->sum(fn ($r) => (float) ($r->converted_total_value ?? $r->total_value))),
                'as_at' => $f->asAt,
                'total_lines' => $rows->count(),
            ],
        ];
    }

    private function currencyContext(ReportFilters $f): array
    {
        $base = $this->baseCurrency();
        $currency = $f->currency ?: $base;
        $rate = 1.0;
        if ($currency !== $base) {
            $rate = (float) ($this->scoped('inventory_currency_rates')
                ->where('currency_code', $currency)
                ->when($f->asAt, fn ($q) => $q->whereDate('effective_date', '<=', $f->asAt))
                ->orderByDesc('effective_date')
                ->value('rate_to_base') ?: 0);
        }

        return ['base' => $base, 'currency' => $currency, 'rate_to_base' => $rate > 0 ? $rate : 1.0];
    }

    private function withConvertedValue(object $row, array $currency): object
    {
        $row->value_currency = $currency['currency'];
        $row->converted_total_value = Decimal::money((string) ((float) ($row->total_value ?? 0) / (float) $currency['rate_to_base']));

        return $row;
    }

    private function baseCurrency(): string
    {
        try {
            return (string) (DB::connection(config('tenancy.central_connection', 'mysql'))
                ->table('organizations')
                ->where('id', $this->orgId())
                ->value('base_currency') ?: 'SAR');
        } catch (\Throwable) {
            return 'SAR';
        }
    }

    // ── 2. Stock Movement ──
    private function reportStockMovement(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_ledger as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('lots as lo', 'lo.id', '=', 'l.lot_id')
            ->leftJoin('serial_numbers as sn', 'sn.id', '=', 'l.serial_id')
            ->when($f->itemId, fn ($x) => $x->where('l.item_id', $f->itemId))
            ->when($f->warehouseId, fn ($x) => $x->where('l.warehouse_id', $f->warehouseId))
            ->when($f->from, fn ($x) => $x->whereDate('l.moved_at', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('l.moved_at', '<=', $f->to))
            ->selectRaw('l.id, l.moved_at, i.sku, i.name item, w.name warehouse, lo.lot_code, sn.serial, l.expiry_date, l.source_type, l.source_id, l.direction,
                (CASE WHEN l.direction="in" THEN l.quantity ELSE 0 END) qty_in,
                (CASE WHEN l.direction="out" THEN l.quantity ELSE 0 END) qty_out,
                l.unit_cost, l.total_cost, l.balance_qty_after')
            ->orderBy('l.moved_at')->orderBy('l.id')->limit($f->limit)->get();
        SourceDocumentPresenter::decorateRows($rows);
        $rows->each(fn ($r) => $r->source_document = $r->source_display ?? null);

        return [
            'columns' => ['moved_at', 'sku', 'item', 'warehouse', 'lot_code', 'serial', 'expiry_date', 'source_document', 'qty_in', 'qty_out', 'unit_cost', 'total_cost', 'balance_qty_after'],
            'rows' => $rows,
            'summary' => ['rows' => $rows->count(), 'qty_in' => (string) $rows->sum(fn ($r) => (float) $r->qty_in), 'qty_out' => (string) $rows->sum(fn ($r) => (float) $r->qty_out)],
        ];
    }

    // ── 3. Item Ledger ──
    private function reportItemLedger(ReportFilters $f): array
    {
        if (! $f->itemId) {
            return ['columns' => [], 'rows' => [], 'summary' => ['note' => 'Select an item to view its ledger.']];
        }
        $rows = $this->scoped('stock_ledger as l')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('warehouse_bins as bin', 'bin.id', '=', 'l.bin_id')
            ->where('l.item_id', $f->itemId)
            ->when($f->warehouseId, fn ($x) => $x->where('l.warehouse_id', $f->warehouseId))
            ->selectRaw('l.moved_at, w.name warehouse, bin.code bin, l.source_type, l.source_id, l.direction, l.quantity, l.unit_cost, l.balance_qty_after, l.balance_value_after')
            ->orderBy('l.moved_at')->orderBy('l.id')->limit($f->limit)->get();
        SourceDocumentPresenter::decorateRows($rows);
        $rows->each(fn ($r) => $r->source_document = $r->source_display ?? null);

        return [
            'columns' => ['moved_at', 'warehouse', 'bin', 'source_document', 'direction', 'quantity', 'unit_cost', 'balance_qty_after', 'balance_value_after'],
            'rows' => $rows,
            'summary' => ['movements' => $rows->count()],
        ];
    }

    // ── 4. Warehouse Stock ──
    private function reportWarehouseStock(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('warehouse_bins as bin', 'bin.id', '=', 'b.bin_id')
            ->when($f->warehouseId, fn ($x) => $x->where('b.warehouse_id', $f->warehouseId))
            ->when($f->binId, fn ($x) => $x->where('b.bin_id', $f->binId))
            ->selectRaw('w.name warehouse, bin.code bin, i.sku, i.name item, b.on_hand_qty, b.reserved_qty, (b.on_hand_qty - b.reserved_qty) available_qty, b.total_value, bin.capacity')
            ->orderBy('w.name')->limit($f->limit)->get();

        return [
            'columns' => ['warehouse', 'bin', 'sku', 'item', 'on_hand_qty', 'reserved_qty', 'available_qty', 'total_value', 'capacity'],
            'rows' => $rows,
            'summary' => ['lines' => $rows->count(), 'total_value' => (string) $rows->sum(fn ($r) => (float) $r->total_value)],
        ];
    }

    // ── 5. Low Stock ──
    private function reportLowStock(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('warehouse_reorder_rules as rr', function ($join) {
                $join->on('rr.item_id', '=', 'b.item_id')
                    ->on('rr.warehouse_id', '=', 'b.warehouse_id')
                    ->on('rr.organization_id', '=', 'b.organization_id');
            })
            ->leftJoin('inventory_suppliers as s', 's.id', '=', 'i.preferred_supplier_id')
            ->whereRaw('COALESCE(rr.reorder_point, i.reorder_point) IS NOT NULL')
            ->whereRaw('(b.on_hand_qty - b.reserved_qty) <= COALESCE(rr.reorder_point, i.reorder_point)')
            ->where('b.on_hand_qty', '>', 0)
            ->when($f->warehouseId, fn ($x) => $x->where('b.warehouse_id', $f->warehouseId))
            ->selectRaw('i.id item_id, i.sku, i.name item, w.name warehouse, b.on_hand_qty, b.reserved_qty,
                (b.on_hand_qty - b.reserved_qty) available_qty,
                COALESCE(rr.reorder_point, i.reorder_point) reorder_point,
                COALESCE(rr.reorder_qty, i.reorder_qty) reorder_qty,
                COALESCE(rr.min_stock, i.min_stock) min_stock,
                COALESCE(rr.max_stock, i.max_stock) max_stock,
                COALESCE(rr.safety_stock, i.safety_stock) safety_stock,
                (COALESCE(rr.reorder_point, i.reorder_point) - (b.on_hand_qty - b.reserved_qty)) shortage_qty,
                s.name preferred_supplier')
            ->orderByDesc('shortage_qty')->get();

        return [
            'columns' => ['sku', 'item', 'warehouse', 'on_hand_qty', 'reserved_qty', 'available_qty', 'reorder_point', 'reorder_qty', 'min_stock', 'max_stock', 'safety_stock', 'shortage_qty', 'preferred_supplier'],
            'rows' => $rows,
            'summary' => ['items' => $rows->count()],
        ];
    }

    // ── 6. Out of Stock ──
    private function reportOutOfStock(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('inventory_suppliers as s', 's.id', '=', 'i.preferred_supplier_id')
            ->whereRaw('(b.on_hand_qty - b.reserved_qty) <= 0')
            ->when($f->warehouseId, fn ($x) => $x->where('b.warehouse_id', $f->warehouseId))
            ->selectRaw('i.sku, i.name item, w.name warehouse, b.on_hand_qty, b.reserved_qty, s.name preferred_supplier')
            ->get();

        return ['columns' => ['sku', 'item', 'warehouse', 'on_hand_qty', 'reserved_qty', 'preferred_supplier'], 'rows' => $rows, 'summary' => ['items' => $rows->count()]];
    }

    // ── 7. Dead Stock ──
    private function reportDeadStock(ReportFilters $f): array
    {
        $cutoff = now()->subDays($f->days)->toDateTimeString();
        $moved = $this->scoped('stock_ledger')->where('direction', 'out')->where('moved_at', '>=', $cutoff)->distinct()->pluck('item_id');
        $rows = $this->scoped('stock_balances as b')->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.on_hand_qty', '>', 0)->whereNotIn('b.item_id', $moved)
            ->selectRaw('i.sku, i.name item, w.name warehouse, b.on_hand_qty, b.total_value')->get();

        return ['columns' => ['sku', 'item', 'warehouse', 'on_hand_qty', 'total_value'], 'rows' => $rows, 'summary' => ['items' => $rows->count(), 'window_days' => $f->days, 'value' => (string) $rows->sum(fn ($r) => (float) $r->total_value)]];
    }

    // ── 8. Fast Moving ──
    private function reportFastMoving(ReportFilters $f): array
    {
        $cutoff = now()->subDays($f->days)->toDateTimeString();
        $rows = $this->scoped('stock_ledger as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('l.direction', 'out')->where('l.moved_at', '>=', $cutoff)
            ->selectRaw('i.sku, i.name item, SUM(l.quantity) qty_out, COUNT(*) movements')
            ->groupBy('i.sku', 'i.name')->orderByDesc('qty_out')->limit(50)->get();

        return ['columns' => ['sku', 'item', 'qty_out', 'movements'], 'rows' => $rows, 'summary' => ['window_days' => $f->days, 'items' => $rows->count()]];
    }

    // ── 9. Stock Aging ──
    private function reportStockAging(ReportFilters $f): array
    {
        $rows = $this->scoped('cost_layers as cl')->join('items as i', 'i.id', '=', 'cl.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'cl.warehouse_id')
            ->where('cl.remaining_qty', '>', 0)
            ->when($f->itemId, fn ($x) => $x->where('cl.item_id', $f->itemId))
            ->when($f->warehouseId, fn ($x) => $x->where('cl.warehouse_id', $f->warehouseId))
            ->selectRaw('i.sku, i.name item, w.name warehouse, cl.received_at, cl.remaining_qty, cl.unit_cost, DATEDIFF(NOW(), cl.received_at) age_days')
            ->orderByDesc('age_days')->limit($f->limit)->get();

        return ['columns' => ['sku', 'item', 'warehouse', 'received_at', 'remaining_qty', 'unit_cost', 'age_days'], 'rows' => $rows, 'summary' => ['layers' => $rows->count()]];
    }

    // ── 10. Lot / Expiry ──
    private function reportLotExpiry(ReportFilters $f): array
    {
        $rows = $this->scoped('lots as lo')->join('items as i', 'i.id', '=', 'lo.item_id')
            ->whereNotNull('lo.expiry_date')
            ->when($f->itemId, fn ($x) => $x->where('lo.item_id', $f->itemId))
            ->selectRaw('i.sku, i.name item, lo.lot_code, lo.mfg_date, lo.expiry_date, DATEDIFF(lo.expiry_date, NOW()) days_to_expiry')
            ->orderBy('lo.expiry_date')->limit($f->limit)->get();

        return ['columns' => ['sku', 'item', 'lot_code', 'mfg_date', 'expiry_date', 'days_to_expiry'], 'rows' => $rows, 'summary' => ['lots' => $rows->count()]];
    }

    // ── 11. Serial ──
    private function reportSerial(ReportFilters $f): array
    {
        $rows = $this->scoped('serial_numbers as s')->join('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->when($f->itemId, fn ($x) => $x->where('s.item_id', $f->itemId))
            ->when($f->status, fn ($x) => $x->where('s.status', $f->status))
            ->selectRaw('i.sku, i.name item, s.serial, s.status, w.name warehouse')
            ->orderByDesc('s.id')->limit($f->limit)->get();

        return ['columns' => ['sku', 'item', 'serial', 'status', 'warehouse'], 'rows' => $rows, 'summary' => ['serials' => $rows->count()]];
    }

    // ── 12. Adjustment ──
    private function reportAdjustment(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_adjustments as a')
            ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->when($f->warehouseId, fn ($x) => $x->where('a.warehouse_id', $f->warehouseId))
            ->when($f->status, fn ($x) => $x->where('a.status', $f->status))
            ->when($f->from, fn ($x) => $x->whereDate('a.adjustment_date', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('a.adjustment_date', '<=', $f->to))
            ->selectRaw('a.id, a.adjustment_number, a.adjustment_date, w.name warehouse, a.reason_code, a.status, a.total_increase_value, a.total_decrease_value')
            ->orderByDesc('a.id')->limit($f->limit)->get();

        return ['columns' => ['adjustment_number', 'adjustment_date', 'warehouse', 'reason_code', 'status', 'total_increase_value', 'total_decrease_value'], 'rows' => $rows, 'summary' => ['documents' => $rows->count()]];
    }

    // ── 13. Receiving ──
    private function reportReceiving(ReportFilters $f): array
    {
        $rows = $this->scoped('goods_receipts as g')
            ->leftJoin('inventory_suppliers as s', 's.id', '=', 'g.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->leftJoin('inventory_purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')
            ->when($f->warehouseId, fn ($x) => $x->where('g.warehouse_id', $f->warehouseId))
            ->when($f->supplierId, fn ($x) => $x->where('g.supplier_id', $f->supplierId))
            ->when($f->status, fn ($x) => $x->where('g.status', $f->status))
            ->when($f->from, fn ($x) => $x->whereDate('g.receipt_date', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('g.receipt_date', '<=', $f->to))
            ->selectRaw('g.id, g.grn_number, g.receipt_date, s.name supplier, w.name warehouse, po.po_number purchase_order, g.status')
            ->orderByDesc('g.id')->limit($f->limit)->get();

        return ['columns' => ['grn_number', 'receipt_date', 'supplier', 'warehouse', 'purchase_order', 'status'], 'rows' => $rows, 'summary' => ['documents' => $rows->count()]];
    }

    // ── 14. Transfer ──
    private function reportTransfer(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_transfers as t')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->when($f->status, fn ($x) => $x->where('t.status', $f->status))
            ->when($f->from, fn ($x) => $x->whereDate('t.transfer_date', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('t.transfer_date', '<=', $f->to))
            ->selectRaw('t.id, t.transfer_number, t.transfer_date, fw.name from_warehouse, tw.name to_warehouse, t.status')
            ->orderByDesc('t.id')->limit($f->limit)->get();

        return ['columns' => ['transfer_number', 'transfer_date', 'from_warehouse', 'to_warehouse', 'status'], 'rows' => $rows, 'summary' => ['documents' => $rows->count()]];
    }

    // ── 15. Count Variance ──
    private function reportCountVariance(ReportFilters $f): array
    {
        $rows = $this->scoped('stock_count_lines as cl')
            ->join('stock_counts as c', 'c.id', '=', 'cl.stock_count_id')
            ->join('items as i', 'i.id', '=', 'cl.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'c.warehouse_id')
            ->leftJoin('warehouse_bins as bin', 'bin.id', '=', 'cl.bin_id')
            ->leftJoin('stock_adjustments as a', 'a.id', '=', 'c.adjustment_id')
            ->where('cl.variance_qty', '!=', 0)
            ->when($f->warehouseId, fn ($x) => $x->where('c.warehouse_id', $f->warehouseId))
            ->when($f->status, fn ($x) => $x->where('c.status', $f->status))
            ->selectRaw('c.count_number, c.status, c.posted_at, a.adjustment_number adjustment, i.sku, i.name item, w.name warehouse, bin.code bin,
                cl.system_qty expected_qty, cl.counted_qty, cl.variance_qty')
            ->orderByDesc('c.id')->limit($f->limit)->get();

        return [
            'columns' => ['count_number', 'sku', 'item', 'warehouse', 'bin', 'expected_qty', 'counted_qty', 'variance_qty', 'status', 'adjustment', 'posted_at'],
            'rows' => $rows,
            'summary' => ['variance_lines' => $rows->count()],
        ];
    }

    // ── 16. Sales Fulfillment Status ──
    private function reportFulfillmentStatus(ReportFilters $f): array
    {
        $rows = $this->scoped('inventory_sales_orders as so')
            ->leftJoin('warehouses as w', 'w.id', '=', 'so.warehouse_id')
            ->leftJoin('sales_order_lines as l', 'l.sales_order_id', '=', 'so.id')
            ->when($f->warehouseId, fn ($x) => $x->where('so.warehouse_id', $f->warehouseId))
            ->when($f->status, fn ($x) => $x->where('so.status', $f->status))
            ->when($f->from, fn ($x) => $x->whereDate('so.order_date', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('so.order_date', '<=', $f->to))
            ->leftJoin('inventory_customers as c', 'c.id', '=', 'so.customer_id')
            ->groupBy('so.id', 'so.order_number', 'so.order_date', 'so.customer_name', 'c.name', 'w.name', 'so.status', 'so.subtotal', 'so.discount_total', 'so.tax_total', 'so.total')
            ->selectRaw('so.id, so.order_number, so.order_date, COALESCE(c.name, so.customer_name) customer_name, w.name warehouse, so.status,
                COALESCE(SUM(l.ordered_qty),0) ordered_qty, COALESCE(SUM(l.reserved_qty),0) reserved_qty,
                COALESCE(SUM(l.picked_qty),0) picked_qty, COALESCE(SUM(l.packed_qty),0) packed_qty,
                COALESCE(SUM(l.shipped_qty),0) shipped_qty, so.subtotal, so.discount_total, so.tax_total, so.total')
            ->orderByDesc('so.id')->limit($f->limit)->get();

        return [
            'columns' => ['order_number', 'order_date', 'customer_name', 'warehouse', 'status', 'ordered_qty', 'reserved_qty', 'picked_qty', 'packed_qty', 'shipped_qty', 'subtotal', 'discount_total', 'tax_total', 'total'],
            'rows' => $rows,
            'summary' => [
                'orders' => $rows->count(),
                'open' => $rows->whereNotIn('status', ['shipped', 'cancelled'])->count(),
                'shipped' => $rows->where('status', 'shipped')->count(),
            ],
        ];
    }

    // ── 17. Pick List Report ──
    private function reportPickList(ReportFilters $f): array
    {
        $rows = $this->scoped('pick_lists as p')
            ->leftJoin('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('inventory_sales_orders as so', 'so.id', '=', 'p.sales_order_id')
            ->leftJoin('pick_list_lines as pl', 'pl.pick_list_id', '=', 'p.id')
            ->when($f->warehouseId, fn ($x) => $x->where('p.warehouse_id', $f->warehouseId))
            ->when($f->status, fn ($x) => $x->where('p.status', $f->status))
            ->groupBy('p.id', 'p.pick_number', 'so.order_number', 'w.name', 'p.status')
            ->selectRaw('p.id, p.pick_number, so.order_number sales_order, w.name warehouse, p.status,
                COUNT(pl.id) line_count, COALESCE(SUM(pl.reserved_qty),0) reserved_qty, COALESCE(SUM(pl.picked_qty),0) picked_qty')
            ->orderByDesc('p.id')->limit($f->limit)->get();

        return [
            'columns' => ['pick_number', 'sales_order', 'warehouse', 'status', 'line_count', 'reserved_qty', 'picked_qty'],
            'rows' => $rows,
            'summary' => ['pick_lists' => $rows->count(), 'open' => $rows->whereNotIn('status', ['picked', 'cancelled'])->count()],
        ];
    }

    // ── 18. Shipment Report ──
    private function reportShipment(ReportFilters $f): array
    {
        $rows = $this->scoped('shipments as s')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('inventory_sales_orders as so', 'so.id', '=', 's.sales_order_id')
            ->leftJoin('shipment_lines as sl', 'sl.shipment_id', '=', 's.id')
            ->when($f->warehouseId, fn ($x) => $x->where('s.warehouse_id', $f->warehouseId))
            ->when($f->status, fn ($x) => $x->where('s.status', $f->status))
            ->when($f->from, fn ($x) => $x->whereDate('s.ship_date', '>=', $f->from))
            ->when($f->to, fn ($x) => $x->whereDate('s.ship_date', '<=', $f->to))
            ->groupBy('s.id', 's.shipment_number', 's.ship_date', 'so.order_number', 'w.name', 's.carrier', 's.tracking_number', 's.status')
            ->selectRaw('s.id, s.shipment_number, s.ship_date, so.order_number sales_order, w.name warehouse,
                s.carrier, s.tracking_number, s.status, COUNT(sl.id) line_count, COALESCE(SUM(sl.quantity),0) shipped_qty,
                COUNT(DISTINCT sl.lot_id) lots, COUNT(DISTINCT sl.serial_id) serials')
            ->orderByDesc('s.id')->limit($f->limit)->get();

        return [
            'columns' => ['shipment_number', 'ship_date', 'sales_order', 'warehouse', 'carrier', 'tracking_number', 'status', 'line_count', 'shipped_qty', 'lots', 'serials'],
            'rows' => $rows,
            'summary' => ['shipments' => $rows->count(), 'posted' => $rows->where('status', 'posted')->count()],
        ];
    }

    // ── 19. Reservation Report ──
    private function reportReservation(ReportFilters $f): array
    {
        $rows = $this->scoped('reservations as r')
            ->join('items as i', 'i.id', '=', 'r.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->where('r.status', 'active')
            ->where(fn ($q) => $q->whereNull('r.expires_at')->orWhere('r.expires_at', '>', now()))
            ->when($f->itemId, fn ($x) => $x->where('r.item_id', $f->itemId))
            ->when($f->warehouseId, fn ($x) => $x->where('r.warehouse_id', $f->warehouseId))
            ->selectRaw('i.sku, i.name item, w.name warehouse, r.qty reserved_qty, r.priority, r.expires_at, r.source_type, r.source_id, r.status')
            ->orderBy('r.priority')->orderBy('r.expires_at')->orderByDesc('r.id')->limit($f->limit)->get();
        SourceDocumentPresenter::decorateRows($rows);
        $rows->each(fn ($r) => $r->source_document = $r->source_display ?? null);

        return [
            'columns' => ['sku', 'item', 'warehouse', 'reserved_qty', 'priority', 'expires_at', 'source_document', 'status'],
            'rows' => $rows,
            'summary' => ['active_reservations' => $rows->count(), 'reserved_qty' => (string) $rows->sum(fn ($r) => (float) $r->reserved_qty)],
        ];
    }

    // ── 20. Lot Trace ──
    private function reportLotTrace(ReportFilters $f): array
    {
        $balanceSubquery = $this->db()->table('stock_balances')
            ->where('organization_id', $this->orgId())
            ->selectRaw('lot_id, SUM(on_hand_qty) on_hand, SUM(reserved_qty) reserved')
            ->groupBy('lot_id');
        $shipmentSubquery = $this->db()->table('stock_ledger')
            ->where('organization_id', $this->orgId())
            ->where('direction', 'out')
            ->where('source_type', 'like', '%Shipment')
            ->selectRaw('lot_id, SUM(quantity) shipped')
            ->groupBy('lot_id');

        $rows = $this->scoped('lots as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('inventory_suppliers as s', 's.id', '=', 'l.supplier_id')
            ->leftJoinSub($balanceSubquery, 'b', 'b.lot_id', '=', 'l.id')
            ->leftJoinSub($shipmentSubquery, 'sh', 'sh.lot_id', '=', 'l.id')
            ->when($f->itemId, fn ($x) => $x->where('l.item_id', $f->itemId))
            ->when($f->status, fn ($x) => $x->where('l.status', $f->status))
            ->selectRaw('l.id lot_id, l.lot_code, i.sku, i.name item, l.status, l.expiry_date, s.name supplier,
                l.source_type, l.source_id, COALESCE(b.on_hand,0) on_hand_qty, COALESCE(b.reserved,0) reserved_qty,
                COALESCE(sh.shipped,0) shipped_qty')
            ->orderByDesc('l.id')->limit($f->limit)->get();
        SourceDocumentPresenter::decorateRows($rows);
        $rows->each(fn ($r) => $r->source_document = $r->source_display ?? null);

        return [
            'columns' => ['lot_code', 'sku', 'item', 'status', 'expiry_date', 'supplier', 'on_hand_qty', 'reserved_qty', 'shipped_qty', 'source_document'],
            'rows' => $rows,
            'summary' => ['lots' => $rows->count(), 'on_hand' => (string) $rows->sum(fn ($r) => (float) $r->on_hand_qty)],
            'drilldown' => ['key' => 'lot_id', 'path' => '/traceability/lots'],
        ];
    }

    // ── 21. Serial Lifecycle ──
    private function reportSerialLifecycle(ReportFilters $f): array
    {
        $rows = $this->scoped('serial_numbers as s')
            ->join('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('lots as l', 'l.id', '=', 's.lot_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 's.shipment_id')
            ->leftJoin('sales_returns as sr', 'sr.id', '=', 's.sales_return_id')
            ->when($f->itemId, fn ($x) => $x->where('s.item_id', $f->itemId))
            ->when($f->status, fn ($x) => $x->where('s.status', $f->status))
            ->selectRaw('s.id serial_id, s.serial, i.sku, i.name item, s.status, w.name warehouse, l.lot_code,
                s.source_type, s.source_id, s.shipment_id, sh.shipment_number, s.sales_return_id, sr.return_number sales_return, s.warranty_until')
            ->orderByDesc('s.id')->limit($f->limit)->get();

        return [
            'columns' => ['serial', 'sku', 'item', 'status', 'warehouse', 'lot_code', 'shipment_number', 'sales_return', 'warranty_until'],
            'rows' => $rows,
            'summary' => ['serials' => $rows->count()],
            'drilldown' => ['key' => 'serial_id', 'path' => '/traceability/serials'],
        ];
    }

    // ── 22. Expiry Risk ──
    private function reportExpiryRisk(ReportFilters $f): array
    {
        $cutoff = now()->addDays($f->days)->toDateString();
        $balanceSubquery = $this->db()->table('stock_balances')
            ->where('organization_id', $this->orgId())
            ->selectRaw('lot_id, SUM(on_hand_qty) on_hand')
            ->groupBy('lot_id');

        $rows = $this->scoped('lots as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoinSub($balanceSubquery, 'b', 'b.lot_id', '=', 'l.id')
            ->whereNotNull('l.expiry_date')->where('l.status', '!=', 'consumed')
            ->whereDate('l.expiry_date', '<=', $cutoff)
            ->when($f->itemId, fn ($x) => $x->where('l.item_id', $f->itemId))
            ->selectRaw('l.id lot_id, l.lot_code, i.sku, i.name item, l.expiry_date, l.status,
                DATEDIFF(l.expiry_date, NOW()) days_to_expiry, COALESCE(b.on_hand,0) on_hand_qty')
            ->orderBy('l.expiry_date')->limit($f->limit)->get();

        $expired = $rows->filter(fn ($r) => (int) $r->days_to_expiry < 0)->count();

        return [
            'columns' => ['lot_code', 'sku', 'item', 'expiry_date', 'days_to_expiry', 'status', 'on_hand_qty'],
            'rows' => $rows,
            'summary' => ['within_days' => $f->days, 'at_risk' => $rows->count(), 'expired' => $expired,
                'on_hand' => (string) $rows->sum(fn ($r) => (float) $r->on_hand_qty)],
            'drilldown' => ['key' => 'lot_id', 'path' => '/traceability/lots'],
        ];
    }

    // ── 23. Recall Impact ──
    private function reportRecallImpact(ReportFilters $f): array
    {
        $rows = $this->scoped('recall_lines as rl')
            ->join('recalls as r', 'r.id', '=', 'rl.recall_id')
            ->join('items as i', 'i.id', '=', 'rl.item_id')
            ->leftJoin('lots as l', 'l.id', '=', 'rl.lot_id')
            ->leftJoin('serial_numbers as s', 's.id', '=', 'rl.serial_id')
            ->when($f->status, fn ($x) => $x->where('r.status', $f->status))
            ->when($f->itemId, fn ($x) => $x->where('rl.item_id', $f->itemId))
            ->selectRaw('r.id recall_id, r.recall_number, r.status, i.sku, i.name item, l.lot_code, s.serial,
                rl.on_hand_qty, rl.reserved_qty, rl.shipped_qty, rl.disposition')
            ->orderByDesc('r.id')->limit($f->limit)->get();

        return [
            'columns' => ['recall_number', 'status', 'sku', 'item', 'lot_code', 'serial', 'on_hand_qty', 'reserved_qty', 'shipped_qty', 'disposition'],
            'rows' => $rows,
            'summary' => [
                'recall_lines' => $rows->count(),
                'on_hand' => (string) $rows->sum(fn ($r) => (float) $r->on_hand_qty),
                'shipped' => (string) $rows->sum(fn ($r) => (float) $r->shipped_qty),
            ],
            'drilldown' => ['key' => 'recall_id', 'path' => '/recalls'],
        ];
    }
}
