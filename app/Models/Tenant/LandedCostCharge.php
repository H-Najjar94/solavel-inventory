<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LandedCostCharge extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_landed_cost_charges';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:4', 'base_amount' => 'decimal:2'];
}
