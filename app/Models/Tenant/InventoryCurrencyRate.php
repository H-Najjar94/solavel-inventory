<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InventoryCurrencyRate extends Model
{
    use BelongsToOrganization;

    protected $table = 'inventory_currency_rates';

    protected $guarded = ['id'];

    protected $casts = [
        'rate_to_base' => 'decimal:8',
        'effective_date' => 'date',
    ];
}
