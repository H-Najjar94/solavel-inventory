<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Freight, duty and insurance applied to posted receipt lines (aggregate "LandedCost"). */
class LandedCost extends Model
{
    use BelongsToOrganization;
    use LocksWhenPosted;

    protected $table = 'stock_landed_costs';

    protected $guarded = ['id'];

    protected $casts = [
        'landed_cost_date' => 'date:Y-m-d',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function charges(): HasMany
    {
        return $this->hasMany(LandedCostCharge::class, 'landed_cost_id')->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class, 'landed_cost_id')->orderBy('id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(LandedCostComponent::class, 'landed_cost_id')->orderBy('id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(InventoryReversal::class, 'reversal_id');
    }
}
