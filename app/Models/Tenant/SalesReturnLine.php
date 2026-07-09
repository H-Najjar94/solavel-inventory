<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'sales_return_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'returned_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
