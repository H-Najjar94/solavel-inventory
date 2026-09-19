<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
final class IntegrationPurchaseCostAdjustment extends Model {
    protected $connection='tenant'; protected $table='integration_purchase_cost_adjustments'; protected $guarded=['id'];
    protected $casts=['safe_metadata'=>'array','applied_at'=>'datetime','reversed_at'=>'datetime'];
    protected static function booted():void{
        static::updating(function(self $m):void{if($m->isDirty(array_diff(array_keys($m->getAttributes()),['state','applied_at','reversed_at','updated_at'])))
            throw \Illuminate\Validation\ValidationException::withMessages(['purchase_cost_adjustment'=>'Purchase-cost evidence is immutable.']);});
        static::deleting(fn()=>throw \Illuminate\Validation\ValidationException::withMessages(['purchase_cost_adjustment'=>'Purchase-cost evidence is never deleted.']));
    }
}
