<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The canonical stock ledger. Append-only and immutable (Immutable trait blocks
 * update/delete at the app layer; DB triggers block them at the engine layer).
 *
 * NOTHING outside App\Services\Stock\StockLedgerService may create rows here —
 * see the architecture guard test. Quantities/costs are decimal-cast.
 */
class StockLedger extends Model
{
    use BelongsToOrganization;
    use Immutable;

    protected $table = 'stock_ledger';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'balance_qty_after' => 'decimal:4',
        'balance_value_after' => 'decimal:2',
        'moved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** The warehouse this movement hit — used to surface names, not raw IDs. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * The FIFO cost layers this (outbound) movement drew down. Empty for inbound
     * or average-costed movements. Read-only — for valuation/movement visibility.
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(CostLayerConsumption::class, 'ledger_id');
    }
}
