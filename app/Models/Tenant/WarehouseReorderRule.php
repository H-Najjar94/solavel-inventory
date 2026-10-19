<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\BelongsToWarehouseScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseReorderRule extends Model
{
    use BelongsToOrganization;
    use BelongsToWarehouseScope;

    protected $table = 'warehouse_reorder_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'reorder_point' => 'decimal:6',
        'reorder_qty' => 'decimal:6',
        'min_stock' => 'decimal:6',
        'max_stock' => 'decimal:6',
        'safety_stock' => 'decimal:6',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
