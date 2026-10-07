<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FulfillmentDemandCommand extends Model {
 use BelongsToOrganization;
 protected $table='sales_fulfillment_demand_commands';
 protected $guarded=['id'];
 protected $casts=['payload'=>'array','reversal_snapshot'=>'array'];
}
