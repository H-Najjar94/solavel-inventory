<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SolaStock document for a SolaPOS sale/return consumption. See migration. */
class PosSaleConsumption extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'result' => 'array', 'transaction_date' => 'date', 'posted_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleConsumptionLine::class);
    }
}
