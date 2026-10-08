<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'sales_return_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'returned_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'entered_qty' => 'decimal:4',
        'unit_conversion_factor' => 'decimal:8',
        'unit_conversion_precision' => 'integer',
    ];

    public function sourceShipmentLine(): BelongsTo
    {
        return $this->belongsTo(ShipmentLine::class, 'source_shipment_line_id');
    }

    public function sourceStockLedger(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'source_stock_ledger_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
