<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

class UnitConversionResolver
{
    public const CONTRACT_VERSION = 'solastock-unit-conversion.v1';

    public const PRECISION = Decimal::QTY_SCALE;

    public const ROUNDING_MODE = 'HALF_UP';

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
        $enteredQty = (string) ($line['entered_qty'] ?? $line[$quantityKey] ?? '0');
        if (! preg_match('/^\d+(?:\.\d+)?$/', $enteredQty) || Decimal::cmp($enteredQty, '0') <= 0) {
            throw new RuntimeException('Entered quantity must be a positive decimal.');
        }

        $item = Item::query()->findOrFail((int) $line['item_id']);
        if (! $item->base_unit_id && empty($line['entered_unit_id'])) {
            return array_merge($line, [
                'entered_qty' => Decimal::qty($enteredQty), 'entered_unit_id' => null,
                'base_unit_id' => null, 'unit_conversion_id' => null, 'unit_conversion_factor' => null,
                'unit_conversion_version' => null, 'unit_conversion_hash' => null,
                'unit_conversion_precision' => null, 'unit_conversion_rounding_mode' => null,
                $quantityKey => Decimal::qty((string) ($line[$quantityKey] ?? $enteredQty)),
            ]);
        }
        if (! $item->base_unit_id || ! (bool) $item->is_active || $item->deleted_at !== null) {
            throw new RuntimeException("Item {$item->sku} has no base unit for conversion.");
        }
        $enteredUnitId = (int) ($line['entered_unit_id'] ?? $item->base_unit_id);
        $units = Unit::query()->withTrashed()
            ->whereIn('id', [$enteredUnitId, (int) $item->base_unit_id])->get()->keyBy('id');
        foreach ([$enteredUnitId, (int) $item->base_unit_id] as $unitId) {
            $unit = $units->get($unitId);
            if (! $unit || (int) $unit->organization_id !== (int) $item->organization_id
                || ! (bool) $unit->is_active || $unit->deleted_at !== null) {
                throw new RuntimeException('Unit conversion references an inactive or cross-organization unit.');
            }
        }

        if ((int) $enteredUnitId === (int) $item->base_unit_id) {
            $factor = '1.00000000';
            $conversionId = null;
        } else {
            $conversion = UnitConversion::query()
                ->where('organization_id', (int) $item->organization_id)
                ->where('from_unit_id', (int) $enteredUnitId)
                ->where('to_unit_id', (int) $item->base_unit_id)
                ->where(fn ($q) => $q->whereNull('item_id')->orWhere('item_id', $item->id))
                ->orderByRaw('item_id is null')
                ->first();

            if (! $conversion) {
                throw new RuntimeException("No conversion from selected unit to {$item->sku}'s base unit.");
            }
            $factor = (string) $conversion->factor;
            $conversionId = (int) $conversion->id;
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/', $factor) || Decimal::cmp($factor, '0') <= 0) {
            throw new RuntimeException('Unit conversion factor must be a positive decimal.');
        }

        $normalizedSourceQty = Decimal::qty($enteredQty);
        $baseQty = Decimal::qty(Decimal::mul($normalizedSourceQty, $factor));
        $snapshot = [
            'organization_id' => (int) $item->organization_id,
            'item_id' => (int) $item->id,
            'source_unit_id' => $enteredUnitId,
            'base_unit_id' => (int) $item->base_unit_id,
            'conversion_id' => $conversionId,
            'factor' => Decimal::round($factor, 8),
            'version' => self::CONTRACT_VERSION,
            'precision' => self::PRECISION,
            'rounding_mode' => self::ROUNDING_MODE,
        ];

        return array_merge($line, [
            'entered_qty' => $normalizedSourceQty,
            'entered_unit_id' => $enteredUnitId,
            'base_unit_id' => (int) $item->base_unit_id,
            'unit_conversion_id' => $conversionId,
            'unit_conversion_factor' => $snapshot['factor'],
            'unit_conversion_version' => self::CONTRACT_VERSION,
            'unit_conversion_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'unit_conversion_precision' => self::PRECISION,
            'unit_conversion_rounding_mode' => self::ROUNDING_MODE,
            $quantityKey => $baseQty,
        ]);
    }
}
