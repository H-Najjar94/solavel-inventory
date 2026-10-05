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

    protected static function booted(): void
    {
        // Superseded rows remain durable audit evidence. Native costing/reversal
        // sees only the reviewed current derived projection.
        static::addGlobalScope('active_fifo_projection', function ($query) {
            if (\Illuminate\Support\Facades\Schema::connection($query->getModel()->getConnectionName())->hasColumn($query->getModel()->getTable(), 'superseded_fifo_correction_id')) {
                $query->whereNull('superseded_fifo_correction_id');
            }
        });
    }

    protected $table = 'cost_layer_consumptions';

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4'];
}
