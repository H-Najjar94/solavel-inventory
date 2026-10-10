<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\Concerns\BelongsToWarehouseScope;
use App\Tenancy\Concerns\LocksWhenPosted;
use Illuminate\Database\Eloquent\Model;
class InternalConsumption extends Model {
    use BelongsToOrganization, BelongsToWarehouseScope, LocksWhenPosted;
    protected $guarded = ['id'];
    protected $casts = ['document_date'=>'date', 'posted_at'=>'datetime', 'approved_at'=>'datetime', 'accounting_connected'=>'boolean'];
    public function lines() { return $this->hasMany(InternalConsumptionLine::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function originalIssue() { return $this->belongsTo(self::class, 'original_issue_id'); }
}
