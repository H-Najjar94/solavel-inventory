<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class ReceivingRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchasing_receiving_requests';

    protected $guarded = ['id'];

    protected $casts = ['source_payload' => 'array', 'exchange_rate_date' => 'date:Y-m-d'];

    public function lines()
    {
        return $this->hasMany(ReceivingRequestLine::class, 'receiving_request_id');
    }
}
