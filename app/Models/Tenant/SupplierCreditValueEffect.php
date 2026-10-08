<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\{BelongsToOrganization,Immutable};
use Illuminate\Database\Eloquent\Model;
final class SupplierCreditValueEffect extends Model {
 use BelongsToOrganization,Immutable;
 protected $connection='tenant';protected $table='supplier_credit_value_effects';protected $guarded=['id'];
}
