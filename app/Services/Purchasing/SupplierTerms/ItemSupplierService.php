<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPriceList;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owns supplier-item purchasing terms in SolaStock. Prices and quantities are kept
 * as decimal strings; tiers are in purchase units, priced per purchase unit.
 */
final class ItemSupplierService
{
    public const MIN_PRICE_SCALE = 3;
    public const MAX_PRICE_SCALE = 6;

    public function rules(bool $withSupplier = true): array
    {
        return array_filter([
            'supplier_id' => $withSupplier ? ['required', 'integer', 'min:1'] : null,
            'status' => ['nullable', 'string', 'in:'.implode(',', ItemSupplier::STATUSES)],
            'is_preferred' => ['nullable', 'boolean'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'supplier_item_name' => ['nullable', 'string', 'max:255'],
            'supplier_item_description' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['nullable', 'string', 'size:3', 'alpha'],
            'purchase_unit_id' => ['nullable', 'integer', 'min:1'],
            'minimum_order_qty' => ['nullable', 'numeric', 'gt:0'],
            'order_multiple' => ['nullable', 'numeric', 'gt:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price_scale' => ['nullable', 'integer', 'min:'.self::MIN_PRICE_SCALE, 'max:'.self::MAX_PRICE_SCALE],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tiers' => ['nullable', 'array', 'max:50'],
            'tiers.*.id' => ['nullable', 'integer', 'min:1'],
            'tiers.*.minimum_qty' => ['required', 'numeric', 'gt:0'],
            'tiers.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'tiers.*.effective_from' => ['nullable', 'date'],
            'tiers.*.effective_to' => ['nullable', 'date'],
            'tiers.*.is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** Create or update one relationship with its full tier set (tiers omitted = unchanged). */
    public function save(Item $item, array $data, ?ItemSupplier $existing, ?int $actorId): ItemSupplier
    {
        return DB::connection('tenant')->transaction(function () use ($item, $data, $existing, $actorId) {
            $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $supplierId = (int) ($existing?->supplier_id ?? $data['supplier_id']);
            $supplier = Supplier::query()->whereKey($supplierId)->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => __('inventory.supplier_terms.supplier_missing')]);
            }
            if (! $existing && ItemSupplier::query()->where('item_id', $item->id)->where('supplier_id', $supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => __('inventory.supplier_terms.duplicate_supplier')]);
            }

            $status = (string) ($data['status'] ?? $existing?->status ?? 'approved');
            if (! $existing && $status === 'approved' && ! (bool) $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => __('inventory.supplier_terms.supplier_inactive')]);
            }
            $scale = array_key_exists('price_scale', $data) ? $data['price_scale'] : $existing?->price_scale;
            $attributes = [
                'organization_id' => $item->organization_id,
                'item_id' => $item->id,
                'supplier_id' => $supplierId,
                'status' => $status,
                'updated_by' => $actorId,
            ];
            foreach (['supplier_sku', 'supplier_item_name', 'supplier_item_description', 'notes'] as $text) {
                if (array_key_exists($text, $data)) {
                    $value = trim((string) ($data[$text] ?? ''));
                    $attributes[$text] = $value === '' ? null : $value;
                }
            }
            if (array_key_exists('currency_code', $data) || ! $existing) {
                $attributes['currency_code'] = strtoupper((string) ($data['currency_code'] ?? '')) ?: app(InventoryReportService::class)->baseCurrency();
            }
            foreach (['minimum_order_qty', 'order_multiple', 'discount_percent'] as $decimal) {
                if (array_key_exists($decimal, $data)) {
                    $attributes[$decimal] = $data[$decimal] === null || $data[$decimal] === '' ? null : $this->decimal((string) $data[$decimal], $decimal, 6);
                }
            }
            if (array_key_exists('lead_time_days', $data)) {
                $attributes['lead_time_days'] = $data['lead_time_days'] === null || $data['lead_time_days'] === '' ? null : (int) $data['lead_time_days'];
            }
            if (array_key_exists('price_scale', $data)) {
                $attributes['price_scale'] = $scale === null || $scale === '' ? null : (int) $scale;
            }
            if (array_key_exists('purchase_unit_id', $data) || ! $existing) {
                [$unitId, $factor] = $this->purchaseUnit($item, $data['purchase_unit_id'] ?? null);
                $attributes['purchase_unit_id'] = $unitId;
                $attributes['purchase_unit_factor'] = $factor;
            }

            $relationship = $existing
                ? ItemSupplier::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail()
                : new ItemSupplier(['created_by' => $actorId]);
            $relationship->fill($attributes)->save();

            if (array_key_exists('tiers', $data) && $data['tiers'] !== null) {
                $this->replaceTiers($relationship, (array) $data['tiers'], $scale === null || $scale === '' ? null : (int) $scale);
            } elseif ($existing) {
                // Keep denormalized tier columns aligned with the relationship.
                SupplierPriceList::query()->where('item_supplier_id', $relationship->id)
                    ->update(['supplier_sku' => $relationship->supplier_sku, 'currency_code' => $relationship->currency_code]);
            }

            $preferred = $data['is_preferred'] ?? null;
            if ($status !== 'approved' && (int) $item->preferred_supplier_id === $supplierId) {
                if ($preferred === true) {
                    throw ValidationException::withMessages(['is_preferred' => __('inventory.supplier_terms.preferred_must_be_approved')]);
                }
                $item->forceFill(['preferred_supplier_id' => null])->save();
            } elseif ($preferred === true) {
                if ($status !== 'approved') {
                    throw ValidationException::withMessages(['is_preferred' => __('inventory.supplier_terms.preferred_must_be_approved')]);
                }
                $item->forceFill(['preferred_supplier_id' => $supplierId])->save();
            } elseif ($preferred === false && (int) $item->preferred_supplier_id === $supplierId) {
                $item->forceFill(['preferred_supplier_id' => null])->save();
            }

            return $relationship->fresh(['supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices']);
        }, 3);
    }

    public function delete(ItemSupplier $relationship): void
    {
        DB::connection('tenant')->transaction(function () use ($relationship) {
            $item = Item::query()->whereKey($relationship->item_id)->lockForUpdate()->firstOrFail();
            SupplierPriceList::query()->where('item_supplier_id', $relationship->id)->delete();
            if ((int) $item->preferred_supplier_id === (int) $relationship->supplier_id) {
                $item->forceFill(['preferred_supplier_id' => null])->save();
            }
            $relationship->delete();
        }, 3);
    }

    /** The relationship for a supplier, created with defaults when a legacy single-price call needs one. */
    public function ensure(Item $item, int $supplierId, array $defaults, ?int $actorId): ItemSupplier
    {
        $existing = ItemSupplier::query()->where('item_id', $item->id)->where('supplier_id', $supplierId)->first();

        return $existing ?? $this->save($item, ['supplier_id' => $supplierId] + $defaults, null, $actorId);
    }

    /** Validates and writes one legacy tier through the relationship rules. */
    public function writeTier(ItemSupplier $relationship, array $tier, ?SupplierPriceList $existing = null): SupplierPriceList
    {
        return DB::connection('tenant')->transaction(function () use ($relationship, $tier, $existing) {
            $tiers = $relationship->prices()->get()->map(fn (SupplierPriceList $row) => $this->tierInput($row))->keyBy('id')->all();
            $tier['id'] = $existing?->id;
            if ($existing) {
                $tiers[$existing->id] = $tier + $tiers[$existing->id];
            } else {
                $tiers[] = $tier;
            }
            $saved = $this->replaceTiers($relationship, array_values($tiers), $relationship->price_scale);

            return $existing ? $saved[$existing->id] : end($saved);
        }, 3);
    }

    public function present(ItemSupplier $relationship, ?Item $item = null): array
    {
        $item ??= $relationship->item;
        $relationship->loadMissing(['supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices']);
        $today = now()->toDateString();

        return [
            'id' => $relationship->id,
            'item_id' => $relationship->item_id,
            'supplier_id' => $relationship->supplier_id,
            'supplier' => $relationship->supplier?->only(['id', 'code', 'name', 'is_active']),
            'status' => $relationship->status,
            'is_preferred' => (int) $item?->preferred_supplier_id === (int) $relationship->supplier_id,
            'supplier_sku' => $relationship->supplier_sku,
            'supplier_item_name' => $relationship->supplier_item_name,
            'supplier_item_description' => $relationship->supplier_item_description,
            'currency_code' => $relationship->currency_code,
            'purchase_unit_id' => $relationship->purchase_unit_id,
            'purchase_unit' => $relationship->purchaseUnit?->only(['id', 'code', 'name', 'symbol']),
            'purchase_unit_factor' => (string) $relationship->purchase_unit_factor,
            'minimum_order_qty' => $relationship->minimum_order_qty,
            'order_multiple' => $relationship->order_multiple,
            'lead_time_days' => $relationship->lead_time_days,
            'discount_percent' => $relationship->discount_percent,
            'price_scale' => $relationship->price_scale,
            'notes' => $relationship->notes,
            'tiers' => $relationship->prices->map(fn (SupplierPriceList $tier) => [
                'id' => $tier->id,
                'minimum_qty' => (string) $tier->minimum_qty,
                'unit_cost' => (string) $tier->unit_cost,
                'effective_from' => $tier->effective_from?->toDateString(),
                'effective_to' => $tier->effective_to?->toDateString(),
                'is_active' => (bool) $tier->is_active,
                'is_current' => (bool) $tier->is_active
                    && (! $tier->effective_from || $tier->effective_from->toDateString() <= $today)
                    && (! $tier->effective_to || $tier->effective_to->toDateString() >= $today),
            ])->values()->all(),
            'updated_at' => $relationship->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, SupplierPriceList> keyed by id, in input order */
    private function replaceTiers(ItemSupplier $relationship, array $tiers, ?int $scale): array
    {
        $existing = SupplierPriceList::query()->where('item_supplier_id', $relationship->id)->lockForUpdate()->get()->keyBy('id');
        $normalized = [];
        foreach (array_values($tiers) as $index => $tier) {
            $id = isset($tier['id']) && $tier['id'] !== null && $tier['id'] !== '' ? (int) $tier['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["tiers.$index.id" => __('inventory.supplier_terms.tier_missing')]);
            }
            $from = $tier['effective_from'] ?? null ?: null;
            $to = $tier['effective_to'] ?? null ?: null;
            if ($from && $to && (string) $to < (string) $from) {
                throw ValidationException::withMessages(["tiers.$index.effective_to" => __('inventory.supplier_terms.validity_order')]);
            }
            $normalized[] = [
                'id' => $id,
                'minimum_qty' => $this->decimal((string) $tier['minimum_qty'], "tiers.$index.minimum_qty", 6, true),
                'unit_cost' => $this->decimal((string) $tier['unit_cost'], "tiers.$index.unit_cost", $scale ?? self::MAX_PRICE_SCALE),
                'effective_from' => $from ? substr((string) $from, 0, 10) : null,
                'effective_to' => $to ? substr((string) $to, 0, 10) : null,
                'is_active' => array_key_exists('is_active', $tier) && $tier['is_active'] !== null ? (bool) $tier['is_active'] : true,
            ];
        }
        $this->assertNoOverlap($normalized);

        $keep = array_filter(array_column($normalized, 'id'));
        SupplierPriceList::query()->where('item_supplier_id', $relationship->id)->whereNotIn('id', $keep ?: [0])->delete();
        $saved = [];
        foreach ($normalized as $tier) {
            $row = $tier['id'] ? $existing->get($tier['id']) : new SupplierPriceList();
            $row->fill([
                'organization_id' => $relationship->organization_id,
                'item_id' => $relationship->item_id,
                'supplier_id' => $relationship->supplier_id,
                'item_supplier_id' => $relationship->id,
                'supplier_sku' => $relationship->supplier_sku,
                'currency_code' => $relationship->currency_code,
                'unit_cost' => $tier['unit_cost'],
                'minimum_qty' => $tier['minimum_qty'],
                'effective_from' => $tier['effective_from'],
                'effective_to' => $tier['effective_to'],
                'is_active' => $tier['is_active'],
            ])->save();
            $saved[$row->id] = $row;
        }

        return $saved;
    }

    /** Two active tiers with the same minimum quantity must not be valid on the same day. */
    public function assertNoOverlap(array $tiers): void
    {
        $active = array_values(array_filter($tiers, fn ($tier) => $tier['is_active']));
        foreach ($active as $i => $a) {
            foreach (array_slice($active, $i + 1, null, true) as $j => $b) {
                if (Decimal::cmp($a['minimum_qty'], $b['minimum_qty']) !== 0) {
                    continue;
                }
                $aFrom = $a['effective_from'] ?? '0000-01-01';
                $aTo = $a['effective_to'] ?? '9999-12-31';
                $bFrom = $b['effective_from'] ?? '0000-01-01';
                $bTo = $b['effective_to'] ?? '9999-12-31';
                if ($aFrom <= $bTo && $bFrom <= $aTo) {
                    throw ValidationException::withMessages(["tiers.$j.minimum_qty" => __('inventory.supplier_terms.tier_overlap')]);
                }
            }
        }
    }

    private function tierInput(SupplierPriceList $row): array
    {
        return [
            'id' => $row->id,
            'minimum_qty' => (string) $row->minimum_qty,
            'unit_cost' => (string) $row->unit_cost,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'is_active' => (bool) $row->is_active,
        ];
    }

    /** @return array{0: ?int, 1: string} purchase unit id and base units per purchase unit */
    private function purchaseUnit(Item $item, mixed $unitId): array
    {
        if ($unitId === null || $unitId === '' || (int) $unitId === (int) $item->base_unit_id) {
            return [$item->base_unit_id ? (int) $item->base_unit_id : null, '1.00000000'];
        }
        try {
            $line = app(UnitConversionResolver::class)->normalizeLine([
                'item_id' => $item->id, 'entered_unit_id' => (int) $unitId, 'entered_qty' => '1',
            ], 'ordered_qty');
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['purchase_unit_id' => __('inventory.supplier_terms.purchase_unit_unconvertible')]);
        }

        return [(int) $unitId, (string) $line['unit_conversion_factor']];
    }

    /** Rejects (never rounds) more decimals than the allowed scale; trailing zeros are fine. */
    private function decimal(string $value, string $field, int $scale, bool $positive = false): string
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.(\d+))?$/D', $value, $match)) {
            throw ValidationException::withMessages([$field => __('inventory.supplier_terms.decimal_invalid')]);
        }
        $fraction = rtrim($match[1] ?? '', '0');
        if (strlen($fraction) > $scale) {
            throw ValidationException::withMessages([$field => __('inventory.supplier_terms.too_many_decimals', ['scale' => $scale])]);
        }
        if ($positive && ! Decimal::gt($value, '0')) {
            throw ValidationException::withMessages([$field => __('inventory.supplier_terms.decimal_invalid')]);
        }

        return Decimal::round($value, 6);
    }
}
