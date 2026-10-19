<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class ReceivingRequestLine extends Model
{
    use BelongsToOrganization;
    use \App\Support\GuardsInactiveItem;

    protected $table = 'purchasing_receiving_request_lines';

    protected $guarded = ['id'];

    protected $casts = ['requested_qty' => 'decimal:6', 'received_qty' => 'decimal:6', 'unit_cost' => 'decimal:6', 'approval_conversion' => 'array'];
}
