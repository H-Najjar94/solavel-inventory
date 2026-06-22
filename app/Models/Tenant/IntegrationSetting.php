<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_settings';

    protected $guarded = ['id'];

    protected $casts = ['meta'=>'array','last_sync_at'=>'datetime','require_mapping_before_post'=>'boolean'];
}
