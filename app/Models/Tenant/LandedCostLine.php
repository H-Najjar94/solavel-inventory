<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_landed_cost_lines';

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:4', 'receipt_value' => 'decimal:2', 'weight' => 'decimal:4', 'allocated_base_amount' => 'decimal:2', 'inventory_base_amount' => 'decimal:2', 'consumed_base_amount' => 'decimal:2'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
