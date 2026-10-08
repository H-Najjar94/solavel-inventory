<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LandedCostComponent extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_landed_cost_components';

    protected $guarded = ['id'];

    protected $casts = ['provenance' => 'array', 'posted_base_amount' => 'decimal:2'];
}
