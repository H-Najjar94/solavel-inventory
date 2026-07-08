<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'sales_order_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'ordered_qty' => 'decimal:4',
        'reserved_qty' => 'decimal:4',
        'picked_qty' => 'decimal:4',
        'packed_qty' => 'decimal:4',
        'shipped_qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /** The item this line is for — used to surface names, not raw IDs. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
