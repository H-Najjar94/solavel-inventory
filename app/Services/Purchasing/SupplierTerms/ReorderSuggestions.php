<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\Item;
use App\Services\Access\WarehouseAccessService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Advisory purchase suggestions: items whose available plus open-PO quantity is at or
 * below the reorder point (warehouse rule first, then the item default). The quantity
 * tops up to max stock, else uses the reorder quantity, else covers the shortage, and is
 * then rounded to the chosen supplier's minimum order and multiple. Nothing is created;
 * the user opens a normal purchase order from a suggestion.
 */
final class ReorderSuggestions
{
    public function __construct(private readonly SupplierPriceResolver $prices) {}

    public function suggestions(?int $warehouseId, ?int $supplierId, int $limit = 200): array
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $incoming = DB::connection('tenant')->table('purchase_order_lines as pl')
            ->join('inventory_purchase_orders as po', 'po.id', '=', 'pl.purchase_order_id')
            ->where('po.organization_id', $org)->whereIn('po.status', ['draft', 'approved', 'partially_received'])
            ->whereNull('po.deleted_at')
            ->groupBy('pl.item_id', 'po.warehouse_id')
            ->selectRaw('pl.item_id, po.warehouse_id, SUM(GREATEST(pl.ordered_qty - pl.received_qty, 0)) incoming_qty');

        $query = DB::connection('tenant')->table('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('warehouse_reorder_rules as rr', function ($join) {
                $join->on('rr.item_id', '=', 'b.item_id')->on('rr.warehouse_id', '=', 'b.warehouse_id')
                    ->on('rr.organization_id', '=', 'b.organization_id');
            })
            ->leftJoinSub($incoming, 'inc', fn ($join) => $join->on('inc.item_id', '=', 'b.item_id')->on('inc.warehouse_id', '=', 'b.warehouse_id'))
            ->where('b.organization_id', $org)->where('i.is_active', true)->whereNull('i.deleted_at')
            ->where(fn ($q) => $q->whereNull('i.available_for_purchase')->orWhere('i.available_for_purchase', true))
            ->whereRaw('COALESCE(rr.reorder_point, i.reorder_point) IS NOT NULL')
            ->whereRaw('(b.on_hand_qty - b.reserved_qty + COALESCE(inc.incoming_qty, 0)) <= COALESCE(rr.reorder_point, i.reorder_point)')
            ->when($warehouseId, fn ($q) => $q->where('b.warehouse_id', $warehouseId))
            ->selectRaw('b.item_id, b.warehouse_id, w.name warehouse, i.sku, i.name item, i.base_unit_id,
                (b.on_hand_qty - b.reserved_qty) available_qty, COALESCE(inc.incoming_qty, 0) incoming_qty,
                COALESCE(rr.reorder_point, i.reorder_point) reorder_point,
                COALESCE(rr.reorder_qty, i.reorder_qty) reorder_qty,
                COALESCE(rr.max_stock, i.max_stock) max_stock')
            ->orderBy('i.name')->limit($limit);
        $allowed = app(WarehouseAccessService::class)->allowedIds();
        if ($allowed !== null) {
            $query->whereIn('b.warehouse_id', $allowed ?: [0]);
        }

        $items = Item::query()->whereIn('id', (clone $query)->pluck('b.item_id')->unique()->all())->get()->keyBy('id');
        $out = [];
        foreach ($query->get() as $row) {
            $projected = Decimal::add((string) $row->available_qty, (string) $row->incoming_qty);
            $target = $row->max_stock !== null && Decimal::gt((string) $row->max_stock, (string) $row->reorder_point)
                ? Decimal::sub((string) $row->max_stock, $projected)
                : ($row->reorder_qty !== null && Decimal::gt((string) $row->reorder_qty, '0')
                    ? (string) $row->reorder_qty
                    : Decimal::sub((string) $row->reorder_point, $projected));
            if (! Decimal::gt($target, '0')) {
                $target = (string) ($row->reorder_qty ?: '1');
            }
            $item = $items->get((int) $row->item_id);
            $price = $item ? $this->prices->suggest($item, $supplierId, Decimal::qty($target), null, null, null) : null;
            $choice = $supplierId ? ($price['suggestion'] ?? null) : ($this->preferred($price) ?? $price['best'] ?? null);
            if ($supplierId && ! $choice) {
                continue;
            }
            $quantity = $choice['suggested_entered_quantity'] ?? Decimal::qty($target);
            $out[] = [
                'item_id' => (int) $row->item_id,
                'sku' => $row->sku,
                'item' => $row->item,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse' => $row->warehouse,
                'available_qty' => Decimal::qty((string) $row->available_qty),
                'incoming_qty' => Decimal::qty((string) $row->incoming_qty),
                'reorder_point' => Decimal::qty((string) $row->reorder_point),
                'max_stock' => $row->max_stock !== null ? Decimal::qty((string) $row->max_stock) : null,
                'needed_qty' => Decimal::qty($target),
                'suggested_qty' => $quantity,
                'supplier' => $choice ? [
                    'id' => $choice['supplier_id'],
                    'name' => $choice['supplier']['name'] ?? null,
                    'is_preferred' => $choice['is_preferred'],
                    'currency_code' => $choice['currency_code'],
                    'unit_price_base_unit' => $choice['net_unit_price_base_unit'],
                    'estimated_total' => Decimal::money(Decimal::mul($quantity, $choice['net_unit_price_base_unit'])),
                    'lead_time_days' => $choice['lead_time_days'],
                    'expected_date' => $choice['expected_date'],
                    'quantity_adjusted' => $choice['quantity_adjusted'],
                ] : null,
            ];
        }

        return $out;
    }

    private function preferred(?array $price): ?array
    {
        foreach ($price['candidates'] ?? [] as $candidate) {
            if ($candidate['is_preferred']) {
                return $candidate;
            }
        }

        return null;
    }
}
