<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class WarehouseBin extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'warehouse_bins';

    protected $guarded = ['id'];
}
