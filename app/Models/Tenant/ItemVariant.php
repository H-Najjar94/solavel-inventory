<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemVariant extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'item_variants';

    protected $guarded = ['id'];

    protected $casts = [
        'variant_attributes' => 'array',
        'purchase_price' => 'decimal:4',
        'sales_price' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
