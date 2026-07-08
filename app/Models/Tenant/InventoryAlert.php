<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryAlert extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_alerts';

    protected $guarded = ['id'];

    protected $casts = [
        'channels' => 'array',
        'metadata' => 'array',
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];
}
