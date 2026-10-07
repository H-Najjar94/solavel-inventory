<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class SalesDocumentOutbox extends Model {
 use BelongsToOrganization;protected $table='sales_document_outbox';protected $guarded=['id'];
 protected $casts=['payload'=>'array','response'=>'array','next_attempt_at'=>'datetime','lease_expires_at'=>'datetime'];
}
