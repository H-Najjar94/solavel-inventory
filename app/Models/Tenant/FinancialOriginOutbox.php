<?php
namespace App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
final class FinancialOriginOutbox extends Model {
 use BelongsToOrganization;protected $table='stock_financial_origin_outbox';protected $guarded=['id'];protected $casts=['payload'=>'array','response'=>'array','lease_expires_at'=>'datetime','next_attempt_at'=>'datetime'];
 protected static function booted():void {static::updating(function(self $row){foreach(['event_uuid','operation_uuid','event_type','source_document_type','source_document_id','source_journal_id','physical_document_type','physical_document_id','external_source_key','payload_hash','payload']as$field)abort_if($row->isDirty($field),409,'Immutable financial origin event evidence.');});}
}
