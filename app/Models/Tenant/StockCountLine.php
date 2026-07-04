<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_count_lines';

    protected $guarded = ['id'];

    protected $casts = ['system_qty'=>'decimal:4','counted_qty'=>'decimal:4','variance_qty'=>'decimal:4'];

    /** The item this line is for — used to surface names, not raw IDs. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
