<?php
namespace App\Services\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Shared deterministic local transaction; transport and authorization happen before it. */
final class PartySyncMaterializer {
 public function apply(object $mapping,string $sourceApp,string $type,int $sourceId,callable $create,callable $update,callable $createMapping,?string $expectedRevision=null):array {
  $ledger=app(PartySyncLedger::class);$db=DB::connection('tenant');
  return $db->transaction(function()use($mapping,$sourceApp,$type,$sourceId,$create,$update,$createMapping,$ledger,$db,$expectedRevision){
   $authorized=$mapping;
   $mapping=$db->table('integration_organization_mappings')->where('id',$authorized->id)->lockForUpdate()->first();
   // Close the authorization-to-mutation race with pause/disconnect and identity changes.
   $valid=$mapping && $mapping->status==='verified' && ($mapping->activation_state??null)==='active'
    && $mapping->tenant_database_identity===$db->getDatabaseName() && $mapping->contract_version==='solastock-journal.v2';
   foreach(['mapping_uuid','central_client_id','central_organization_id','finance_organization_id','solastock_organization_id','tenant_database_identity','contract_version'] as $identityKey)
    $valid=$valid && (string)($mapping->$identityKey??'')===(string)($authorized->$identityKey??'');
   if(!$valid)return $this->result('held',$sourceId,null,null,'party_connection_pending');
   $setting=$db->table('integration_settings')->where('organization_id',$mapping->solastock_organization_id)
    ->where('integration','solabooks')->where('solabooks_organization_id',$mapping->finance_organization_id)->lockForUpdate()->first();
   if(!$setting || $setting->mode!=='active')return $this->result('held',$sourceId,null,null,'party_connection_pending');
   $sourceTable=($sourceApp==='stock'?'inventory_':'').($type==='supplier'?'suppliers':'customers');
   $sourceOrg=$sourceApp==='stock'?$mapping->solastock_organization_id:$mapping->finance_organization_id;
   $source=$db->table($sourceTable)->where('organization_id',$sourceOrg)->where('id',$sourceId)->lockForUpdate()->first();
   $sourceFields=$ledger->fields($mapping,$sourceApp,$type,$sourceId);
   if($expectedRevision!==null && (!$sourceFields || !hash_equals($expectedRevision,$ledger->revision($sourceFields))))return $this->result('pending',$sourceId,null,null,'party_source_changed');
   $state=$ledger->record($mapping,$sourceApp,$type,$sourceId);
   if(!$state||!$sourceFields||$sourceFields['name']==='')return $this->result('intervention',$sourceId,null,null,'party_source_unavailable');
   $state=$db->table('integration_party_sync_states')->where('id',$state->id)->lockForUpdate()->first();
   $sourceColumn=$sourceApp==='finance'?'solabooks_record_id':'solastock_record_id';
   $targetColumn=$sourceApp==='finance'?'solastock_record_id':'solabooks_record_id';
   $masters=$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where($sourceColumn,(string)$sourceId)->lockForUpdate()->get();
   if($masters->count()>1)return $this->finish($state,$this->result('intervention',$sourceId,null,null,'party_identity_review_required'));
   $master=$masters->first();
   if($master&&($master->status!=='verified'||$master->conflict_code!==null||$master->error_state!==null
    ||(int)$master->central_client_id!==(int)$mapping->central_client_id||(int)$master->central_organization_id!==(int)$mapping->central_organization_id
    ||(int)$master->finance_organization_id!==(int)$mapping->finance_organization_id||(int)$master->solastock_organization_id!==(int)$mapping->solastock_organization_id))
    return $this->finish($state,$this->result('intervention',$sourceId,null,null,'party_identity_review_required'));
   if($master && $state->source_app !== $sourceApp) {
    $db->table('integration_master_data_mappings')->where('id',$master->id)->update([$sourceApp==='finance'?'solabooks_archived':'solastock_archived'=>!$sourceFields['active'],'updated_at'=>now()]);
    return $this->result($sourceFields['active']?'synced':'held',$sourceId,(int)$master->$targetColumn,$master->mapping_uuid,$sourceFields['active']?null:'party_source_unavailable',json_decode($state->field_overrides??'{}',true));
   }
   if(!$sourceFields['active']){
    if($master)$db->table('integration_master_data_mappings')->where('id',$master->id)->update([$sourceApp==='finance'?'solabooks_archived':'solastock_archived'=>true,'updated_at'=>now()]);
    return $this->finish($state,$this->result('held',$sourceId,$master?(int)$master->$targetColumn:null,$master?->mapping_uuid,'party_source_unavailable'));
   }
   $targetApp=$sourceApp==='finance'?'stock':'finance';$targetTable=($targetApp==='stock'?'inventory_':'').($type==='supplier'?'suppliers':'customers');
   $targetOrg=$targetApp==='stock'?$mapping->solastock_organization_id:$mapping->finance_organization_id;
   $targetId=$master?(int)$master->$targetColumn:null;$new=false;
   if($master && !$targetId)return $this->finish($state,$this->result('intervention',$sourceId,null,$master->mapping_uuid,'party_identity_review_required'));
   // Archived targets are an explicit intervention, never implicitly reactivated.
   if($master && ($sourceApp==='finance' ? $master->solastock_archived : $master->solabooks_archived))
    return $this->finish($state,$this->result('intervention',$sourceId,$targetId,$master->mapping_uuid,'party_identity_review_required'));
   if(!$targetId){
    $existing=$db->table($targetTable)->where('organization_id',$targetOrg)->where(function($q)use($sourceFields,$targetApp,$type,$sourceId){
     $q->where('name',$sourceFields['name']);
     if($targetApp==='stock')$q->orWhere('code',($type==='supplier'?'FIN-S-':'FIN-C-').$sourceId);
    })->whereNull('deleted_at')->exists();
    if($existing)return $this->finish($state,$this->result('intervention',$sourceId,null,null,'party_identity_review_required'));
    if($targetApp==='finance' && $this->approvalEnabled($targetOrg,$type,'create'))
     return $this->finish($state,$this->result('held',$sourceId,null,null,'party_approval_required'));
    $targetId=$create($sourceFields,$sourceId);$new=true;
    $master=$createMapping(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$mapping->mapping_uuid,'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,'solastock_organization_id'=>$mapping->solastock_organization_id,'entity_type'=>$type,'solastock_record_id'=>(string)($sourceApp==='finance'?$targetId:$sourceId),'solabooks_record_id'=>(string)($sourceApp==='finance'?$sourceId:$targetId),'status'=>'verified','last_verified_at'=>now(),'discovery_method'=>'continuous_party_identity','solastock_archived'=>false,'solabooks_archived'=>false]);
   }
   $target=$db->table($targetTable)->where('organization_id',$targetOrg)->where('id',$targetId)->lockForUpdate()->first();
   if(!$target||!empty($target->deleted_at)||!($target->is_active??true))return $this->finish($state,$this->result('intervention',$sourceId,$targetId,$master?->mapping_uuid,'party_source_unavailable'));
   // The first source that creates a counterpart owns shared fields. An opposite model
   // event retains the verified identity and records local overrides without a sync loop.
   $owner=$db->table('integration_party_sync_states')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where('source_app',$targetApp)->where('source_id',$targetId)->where('target_id',$sourceId)->first();
   if($owner&&$owner->id<$state->id)return $this->finish($state,$this->result('synced',$sourceId,$targetId,$master->mapping_uuid,null),['source_fields'=>$sourceFields]);
   $current=$ledger->fields($mapping,$targetApp,$type,$targetId);$baseline=json_decode($state->field_baselines??'{}',true);$overrides=json_decode($state->field_overrides??'{}',true);
   $flat=$this->flat($sourceFields);$targetFlat=$this->flat($current);$desired=$targetFlat;
   foreach($flat as$key=>$value){
    if(!$new&&(!isset($baseline['target'])||array_key_exists($key,$baseline['target'])&&$baseline['target'][$key]!==$targetFlat[$key]))$overrides[$key]=$targetFlat[$key]!==($baseline['source'][$key]??$value);
    if(!($overrides[$key]??false))$desired[$key]=$value;
   }
   if($desired!==$targetFlat){
    if($targetApp==='finance' && $this->approvalEnabled($targetOrg,$type,'update'))
     return $this->finish($state,$this->result('held',$sourceId,$targetId,$master->mapping_uuid,'party_approval_required'));
    $update($targetId,$this->unflat($desired));
   }
   $after=$ledger->fields($mapping,$targetApp,$type,$targetId);
   $db->table('integration_master_data_mappings')->where('id',$master->id)->update([$sourceApp==='finance'?'solabooks_archived':'solastock_archived'=>false,'last_verified_at'=>now(),'updated_at'=>now()]);
   return $this->finish($state,$this->result('synced',$sourceId,$targetId,$master->mapping_uuid,null,$overrides),['source_fields'=>$sourceFields,'field_baselines'=>['source'=>$flat,'target'=>$this->flat($after)],'field_overrides'=>$overrides]);
  });
 }
 private function approvalEnabled(int $organizationId,string $type,string $operation):bool {
  return (bool) DB::connection('tenant')->table('document_approval_configs')
   ->where('organization_id',$organizationId)->where('document_type','master_data.'.$type.'.'.$operation)
   ->lockForUpdate()->value('enabled');
 }
 public function flat(array $fields):array { $r=['name'=>$fields['name']];foreach(['phone','email','contact_person_name','contact_person_title','address_line1','address_line2','city','country']as$key)$r['contact.'.$key]=$fields['contact'][$key]??null;return$r; }
 public function unflat(array $fields):array {$r=['name'=>$fields['name'],'contact'=>[]];foreach($fields as$key=>$v)if(str_starts_with($key,'contact.')&&$v!==null&&$v!=='')$r['contact'][substr($key,8)]=$v;return$r;}
 public function result(string $status,int $sourceId,?int $targetId,?string $mapping,?string $reason,array $overrides=[]):array {
  return ['status'=>$status,'source_id'=>$sourceId,'target_id'=>$targetId,'mapping_uuid'=>$mapping,'reason'=>$reason,'retryable'=>in_array($status,['pending','held'],true),'field_overrides'=>$overrides];
 }
 private function finish(object $state,array $result,array $extra=[]):array {
  if($state->attempts+1>=40 && in_array($result['status'],['held','pending'],true)){
   $result['status']='intervention';$result['retryable']=false;$result['reason']='party_retry_exhausted';
  }
  $values=['status'=>$result['status'],'target_id'=>$result['target_id'],'mapping_uuid'=>$result['mapping_uuid'],'last_error'=>$result['reason'],'attempts'=>$state->attempts+1,'next_attempt_at'=>$result['retryable']?now()->addMinute():null,'updated_at'=>now()];
  foreach($extra as$key=>$value)$values[$key]=json_encode($value);
  DB::connection('tenant')->table('integration_party_sync_states')->where('id',$state->id)->update($values);
  // After commit and deduplicated; never fails or changes the sync itself.
  app(SyncIncidentNotificationPublisher::class)->changed('party',(int)$state->id,(string)$state->organization_mapping_uuid);
  return$result;
 }
}

