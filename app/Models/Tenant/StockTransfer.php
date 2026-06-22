<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'stock_transfers';

    protected $guarded = ['id'];

    protected $casts = ['transfer_date'=>'date','posted_at'=>'datetime','reversed_at'=>'datetime'];

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class, 'stock_transfer_id');
    }
}
