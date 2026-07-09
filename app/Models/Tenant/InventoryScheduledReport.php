<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryScheduledReport extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_scheduled_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'filters' => 'array',
        'recipients' => 'array',
        'last_payload' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_delivered_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
