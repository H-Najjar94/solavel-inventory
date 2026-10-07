<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FulfillmentCommand extends Model {use BelongsToOrganization;protected $table='sales_fulfillment_commands';protected $guarded=['id'];protected $casts=['payload'=>'array'];}
