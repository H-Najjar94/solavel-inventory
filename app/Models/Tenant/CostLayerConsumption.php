<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One FIFO layer draw-down by an outbound ledger movement. Enables exact
 * layer restoration on reversal (see StockLedgerService::reverse).
 */
class CostLayerConsumption extends Model
{
    use BelongsToOrganization;

    protected $table = 'cost_layer_consumptions';

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4'];
}
