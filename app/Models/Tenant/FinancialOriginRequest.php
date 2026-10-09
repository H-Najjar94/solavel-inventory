<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FinancialOriginRequest extends Model {
 use BelongsToOrganization;
 protected $table='stock_financial_origin_requests'; protected $guarded=['id'];
 protected $casts=['source_payload'=>'array','approved_at'=>'datetime'];
 public function lines(){return $this->hasMany(FinancialOriginLine::class,'request_id');}
}
