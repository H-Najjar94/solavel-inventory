<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ItemIntegrationMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'item_integration_mappings';

    protected $guarded = ['id'];

    protected $casts = ['last_synced_at'=>'datetime'];
}
