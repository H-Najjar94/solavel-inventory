<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FinancialOriginCommand extends Model {use BelongsToOrganization; protected $table='stock_financial_origin_commands'; protected $guarded=['id']; protected $casts=['payload'=>'array','response'=>'array','native_line_links'=>'array'];}
