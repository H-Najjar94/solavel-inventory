<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class WarehouseZone extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'warehouse_zones';

    protected $guarded = ['id'];
}
