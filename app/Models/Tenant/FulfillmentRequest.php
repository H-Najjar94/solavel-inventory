<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FulfillmentRequest extends Model {
 use BelongsToOrganization;
 protected $table='sales_fulfillment_requests';protected $guarded=['id'];
 protected $casts=['source_payload'=>'array','approved_at'=>'datetime','invoice_date'=>'date:Y-m-d','requested_ship_date'=>'date:Y-m-d','exchange_rate_date'=>'date:Y-m-d'];
 public function lines(){return $this->hasMany(FulfillmentRequestLine::class,'fulfillment_request_id');}
}
