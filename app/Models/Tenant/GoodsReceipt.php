<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\BelongsToWarehouseScope;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use BelongsToOrganization;
    use BelongsToWarehouseScope;
    use LocksWhenPosted;
    use SoftDeletes;

    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    protected $casts = [
        'receipt_date' => 'date',
        'blind_receiving' => 'boolean',
        'posted_at' => 'datetime',
        'inspected_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(InventoryReversal::class, 'reversal_id');
    }
}
