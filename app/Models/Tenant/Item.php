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
        'is_variant_parent' => 'boolean',
        'enable_reorder_alert' => 'boolean',
        'is_active' => 'boolean',
        'tracks_expiry' => 'boolean',
        'reorder_point' => 'decimal:4',
        'reorder_qty' => 'decimal:4',
        'purchase_price' => 'decimal:4',
        'sales_price' => 'decimal:4',
    ];

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
