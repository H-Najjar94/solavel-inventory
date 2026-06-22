<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'sales_order_lines';

    protected $guarded = ['id'];
}
