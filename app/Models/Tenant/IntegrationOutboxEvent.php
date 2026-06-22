<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IntegrationOutboxEvent extends Model
{
    use BelongsToOrganization;

    protected $table = 'integration_outbox_events';

    protected $guarded = ['id'];

    protected $casts = ['payload'=>'array','occurred_at'=>'datetime','next_attempt_at'=>'datetime','sent_at'=>'datetime'];
}
