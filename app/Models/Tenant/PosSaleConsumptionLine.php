<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PosSaleConsumptionLine extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];
}
