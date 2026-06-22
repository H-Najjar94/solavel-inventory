<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'goods_receipt_lines';

    protected $guarded = ['id'];

    protected $casts = ['received_qty'=>'decimal:4','accepted_qty'=>'decimal:4','unit_cost'=>'decimal:4'];
}
