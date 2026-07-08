<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'goods_receipt_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'received_qty' => 'decimal:4',
        'accepted_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'entered_qty' => 'decimal:4',
        'unit_conversion_factor' => 'decimal:8',
    ];

    /** The item this line is for — used to surface names, not raw IDs. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'entered_unit_id');
    }
}
