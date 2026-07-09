<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockEntryLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'opening_stock_entry_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'entered_qty' => 'decimal:4',
        'unit_conversion_factor' => 'decimal:8',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(OpeningStockEntry::class, 'opening_stock_entry_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'entered_unit_id');
    }
}
