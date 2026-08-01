<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ShipmentLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'shipment_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4', 'entered_qty' => 'decimal:4',
        'unit_conversion_factor' => 'decimal:8', 'unit_conversion_precision' => 'integer',
    ];
}
