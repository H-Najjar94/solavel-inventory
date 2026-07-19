<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryUserWarehouse extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_user_warehouses';

    protected $guarded = ['id'];
}
