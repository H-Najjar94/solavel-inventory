<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
 use Illuminate\Database\Eloquent\Model;

class CostLayer extends Model
{
    use BelongsToOrganization;

    protected $table = 'cost_layers';

    protected $guarded = ['id'];
}
