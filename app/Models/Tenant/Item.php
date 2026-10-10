<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'items';

    protected $guarded = ['id'];

    protected $casts = [
        'available_for_sale'=>'boolean','available_for_purchase'=>'boolean','track_inventory'=>'boolean',
        'is_variant_parent' => 'boolean',
        'enable_reorder_alert' => 'boolean',
        'is_active' => 'boolean',
        'tracks_expiry' => 'boolean',
        'reorder_point' => 'decimal:4',
        'reorder_qty' => 'decimal:4',
        'weight' => 'decimal:4',
        'length' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'safety_stock' => 'decimal:4',
        'purchase_price' => 'decimal:4',
        'sales_price' => 'decimal:4',
    ];

    /** All catalog writers (including imports and synchronization) use this boundary. */
    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            $current = $this->exists
                ? static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()
                : null;
            $type = $current && ! $this->isDirty('item_type') ? $current->item_type : ($this->item_type ?? 'inventory');
            $typeChanged = $current && $type !== $current->item_type;
            if ($typeChanged && (($type === 'inventory') !== ($current->item_type === 'inventory'))
                && (StockLedger::query()->where('item_id', $this->getKey())->exists()
                    || StockBalance::query()->withoutGlobalScopes()->where('organization_id', $current->organization_id)
                        ->where('item_id', $this->getKey())->where(function ($q) {
                            $q->where('on_hand_qty', '<>', 0)->orWhere('reserved_qty', '<>', 0)->orWhere('total_value', '<>', 0);
                        })->exists())) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'item_type' => __('inventory.consumption.tracking_history'),
                ]);
            }
            // An unchanged inconsistent legacy flag is diagnostic only. Never rewrite it
            // as a side effect of a name/price update or a historical reversal.
            $trackingChanged = $this->isDirty('track_inventory');
            if ((! $current || $typeChanged || $trackingChanged)
                && $this->track_inventory !== null && $trackingChanged
                && (bool) $this->track_inventory !== ($type === 'inventory')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'track_inventory' => __('inventory.consumption.tracking_type_controlled'),
                ]);
            }
            if (! $current || $typeChanged || $trackingChanged) {
                $this->item_type = $type;
                $this->track_inventory = $type === 'inventory';
            }
            return parent::save($options);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ItemBrand::class, 'brand_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class, 'item_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ItemImage::class, 'item_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ItemAttachment::class, 'item_id');
    }

    public function supplierPrices(): HasMany
    {
        return $this->hasMany(SupplierPriceList::class, 'item_id');
    }

    /** The primary image, if any (for list/detail thumbnails). */
    public function primaryImage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ItemImage::class, 'item_id')->where('is_primary', true);
    }

    /** Effective costing method (item override → org default). */
    public function effectiveCostingMethod(): string
    {
        if ($this->costing_method) {
            return $this->costing_method;
        }

        $settings = InventorySetting::query()->first();

        return $settings?->default_costing_method ?? 'average';
    }

    public function tracksLots(): bool
    {
        return in_array($this->tracking_type, ['lot', 'lot_serial'], true);
    }

    public function tracksSerials(): bool
    {
        return in_array($this->tracking_type, ['serial', 'lot_serial'], true);
    }

    /** Expiry capture is required on IN only when the item opts into it. */
    public function tracksExpiry(): bool
    {
        return (bool) ($this->tracks_expiry ?? false);
    }
}
