<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    protected static function booted(): void
    {
        $record = static fn ($party) => app(\App\Services\Integration\PartySyncLedger::class)->changed($party, 'stock', 'customer');
        static::saved($record);
        static::deleted($record);
        static::registerModelEvent('restored', $record);
    }

    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'inventory_customers';

    protected $guarded = ['id'];

    protected $casts = ['contact' => 'array', 'is_active' => 'boolean'];
}
