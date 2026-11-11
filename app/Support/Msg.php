<?php

namespace App\Support;

use App\Models\Tenant\Item;
use App\Models\Tenant\Warehouse;

/** Helpers that turn internal values into user-facing labels for error messages. */
final class Msg
{
    public static function status(?string $status): string
    {
        $status = (string) $status;
        $key = 'inventory.status.'.$status;
        $label = __($key);

        return $label === $key ? $status : $label;
    }

    public static function doc(string $type): string
    {
        return __('inventory.doc_type.'.$type);
    }

    public static function sku(int|string|null $itemId): string
    {
        if (! $itemId) {
            return '';
        }
        try {
            $sku = Item::query()->whereKey($itemId)->value('sku');
        } catch (\Throwable) {
            $sku = null;
        }

        return (string) ($sku ?: '#'.$itemId);
    }

    public static function warehouse(int|string|null $warehouseId): string
    {
        if (! $warehouseId) {
            return '';
        }
        try {
            $name = Warehouse::query()->whereKey($warehouseId)->value('name');
        } catch (\Throwable) {
            $name = null;
        }

        return (string) ($name ?: '#'.$warehouseId);
    }
}
