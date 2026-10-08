<?php

namespace Tests\Support;

use App\Models\Tenant\Item;
use App\Models\Tenant\Lot;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\Warehouse;

/**
 * Minimal master-data factory for stock-engine tests. Creates rows in the active
 * tenant DB using the org-scoped models (organization_id stamped automatically).
 * Assumes a tenant + org context is already active (via TenantAware::useTenantA()).
 */
class StockTestFactory
{
    public static function warehouse(array $attrs = []): Warehouse
    {
        static $n = 0;
        $n++;

        return Warehouse::create(array_merge([
            'code' => 'WH'.$n,
            'name' => 'Warehouse '.$n,
            'type' => 'warehouse',
            'is_active' => true,
        ], $attrs));
    }

    public static function item(array $attrs = []): Item
    {
        static $n = 0;
        $n++;

        return Item::create(array_merge([
            'sku' => 'SKU'.$n,
            'name' => 'Item '.$n,
            'item_type' => 'inventory',
            'tracking_type' => 'none',
            'costing_method' => 'average',
            'is_active' => true,
        ], $attrs));
    }

    public static function averageItem(array $attrs = []): Item
    {
        return self::item(array_merge(['costing_method' => 'average'], $attrs));
    }

    public static function fifoItem(array $attrs = []): Item
    {
        return self::item(array_merge(['costing_method' => 'fifo'], $attrs));
    }

    public static function lotItem(array $attrs = []): Item
    {
        return self::item(array_merge(['tracking_type' => 'lot'], $attrs));
    }

    public static function serialItem(array $attrs = []): Item
    {
        return self::item(array_merge(['tracking_type' => 'serial'], $attrs));
    }

    public static function lot(Item $item, array $attrs = []): Lot
    {
        static $n = 0;
        $n++;

        return Lot::create(array_merge([
            'item_id' => $item->id,
            'lot_code' => 'LOT'.$n,
        ], $attrs));
    }

    public static function serial(Item $item, string $serial, array $attrs = []): SerialNumber
    {
        return SerialNumber::create(array_merge([
            'item_id' => $item->id,
            'serial' => $serial,
            'status' => 'pending', // not yet in stock until an inbound posts
        ], $attrs));
    }
}
