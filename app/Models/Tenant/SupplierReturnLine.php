<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class SupplierReturnLine extends Model
{
    use BelongsToOrganization;
    protected $table = 'supplier_return_lines';
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:4', 'entered_qty' => 'decimal:4', 'unit_conversion_factor' => 'decimal:8', 'actual_return_cost_base' => 'decimal:6'];
    public function supplierReturn() { return $this->belongsTo(SupplierReturn::class); }
    public function goodsReceiptLine() { return $this->belongsTo(GoodsReceiptLine::class); }
}
