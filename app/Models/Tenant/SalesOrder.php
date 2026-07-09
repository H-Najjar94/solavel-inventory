<?php

namespace App\Models\Tenant;

use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $table = 'inventory_sales_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'requested_ship_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id');
    }

    /** Header warehouse — surface a name, not a raw id. (Customer is the
        denormalized customer_name string; no customer table exists.) */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'source_id')
            ->where('source_type', 'sales_order')
            ->orderBy('priority')
            ->orderBy('expires_at');
    }
}
