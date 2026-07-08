<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Models\Tenant\UnitConversion;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

class UnitConversionResolver
{
    /**
     * Normalize a customer-entered quantity to the item's base stock unit.
     *
     * Input keys:
     * - quantity field: ordered_qty, received_qty or quantity
     * - entered_qty: optional original quantity
     * - entered_unit_id: optional unit selected by the user
     */
    public function normalizeLine(array $line, string $quantityKey): array
    {
        $enteredUnitId = $line['entered_unit_id'] ?? null;
        $enteredQty = (string) ($line['entered_qty'] ?? $line[$quantityKey] ?? '0');

        if (! $enteredUnitId) {
            $qty = Decimal::qty((string) ($line[$quantityKey] ?? $enteredQty));
            return $line + [
                'entered_qty' => $enteredQty,
                'entered_unit_id' => null,
                'unit_conversion_factor' => null,
                $quantityKey => $qty,
            ];
        }

        $item = Item::query()->findOrFail((int) $line['item_id']);
        if (! $item->base_unit_id) {
            throw new RuntimeException("Item {$item->sku} has no base unit for conversion.");
        }

        if ((int) $enteredUnitId === (int) $item->base_unit_id) {
            $factor = '1.00000000';
        } else {
            $conversion = UnitConversion::query()
                ->where('from_unit_id', (int) $enteredUnitId)
                ->where('to_unit_id', (int) $item->base_unit_id)
                ->where(fn ($q) => $q->whereNull('item_id')->orWhere('item_id', $item->id))
                ->orderByRaw('item_id is null')
                ->first();

            if (! $conversion) {
                throw new RuntimeException("No conversion from selected unit to {$item->sku}'s base unit.");
            }
            $factor = (string) $conversion->factor;
        }

        $baseQty = Decimal::qty(Decimal::mul($enteredQty, $factor));

        return array_merge($line, [
            'entered_qty' => Decimal::qty($enteredQty),
            'entered_unit_id' => (int) $enteredUnitId,
            'unit_conversion_factor' => $factor,
            $quantityKey => $baseQty,
        ]);
    }
}
