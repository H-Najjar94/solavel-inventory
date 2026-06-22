<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

class InventoryAuditLog extends Model
{
    use BelongsToOrganization;
    use Immutable;

    protected $table = 'inventory_audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];
}
