<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

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
}
