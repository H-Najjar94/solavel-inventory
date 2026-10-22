<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's purchasing terms for one item; supplier_price_lists rows are its price tiers. */
class ItemSupplier extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['approved', 'blocked', 'historical'];

    protected $table = 'item_suppliers';

    protected $guarded = ['id'];

    protected $casts = [
        'purchase_unit_factor' => 'decimal:8',
        'minimum_order_qty' => 'decimal:6',
        'order_multiple' => 'decimal:6',
        'discount_percent' => 'decimal:6',
        'lead_time_days' => 'integer',
        'price_scale' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupplierPriceList::class, 'item_supplier_id')->orderBy('minimum_qty')->orderBy('effective_from');
    }
}
