<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
 use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'units';

    protected $guarded = ['id'];
}
