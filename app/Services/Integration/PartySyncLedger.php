<?php
namespace App\Services\Integration;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Eloquent\Model;
/** Local durable intent only. No transport or target writes run in model hooks. */
final class PartySyncLedger {
 public static int $muted=0;
 public static function withoutTracking(callable $callback):mixed {self::$muted++;try{return $callback();}finally{self::$muted--;}}
 public function changed(Model $model,string $app,string $type):void {
  if(self::$muted || !Schema::connection('tenant')->hasTable('integration_party_sync_states') || !Schema::connection('tenant')->hasTable('integration_organization_mappings'))return;
  $org=(int)$model->getAttribute('organization_id');if($org<1||!$model->getKey())return;
  $column=$app==='finance'?'finance_organization_id':'solastock_organization_id';
  foreach(DB::connection('tenant')->table('integration_organization_mappings')->where($column,$org)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->whereIn('status',['verified','verified_hold'])->get()as$mapping){
   if(!$this->fields($mapping,$app,$type,(int)$model->getKey())){
    // A native hard-delete event still carries its original scoped source identity.
    $fields=['name'=>trim((string)$model->getRawOriginal('name')),'contact'=>[],'active'=>false];
    DB::connection('tenant')->table('integration_party_sync_states')->insertOrIgnore(['organization_mapping_uuid'=>$mapping->mapping_uuid,'entity_type'=>$type,'source_app'=>$app,'source_id'=>(int)$model->getKey(),'organization_id'=>$mapping->finance_organization_id,'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'source_revision'=>$this->revision($fields),'source_fields'=>json_encode($fields),'status'=>'pending','state_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
   }
   $this->record($mapping,$app,$type,(int)$model->getKey());
  }
 }
 public function fields(object $mapping,string $app,string $type,int $id):?array {
  if(!in_array($app,['finance','stock'],true)||!in_array($type,['supplier','customer'],true))throw new \InvalidArgumentException('party_identity_invalid');
  $table=($app==='stock'?'inventory_':'').($type==='supplier'?'suppliers':'customers');
  $org=$app==='finance'?$mapping->finance_organization_id:$mapping->solastock_organization_id;
  $source=DB::connection('tenant')->table($table)->where('organization_id',$org)->where('id',$id)->first();
  if(!$source){
   $prior=DB::connection('tenant')->table('integration_party_sync_states')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_app',$app)->where('entity_type',$type)->where('source_id',$id)->first();
   if(!$prior)return null;
   $fields=json_decode($prior->source_fields,true);$fields['active']=false;return $fields;
  }
  $contact=$app==='stock'?json_decode((string)($source->contact??'{}'),true):array_intersect_key((array)$source,array_flip(['phone','email','contact_person_name','contact_person_title','address_line1','address_line2','city','country']));
  $contact=is_array($contact)?$contact:[];$contact=array_intersect_key($contact,array_flip(['phone','email','contact_person_name','contact_person_title','address_line1','address_line2','city','country']));$contact=array_filter(array_map(static fn($v)=>is_scalar($v)?trim((string)$v):null,$contact),static fn($v)=>$v!==null&&$v!=='');ksort($contact);
  return ['name'=>trim((string)$source->name),'contact'=>$contact,'active'=>(bool)($source->is_active??true)&&empty($source->deleted_at)];
 }
 public function revision(array $fields):string {return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public function record(object $mapping,string $app,string $type,int $id):?object {
  $fields=$this->fields($mapping,$app,$type,$id);if(!$fields)return null;
  $db=DB::connection('tenant');$identity=['organization_mapping_uuid'=>$mapping->mapping_uuid,'entity_type'=>$type,'source_app'=>$app,'source_id'=>$id];$hash=$this->revision($fields);
  return $db->transaction(function()use($db,$mapping,$fields,$identity,$hash,$app,$type,$id){
   // A generated counterpart must not start an opposite synchronization loop.
   $opposite=$db->table('integration_party_sync_states')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where('source_app',$app==='finance'?'stock':'finance')->where('target_id',$id)->lockForUpdate()->first();
   if($opposite){
    $baselines=json_decode($opposite->field_baselines??'{}',true);$overrides=json_decode($opposite->field_overrides??'{}',true);
    $flat=app(PartySyncMaterializer::class)->flat($fields);foreach($flat as$field=>$value)if(array_key_exists($field,$baselines['target']??[])&&$baselines['target'][$field]!==$value)$overrides[$field]=true;
    $sourceColumn=$app==='finance'?'solabooks_record_id':'solastock_record_id';
    $archiveColumn=$app==='finance'?'solabooks_archived':'solastock_archived';
    $archiveChanged=$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where($sourceColumn,(string)$id)->where($archiveColumn,!$fields['active'])->count()===0;
    if($archiveChanged)$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where($sourceColumn,(string)$id)->update([$archiveColumn=>!$fields['active'],'updated_at'=>now()]);
    if($archiveChanged || $overrides!==json_decode($opposite->field_overrides??'{}',true))
     $db->table('integration_party_sync_states')->where('id',$opposite->id)->update(['field_overrides'=>json_encode($overrides),'state_version'=>$opposite->state_version+1,'status'=>'pending','next_attempt_at'=>null,'updated_at'=>now()]);
    return $opposite;
   }
   $db->table('integration_party_sync_states')->insertOrIgnore($identity+['organization_id'=>$mapping->finance_organization_id,'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'source_revision'=>$hash,'source_fields'=>json_encode($fields),'status'=>'pending','state_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
   $row=$db->table('integration_party_sync_states')->where($identity)->lockForUpdate()->first();
   if($row->source_revision!==$hash)$db->table('integration_party_sync_states')->where('id',$row->id)->update(['source_revision'=>$hash,'source_fields'=>json_encode($fields),'status'=>'pending','state_version'=>$row->state_version+1,'attempts'=>0,'next_attempt_at'=>null,'last_error'=>null,'updated_at'=>now()]);
   return $db->table('integration_party_sync_states')->where('id',$row->id)->first();
  });
 }
 public function reconcile(object $mapping):void {
  foreach(['finance','stock']as$app)foreach(['supplier','customer']as$type){
   $table=($app==='stock'?'inventory_':'').($type==='supplier'?'suppliers':'customers');$org=$app==='finance'?$mapping->finance_organization_id:$mapping->solastock_organization_id;
   $activation=Schema::connection('tenant')->hasTable('integration_connection_wizard_runs')
    ? DB::connection('tenant')->table('integration_connection_wizard_runs')->where('organization_mapping_uuid',$mapping->mapping_uuid)->whereNotNull('activated_at')->min('activated_at') : null;
   $activation=$activation??($mapping->verified_at??null);
   $sourceColumn=$app==='finance'?'solabooks_record_id':'solastock_record_id';
   $cursorKey='party-sync-cursor:'.hash('sha256',DB::connection('tenant')->getDatabaseName().'|'.$mapping->mapping_uuid.'|'.$app.'|'.$type);
   $cursor=(int)\Illuminate\Support\Facades\Cache::store('file')->get($cursorKey,0);
   $rows=DB::connection('tenant')->table($table)->where('organization_id',$org)->where($table.'.id','>',$cursor)->where(function($query)use($mapping,$app,$type,$table,$sourceColumn,$activation){
    if($activation)$query->where($table.'.created_at','>=',$activation);
    $query->orWhereExists(function($q)use($mapping,$type,$table,$sourceColumn){$q->selectRaw('1')->from('integration_master_data_mappings as pm')->where('pm.organization_mapping_uuid',$mapping->mapping_uuid)->where('pm.entity_type',$type)->where('pm.status','verified')->whereColumn('pm.'.$sourceColumn,$table.'.id');});
    $query->orWhereExists(function($q)use($mapping,$app,$type,$table){$q->selectRaw('1')->from('integration_party_sync_states as ps')->where('ps.organization_mapping_uuid',$mapping->mapping_uuid)->where('ps.entity_type',$type)->where('ps.source_app',$app)->whereColumn('ps.source_id',$table.'.id');});
   })->orderBy('id')->limit(100)->get();
   foreach($rows as$row)$this->record($mapping,$app,$type,(int)$row->id);
   \Illuminate\Support\Facades\Cache::store('file')->forever($cursorKey,$rows->count()===100?(int)$rows->last()->id:0);
  }
 }
}

