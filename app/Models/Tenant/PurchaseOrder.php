<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\BelongsToWarehouseScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToOrganization;
    use BelongsToWarehouseScope;
    use SoftDeletes;

    protected $table = 'inventory_purchase_orders';

    protected $guarded = ['id'];

    protected $casts = ['order_date' => 'date', 'expected_date' => 'date'];

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function backorders(): HasMany
    {
        return $this->hasMany(PurchaseOrderBackorder::class, 'purchase_order_id');
    }

    /** Header warehouse — surface a name, not a raw id. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** Header supplier — surface a name, not a raw id. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
