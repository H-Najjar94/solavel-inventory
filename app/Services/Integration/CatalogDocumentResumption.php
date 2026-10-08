<?php
namespace App\Services\Integration;
use Illuminate\Support\Facades\{DB,Schema};
/** Resume only exact current wanted document dependencies after a verified native catalog ACK. */
final class CatalogDocumentResumption {
 public function resume(object $map,string $type,int $id):void {
  if(!in_array($type,['item','unit'],true)||$id<1)return;$db=DB::connection('tenant');abort_unless($db->transactionLevel()===0,409);
  if($map->status!=='verified'||$map->activation_state!=='active'||$map->tenant_database_identity!==$db->getDatabaseName())return;
  $matches=$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$map->mapping_uuid)->where('solastock_organization_id',$map->solastock_organization_id)->where('finance_organization_id',$map->finance_organization_id)->where('entity_type',$type)->where('solastock_record_id',(string)$id)->where('status','verified')->whereNull('conflict_code')->whereNull('error_state')->get();if($matches->count()!==1)return;
  foreach([false,true]as$sales){$table=$sales?'sales_document_outbox':'purchasing_document_outbox';if(!Schema::connection('tenant')->hasTable($table))continue;$source=$sales?'shipment':'receipt';
   $rows=$db->table($table)->where('organization_id',$map->solastock_organization_id)->where('status','intervention')->where('payload->identity->organization_mapping_uuid',$map->mapping_uuid)->whereRaw("JSON_CONTAINS(JSON_EXTRACT(payload, '$.".$source.".lines[*].".$type."_id'), ?)",[json_encode($id)])->orderBy('id')->limit(20)->get();
   foreach($rows as$row){$payload=json_decode($row->payload,true);$answer=json_decode($sales?($row->response??'{}'):($row->receiver_response??'{}'),true);
    if(!is_array($payload)||!is_array($answer)||!hash_equals($row->payload_hash,hash('sha256',SolaStockJournalContract::canonicalJson($payload))))continue;
    $db->transaction(function()use($db,$map,$type,$id,$sales,$source,$table,$row,$payload,$answer){
     $native=$db->table($sales?'shipments':'goods_receipts')->where('organization_id',$map->solastock_organization_id)->where('id',$payload[$source]['id']??0)->lockForUpdate()->first();
     if(!$native||!DocumentCatalogRecoveryPolicy::matches($type,$id,$payload,$answer,$native))return;
     $db->table($table)->where('id',$row->id)->where('organization_id',$map->solastock_organization_id)->where('status','intervention')->where('updated_at',$row->updated_at)->where('payload_hash',$row->payload_hash)->update(['status'=>'retry','next_attempt_at'=>now(),'updated_at'=>now()]);
    });
   }
  }
 }
}
