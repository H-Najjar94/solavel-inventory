<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class ReceivingRequestLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'purchasing_receiving_request_lines';

    protected $guarded = ['id'];

    protected $casts = ['requested_qty' => 'decimal:4', 'received_qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'approval_conversion' => 'array'];
}
