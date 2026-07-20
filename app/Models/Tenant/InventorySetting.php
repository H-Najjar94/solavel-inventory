<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

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
        'expiry_warning_days' => 'integer',
    ];

    public static function expiryWarningDays(): int
    {
        $connection = config('tenancy.tenant_connection', 'tenant');
        if (! Schema::connection($connection)->hasColumn('inventory_settings', 'expiry_warning_days')) {
            return 30;
        }

        return (int) (static::query()->value('expiry_warning_days') ?? 30);
    }
}
