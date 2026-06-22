<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'inventory_sales_orders';

    protected $guarded = ['id'];

    protected $casts = ['order_date'=>'date','requested_ship_date'=>'date'];

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id');
    }
}
