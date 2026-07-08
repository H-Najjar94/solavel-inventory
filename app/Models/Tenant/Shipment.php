<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;
    use LocksWhenPosted;

    protected $table = 'shipments';

    protected $guarded = ['id'];

    protected $casts = [
        'ship_date' => 'date',
        'posted_at' => 'datetime',
        'ship_to' => 'array',
        'package_weight' => 'decimal:4',
        'package_length' => 'decimal:4',
        'package_width' => 'decimal:4',
        'package_height' => 'decimal:4',
        'rate_amount' => 'decimal:2',
        'label_payload' => 'array',
        'label_generated_at' => 'datetime',
        'tracking_events' => 'array',
        'warranty_months' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id');
    }
}
