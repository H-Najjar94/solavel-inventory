<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'item_categories';

    protected $guarded = ['id'];
}
