<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    protected $casts = ['receipt_date'=>'date','posted_at'=>'datetime','reversed_at'=>'datetime'];

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }
}
