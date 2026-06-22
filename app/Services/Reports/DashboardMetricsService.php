<?php

namespace App\Services\Reports;

use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates dashboard KPIs from canonical projections + document tables.
 * Read-only.
 *
 * SECURITY: these are raw query-builder reads that bypass the Eloquent
 * OrganizationScope, so EVERY query MUST filter by the active organization_id.
 * Use scoped() (never db()->table() directly) — it injects the org filter and
 * fails closed (returns nothing) when there is no active org context.
 */
class DashboardMetricsService
{
    private function db()
    {
        return DB::connection(config('tenancy.tenant_connection', 'tenant'));
    }

    /** Active org id, or -1 (matches no rows → fail closed) when no context. */
    private function orgId(): int
    {
        $ctx = app(OrganizationContext::class);

        return $ctx->has() ? (int) $ctx->id() : -1;
    }

    /**
     * A tenant-table query already constrained to the active organization.
     * Accepts "table" or "table as alias" and qualifies organization_id with the
     * alias so it is safe to join.
     */
    private function scoped(string $table)
    {
        $alias = preg_match('/\s+as\s+(\w+)\s*$/i', $table, $m) ? $m[1] : $table;

        return $this->db()->table($table)->where($alias.'.organization_id', $this->orgId());
    }

    public function metrics(): array
    {
        $balances = $this->scoped('stock_balances')->get(['item_id', 'on_hand_qty', 'reserved_qty', 'total_value']);

        $inventoryValue = '0';
        $low = 0; $out = 0;
        foreach ($balances as $b) {
            $inventoryValue = Decimal::add($inventoryValue, (string) $b->total_value);
            $avail = (float) $b->on_hand_qty - (float) $b->reserved_qty;
            if ($avail <= 0) { $out++; } elseif ($avail <= 5) { $low++; }
        }

        $today = now()->toDateString();
        $deadCutoff = now()->subDays(90)->toDateTimeString();
        $movedItems = $this->scoped('stock_ledger')->where('direction', 'out')->where('moved_at', '>=', $deadCutoff)->distinct()->pluck('item_id');

        return [
            'inventory_value' => Decimal::money($inventoryValue),
            'total_skus' => $this->scoped('items')->count(),
            'active_items' => $this->scoped('items')->where('is_active', true)->count(),
            'low_stock' => $low,
            'out_of_stock' => $out,
            'movements_today' => $this->scoped('stock_ledger')->whereDate('moved_at', $today)->count(),
            'pending_pos' => $this->scoped('inventory_purchase_orders')->whereIn('status', ['draft', 'approved', 'partially_received'])->count(),
            'pending_grns' => $this->scoped('goods_receipts')->where('status', 'draft')->count(),
            'pending_transfers' => $this->scoped('stock_transfers')->where('status', 'draft')->count(),
            'pending_counts' => $this->scoped('stock_counts')->whereIn('status', ['draft', 'counting', 'review'])->count(),
            'warehouses' => $this->scoped('warehouses')->where('is_active', true)->count(),
            // ── Sales fulfillment KPIs ──
            'pending_sales_orders' => $this->scoped('inventory_sales_orders')->whereIn('status', ['draft', 'confirmed', 'partially_reserved', 'reserved'])->count(),
            'reserved_stock_qty' => (string) ($this->scoped('reservations')->where('status', 'active')->sum('qty') ?? 0),
            'awaiting_pick' => $this->scoped('inventory_sales_orders')->whereIn('status', ['reserved', 'partially_reserved'])->count(),
            'awaiting_pack' => $this->scoped('inventory_sales_orders')->whereIn('status', ['picked', 'partially_picked'])->count(),
            'awaiting_ship' => $this->scoped('inventory_sales_orders')->whereIn('status', ['packed', 'packing'])->count(),
            'shipments_today' => $this->scoped('shipments')->where('status', 'posted')->whereDate('ship_date', $today)->count(),
            // ── Traceability alerts ──
            'expiring_lots_30d' => $this->scoped('lots')->whereNotNull('expiry_date')
                ->where('status', '!=', 'consumed')
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->whereDate('expiry_date', '>=', $today)->count(),
            'expired_lots' => $this->scoped('lots')->whereNotNull('expiry_date')
                ->where('status', '!=', 'consumed')->whereDate('expiry_date', '<', $today)->count(),
            'recalled_lots' => $this->scoped('lots')->where('status', 'recalled')->count(),
            'quarantined_lots' => $this->scoped('lots')->where('status', 'quarantined')->count(),
            'quarantined_serials' => $this->scoped('serial_numbers')->where('status', 'quarantined')->count(),
            'active_recalls' => $this->scoped('recalls')->where('status', 'active')->count(),
            'dead_stock' => $this->scoped('stock_balances')->where('on_hand_qty', '>', 0)
                ->when($movedItems->isNotEmpty(), fn ($q) => $q->whereNotIn('item_id', $movedItems))->count(),
            'top_moving' => $this->scoped('stock_ledger as l')->join('items as i', 'i.id', '=', 'l.item_id')
                ->where('l.direction', 'out')->where('l.moved_at', '>=', now()->subDays(30)->toDateTimeString())
                ->selectRaw('i.sku, i.name, SUM(l.quantity) qty')->groupBy('i.sku', 'i.name')
                ->orderByDesc('qty')->limit(5)->get(),
            'recent_movements' => $this->scoped('stock_ledger')->orderByDesc('id')->limit(10)
                ->get(['id', 'item_id', 'warehouse_id', 'direction', 'quantity', 'unit_cost', 'moved_at', 'source_type', 'source_id']),
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
