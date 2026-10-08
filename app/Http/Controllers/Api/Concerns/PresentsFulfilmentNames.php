<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Tenant\Item;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Warehouse;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Fulfilment documents (pick lists, packs, shipments, returns) store bare ids.
 * Attach display names so pages show warehouse/order/item names, not "#id".
 * Lookups go through the org-scoped models, so names never cross organizations.
 */
trait PresentsFulfilmentNames
{
    /** @param iterable<\Illuminate\Database\Eloquent\Model> $documents */
    protected function nameDocuments(iterable $documents): void
    {
        $documents = collect($documents instanceof Paginator ? $documents->items() : $documents);
        $warehouseIds = $documents->pluck('warehouse_id')->filter()->unique();
        $orderIds = $documents->pluck('sales_order_id')->filter()->unique();
        $warehouses = $warehouseIds->isEmpty() ? collect() : Warehouse::query()->whereIn('id', $warehouseIds)->pluck('name', 'id');
        $orders = $orderIds->isEmpty() ? collect() : SalesOrder::query()->whereIn('id', $orderIds)->pluck('order_number', 'id');
        foreach ($documents as $document) {
            if ($document->warehouse_id) {
                $document->setAttribute('warehouse_name', $warehouses[$document->warehouse_id] ?? null);
            }
            if ($document->sales_order_id ?? null) {
                $document->setAttribute('sales_order_number', $orders[$document->sales_order_id] ?? null);
            }
        }
    }

    /** @param iterable<\Illuminate\Database\Eloquent\Model> $lines */
    protected function nameLines(iterable $lines): void
    {
        $lines = collect($lines);
        $itemIds = $lines->pluck('item_id')->filter()->unique();
        $items = $itemIds->isEmpty() ? collect() : Item::query()->whereIn('id', $itemIds)->get(['id', 'name', 'sku'])->keyBy('id');
        foreach ($lines as $line) {
            $item = $items[$line->item_id] ?? null;
            $line->setAttribute('item_name', $item?->name);
            $line->setAttribute('item_sku', $item?->sku);
        }
    }
}
