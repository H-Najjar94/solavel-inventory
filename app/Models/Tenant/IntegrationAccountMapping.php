<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IntegrationAccountMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_account_mappings';

    protected $guarded = ['id'];

    protected $casts = ['last_verified_at'=>'datetime'];
}
