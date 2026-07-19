<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'allow_negative_stock' => 'boolean',
        'numbering' => 'array',
        'barcode' => 'array',
        'approvals' => 'array',
        'adjustment_reason_codes' => 'array',
        'taxes' => 'array',
    ];
}
