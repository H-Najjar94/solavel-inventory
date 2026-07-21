<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;
    use SoftDeletes;

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
        'reversed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id');
    }

    public function reversal()
    {
        return $this->belongsTo(SalesReturn::class, 'reversal_sales_return_id');
    }
}
