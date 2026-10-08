<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
final class IntegrationPurchaseCostAdjustmentComponent extends Model {
    protected $connection='tenant'; protected $table='integration_purchase_cost_adjustment_components'; protected $guarded=['id'];
    protected $casts=['provenance'=>'array'];
    protected static function booted():void{
        static::updating(fn()=>throw \Illuminate\Validation\ValidationException::withMessages(['purchase_cost_adjustment'=>'Purchase-cost components are immutable.']));
        static::deleting(fn()=>throw \Illuminate\Validation\ValidationException::withMessages(['purchase_cost_adjustment'=>'Purchase-cost components are never deleted.']));
    }
}
