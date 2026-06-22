<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class ItemBrand extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'item_brands';

    protected $guarded = ['id'];
}
