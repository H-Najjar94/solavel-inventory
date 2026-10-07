<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\{BelongsToOrganization, LocksWhenPosted};
use Illuminate\Database\Eloquent\Model;
final class SupplierReturn extends Model
{
    use BelongsToOrganization, LocksWhenPosted;
    protected $table = 'supplier_returns';
    protected $guarded = ['id'];
    protected $casts = ['return_date' => 'date', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    public function lines() { return $this->hasMany(SupplierReturnLine::class); }
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class); }
}
