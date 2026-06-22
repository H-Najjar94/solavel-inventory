<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ShipmentLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'shipment_lines';

    protected $guarded = ['id'];
}
