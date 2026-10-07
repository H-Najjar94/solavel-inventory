<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FulfillmentRequestLine extends Model {
 use BelongsToOrganization;protected $table='sales_fulfillment_request_lines';protected $guarded=['id'];
 protected $casts=['requested_qty'=>'decimal:4','fulfilled_qty'=>'decimal:4','cancelled_qty'=>'decimal:4','unit_price'=>'decimal:4','discount_rate'=>'decimal:4'];
}
