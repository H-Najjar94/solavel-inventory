<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class UnitConversion extends Model
{
    use BelongsToOrganization;

    protected $table = 'unit_conversions';

    protected $guarded = ['id'];
}
