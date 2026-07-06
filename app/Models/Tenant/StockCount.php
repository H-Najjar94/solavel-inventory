<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCount extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'stock_counts';

    protected $guarded = ['id'];

    protected $casts = ['count_date'=>'date','posted_at'=>'datetime','reversed_at'=>'datetime'];

    public function lines()
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_id');
    }

    /** Header warehouse — surface a name, not a raw id. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'adjustment_id');
    }
}
