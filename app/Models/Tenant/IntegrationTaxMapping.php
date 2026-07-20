<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IntegrationTaxMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_tax_mappings';

    protected $guarded = ['id'];
}
