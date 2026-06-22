<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'inventory_purchase_orders';

    protected $guarded = ['id'];

    protected $casts = ['order_date'=>'date','expected_date'=>'date'];

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }
}
