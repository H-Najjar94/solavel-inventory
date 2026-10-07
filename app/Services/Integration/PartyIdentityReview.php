<?php
namespace App\Services\Integration;
use App\Models\Tenant\{IntegrationMasterDataMapping,InventoryAuditLog};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class PartyIdentityReview {
 public function choices(object $mapping,array $facts):array {
  $type=$this->type($facts);
  $rows=DB::connection('tenant')->table($type==='customer'?'inventory_customers':'inventory_suppliers')->where('organization_id',$mapping->solastock_organization_id)->where('is_active',true)->whereNull('deleted_at')->orderBy('name')->limit(100)->get();
  return ['source_id'=>(int)$facts['source_id'],'source_revision'=>$facts['source_revision'],'candidates'=>$rows->map(fn($row)=>['id'=>(int)$row->id,'name'=>$row->name,'code'=>$row->code,'selection_fingerprint'=>$this->fingerprint($mapping,$facts,$row)])->all()];
 }
 private function fingerprint(object $mapping,array $facts,object $target):string {
  $type=$this->type($facts);
  $secret=(string)config('finance_workspace.secret');abort_unless(strlen($secret)>=32,503);
  $fields=app(PartySyncLedger::class)->fields($mapping,'stock',$type,(int)$target->id);
  $identity=[$mapping->mapping_uuid,$facts['source_id'],$facts['source_revision'],$target->id,$target->code,$fields];
  if($type==='customer')array_unshift($identity,'customer');
  return hash_hmac('sha256',json_encode($identity,JSON_THROW_ON_ERROR),$secret);
 }
 public function resolve(object $mapping,array $facts,int $targetId,string $fingerprint):array {
  $type=$this->type($facts);
  DB::connection('tenant')->transaction(function()use($mapping,$facts,$targetId,$fingerprint,$type){
   $db=DB::connection('tenant');$locked=$db->table('integration_organization_mappings')->where('id',$mapping->id)->lockForUpdate()->first();
   foreach(['mapping_uuid','central_client_id','central_organization_id','finance_organization_id','solastock_organization_id','tenant_database_identity']as$key)abort_unless($locked && (string)$locked->$key===(string)$mapping->$key,403);
   abort_unless($locked->status==='verified' && $locked->activation_state==='active' && $locked->tenant_database_identity===$db->getDatabaseName(),409);
   $setting=$db->table('integration_settings')->where('organization_id',$mapping->solastock_organization_id)->where('integration','solabooks')->where('solabooks_organization_id',$mapping->finance_organization_id)->lockForUpdate()->first();abort_unless($setting && $setting->mode==='active',409);
   $source=$db->table($type==='customer'?'customers':'suppliers')->where('organization_id',$mapping->finance_organization_id)->where('id',$facts['source_id'])->lockForUpdate()->first();abort_unless($source && $source->is_active && empty($source->deleted_at),409);
   $ledger=app(PartySyncLedger::class);abort_unless(hash_equals($facts['source_revision'],$ledger->revision($ledger->fields($mapping,'finance',$type,(int)$facts['source_id']))),409);
   $target=$db->table($type==='customer'?'inventory_customers':'inventory_suppliers')->where('organization_id',$mapping->solastock_organization_id)->where('id',$targetId)->where('is_active',true)->whereNull('deleted_at')->lockForUpdate()->first();abort_unless($target,404);
   abort_unless(hash_equals($this->fingerprint($mapping,$facts,$target),$fingerprint),409);
   $pairs=$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where(fn($q)=>$q->where('solabooks_record_id',(string)$facts['source_id'])->orWhere('solastock_record_id',(string)$targetId))->lockForUpdate()->get();
   if($pairs->isNotEmpty()){
    abort_unless($pairs->count()===1 && (string)$pairs->first()->solabooks_record_id===(string)$facts['source_id'] && (string)$pairs->first()->solastock_record_id===(string)$targetId && $pairs->first()->status==='verified' && !$pairs->first()->conflict_code && !$pairs->first()->error_state && !$pairs->first()->solastock_archived && !$pairs->first()->solabooks_archived,409);
   }else{
    IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$mapping->mapping_uuid,'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,'solastock_organization_id'=>$mapping->solastock_organization_id,'entity_type'=>$type,'solabooks_record_id'=>(string)$facts['source_id'],'solastock_record_id'=>(string)$targetId,'status'=>'verified','last_verified_at'=>now(),'discovery_method'=>'explicit_party_identity_review','created_by_user_id'=>$facts['native_actor_id']]);
    InventoryAuditLog::create(['organization_id'=>$mapping->solastock_organization_id,'actor_user_id'=>$facts['native_actor_id'],'action'=>'integration.party.identity_resolved','entity_type'=>$type,'entity_id'=>$targetId,'document_ref'=>$mapping->mapping_uuid,'after'=>['finance_'.$type.'_id'=>(int)$facts['source_id'],'stock_'.$type.'_id'=>$targetId,'source_revision'=>$facts['source_revision']]]);
   }
  });
  return app(ContinuousPartySync::class)->materialize($mapping,'finance',$type,(int)$facts['source_id'],$facts['source_revision']);
 }
 private function type(array $facts):string {
  $type=(string)($facts['entity_type']??'supplier');
  abort_unless(in_array($type,['supplier','customer'],true),422);
  return $type;
 }

}
