<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    // SolaStock's own supplier table — independent of Finance's `suppliers`
    // (different schema; SolaStock is installable standalone).
    protected $table = 'inventory_suppliers';

    protected $guarded = ['id'];

    protected $casts = ['contact'=>'array','is_active'=>'boolean'];
}
