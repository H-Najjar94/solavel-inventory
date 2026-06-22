<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchase_order_lines';

    protected $guarded = ['id'];

    protected $casts = ['ordered_qty'=>'decimal:4','received_qty'=>'decimal:4','unit_price'=>'decimal:4'];
}
