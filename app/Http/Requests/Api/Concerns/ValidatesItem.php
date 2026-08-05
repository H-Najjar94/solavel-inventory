<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared item validation: org-scoped uniqueness (SKU, barcode) and the domain
 * guards (service items can't track stock, lot/serial/expiry coherence, costing
 * method / item type immutability once stock exists). Used by Store + Update.
 *
 * The frontend may send either tracking_type directly, or the boolean trio
 * track_lot/track_serial/track_expiry — prepareForValidation() normalizes those
 * into tracking_type so the engine sees one canonical field.
 */
trait ValidatesItem
{
    /**
     * Normalize blank numeric fields from the form. These map to DECIMAL columns
     * and MySQL rejects '' (SQLSTATE 22007 / 1366). Critically, the columns
     * differ:
     *   - reorder_point / reorder_qty are NULLABLE → a blank means "unset" → null.
     *   - purchase_price / sales_price are NOT NULL (DB default 0.0000) → a blank
     *     means "no price" → 0. (This was the real create-item bug: the global
     *     ConvertEmptyStringsToNull middleware turned a blank price into null,
     *     then the NOT NULL column rejected it and item creation failed.)
     * Doing this here makes creation robust regardless of middleware.
     */
    protected function normalizeNumerics(): void
    {
        // NB: the global ConvertEmptyStringsToNull middleware runs BEFORE this, so
        // a blank field arrives here as null (not ''). We must treat BOTH null and
        // '' as "blank" — checking only for '' would miss the real production case
        // and let a null reach a NOT NULL price column.
        $isBlank = fn (string $f) => $this->has($f)
            && ($this->input($f) === '' || $this->input($f) === null);

        $patch = [];
        // Nullable columns: blank → null (the DB accepts null / treats as unset).
        foreach (['reorder_point', 'reorder_qty', 'weight', 'length', 'width', 'height', 'min_stock', 'max_stock', 'safety_stock'] as $f) {
            if ($isBlank($f)) {
                $patch[$f] = null;
            }
        }
        // NOT NULL price columns (DB default 0.0000): blank → 0 ("no price").
        foreach (['purchase_price', 'sales_price'] as $f) {
            if ($isBlank($f)) {
                $patch[$f] = '0';
            }
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    protected function normalizeTracking(): void
    {
        if ($this->has('track_lot') || $this->has('track_serial')) {
            $lot = $this->boolean('track_lot');
            $serial = $this->boolean('track_serial');
            $type = match (true) {
                $lot && $serial => 'lot_serial',
                $serial => 'serial',
                $lot => 'lot',
                default => 'none',
            };
            $this->merge(['tracking_type' => $type]);
        }
    }

    protected function itemId(): ?int
    {
        $route = $this->route('item');

        return $route instanceof Item ? (int) $route->id : (is_numeric($route) ? (int) $route : null);
    }

    protected function applyItemDomainRules(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $orgId = app(\App\Tenancy\OrganizationContext::class)->id();
            $conn = config('tenancy.tenant_connection', 'tenant');
            $id = $this->itemId();

            $sku = $this->input('sku');
            $barcode = $this->input('barcode');
            $type = $this->input('item_type');
            $tracking = $this->input('tracking_type', 'none');
            $current = $id && $orgId
                ? Item::on($conn)->where('organization_id', $orgId)->find($id)
                : null;
            $effectiveType = (string) ($this->input('item_type', $current?->item_type ?? ''));
            $effectiveCategory = $this->has('category_id') ? $this->input('category_id') : $current?->category_id;
            $effectiveUnit = $this->has('base_unit_id') ? $this->input('base_unit_id') : $current?->base_unit_id;

            if (empty($effectiveCategory)) {
                $v->errors()->add('category_id', __('inventory.validation.category_required'));
            }
            if ($effectiveType === 'inventory' && empty($effectiveUnit)) {
                $v->errors()->add('base_unit_id', __('inventory.validation.unit_required_for_inventory'));
            }

            // org-scoped SKU uniqueness
            if ($sku && $orgId) {
                $exists = Item::on($conn)->where('organization_id', $orgId)->where('sku', $sku)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                if ($exists) {
                    $v->errors()->add('sku', __('inventory.validation.sku_unique'));
                }
            }

            // org-scoped barcode uniqueness (when present) — checks item_barcodes
            if ($barcode && $orgId) {
                $dup = \Illuminate\Support\Facades\DB::connection($conn)->table('item_barcodes')
                    ->where('organization_id', $orgId)->where('barcode', $barcode)->exists();
                if ($dup) {
                    $v->errors()->add('barcode', __('inventory.validation.barcode_unique'));
                }
            }

            // service items cannot track stock
            if ($type === 'service' && $tracking !== 'none') {
                $v->errors()->add('tracking_type', __('inventory.validation.service_tracking'));
            }

            // expiry requires lot tracking
            if ($this->boolean('track_expiry') && ! in_array($tracking, ['lot', 'lot_serial'], true)) {
                $v->errors()->add('track_expiry', __('inventory.validation.expiry_requires_lot'));
            }

            // costing method / item type immutability once stock exists
            if ($id && $orgId) {
                $hasStock = StockBalance::on($conn)->where('organization_id', $orgId)
                    ->where('item_id', $id)->where('on_hand_qty', '>', 0)->exists();
                if ($hasStock) {
                    if ($current) {
                        if ($this->filled('costing_method') && $this->input('costing_method') !== $current->costing_method) {
                            $v->errors()->add('costing_method', __('inventory.validation.costing_locked'));
                        }
                        if ($this->filled('item_type') && $this->input('item_type') !== $current->item_type) {
                            $v->errors()->add('item_type', __('inventory.validation.item_type_locked'));
                        }
                    }
                }
            }
        });
    }

    protected function baseItemRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $orgId = app(\App\Tenancy\OrganizationContext::class)->id();
        $activeForOrg = static fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($query) => $query->where('organization_id', $orgId)->where('is_active', true)->whereNull('deleted_at')
        );

        return [
            'sku' => [$req, 'string', 'max:191'],
            'name' => [$req, 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'item_type' => [$partial ? 'sometimes' : 'required', \Illuminate\Validation\Rule::in(['inventory', 'non_inventory', 'service'])],
            'tracking_type' => ['nullable', \Illuminate\Validation\Rule::in(['none', 'lot', 'serial', 'lot_serial'])],
            'track_lot' => ['boolean'],
            'track_serial' => ['boolean'],
            'track_expiry' => ['boolean'],
            'category_id' => [$partial ? 'sometimes' : 'required', 'integer', $activeForOrg('item_categories')],
            'brand_id' => ['nullable', 'integer'],
            'base_unit_id' => [$partial ? 'sometimes' : 'nullable', 'integer', $activeForOrg('units')],
            'preferred_supplier_id' => ['nullable', 'integer'],
            'costing_method' => ['nullable', \Illuminate\Validation\Rule::in(['average', 'fifo', 'standard'])],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }
}
