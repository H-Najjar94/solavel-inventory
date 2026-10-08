<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FinancialOriginLine extends Model {use BelongsToOrganization; protected $table='stock_financial_origin_lines'; protected $guarded=['id'];}
