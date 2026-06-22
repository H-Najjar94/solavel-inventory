<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'stock_adjustments';

    protected $guarded = ['id'];

    protected $casts = [
        'adjustment_date' => 'date',
        'total_increase_value' => 'decimal:2',
        'total_decrease_value' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'stock_adjustment_id');
    }
}
