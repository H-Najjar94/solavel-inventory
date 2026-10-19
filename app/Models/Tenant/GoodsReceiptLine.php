<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToOrganization;
    use \App\Support\GuardsInactiveItem;

    protected $table = 'goods_receipt_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'received_qty' => 'decimal:6',
        'accepted_qty' => 'decimal:6',
        'rejected_qty' => 'decimal:6',
        'quarantine_qty' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'entered_qty' => 'decimal:6',
        'unit_conversion_factor' => 'decimal:8',
        'unit_conversion_precision' => 'integer',
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
