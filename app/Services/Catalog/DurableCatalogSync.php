<?php
namespace App\Services\Catalog;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\FinanceConnectionClient;
use Illuminate\Support\Facades\{DB,Schema,Auth,Log};
use Ramsey\Uuid\Uuid;
/** Durable source identity and bounded delivery. No network requests run inside save transactions. */
final class DurableCatalogSync {
 private array $recording=[];
 public const TABLE='integration_catalog_sync_states';
 public function record(string $type,int $sourceId,int $org,?int $actor=null):void {
  if(!Schema::connection('tenant')->hasTable(self::TABLE)){Log::warning('catalog_sync_schema_pending',['organization_id'=>$org,'entity_type'=>$type,'source_id'=>$sourceId]);return;}
  $maps=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->where('status','verified')->get();
  if($maps->count()!==1)return;$map=$maps->first();$fields=app(CatalogSourceSnapshot::class)->capture($type,$sourceId,$org);if(!$fields)return;
  $actor=$actor?:((int)Auth::id()?:null);$hash=CatalogSourceSnapshot::revision($fields);$raw=CatalogSourceSnapshot::encode($fields);
  $key=$map->mapping_uuid.'|'.$type.'|'.$sourceId;if(isset($this->recording[$key]))return;$this->recording[$key]=true;
  try{DB::connection('tenant')->transaction(function()use($map,$fields,$type,$sourceId,$org,$actor,$hash,$raw){
   $db=DB::connection('tenant');$identity=['organization_mapping_uuid'=>$map->mapping_uuid,'entity_type'=>$type,'source_id'=>$sourceId];
   $uuid=Uuid::uuid5(Uuid::NAMESPACE_URL,'stock-catalog|'.$map->mapping_uuid.'|'.$type.'|'.$sourceId)->toString();
   $db->table(self::TABLE)->insertOrIgnore($identity+['organization_id'=>$org,'source_uuid'=>$uuid,'actor_id'=>$actor,'source_revision'=>$hash,'source_snapshot'=>$raw,'state'=>'pending','created_at'=>now(),'updated_at'=>now()]);
   $row=$db->table(self::TABLE)->where($identity)->lockForUpdate()->first();
   if($row->source_revision!==$hash||($actor&&((int)$row->actor_id!==$actor)&&$row->state!=='delivered'&&(!$row->lease_expires_at||!\Carbon\Carbon::parse($row->lease_expires_at)->isFuture())))$db->table(self::TABLE)->where('id',$row->id)->update([
    'actor_id'=>$actor?:$row->actor_id,'source_revision'=>$hash,'source_snapshot'=>$raw,'state_version'=>$row->state_version+1,'state'=>'pending','attempts'=>0,'last_error'=>null,'next_attempt_at'=>null,'lease_uuid'=>null,'lease_expires_at'=>null,'updated_at'=>now()]);
   foreach(CatalogSourceSnapshot::dependencies($fields)as[$dependency,$id])$this->record($dependency,$id,$org,$actor);
  });}finally{unset($this->recording[$key]);}
 }
 /** Explicit authorized recovery adopts the current actor, never guesses an owner. */
 public function retry(string $type,int $id,int $org,int $actor):void {
  $this->record($type,$id,$org,$actor);
  $db=DB::connection('tenant');$db->transaction(function()use($db,$type,$id,$org,$actor){
   $row=$db->table(self::TABLE)->where('organization_id',$org)->where('entity_type',$type)->where('source_id',$id)->lockForUpdate()->first();
   if(!$row||$row->state==='delivered')return;
   // Never race an unexpired accepted-or-in-flight delivery. Reconciliation retries its durable identity.
   if($row->lease_expires_at&&\Carbon\Carbon::parse($row->lease_expires_at)->isFuture())return;
   $db->table(self::TABLE)->where('id',$row->id)->update(['actor_id'=>$actor,'state'=>'pending','attempts'=>0,'last_error'=>null,'next_attempt_at'=>null,'lease_uuid'=>null,'lease_expires_at'=>null,'updated_at'=>now()]);
  });
 }
 public function process(object $map,int $limit=2):int {
  if(!Schema::connection('tenant')->hasTable(self::TABLE)||$map->activation_state!=='active'||$map->status!=='verified')return 0;
  $db=DB::connection('tenant');if($db->transactionLevel()!==0)throw new \LogicException('Catalog delivery requires transaction zero');$count=0;
  for($i=0;$i<min(20,max(1,$limit));$i++){
   $row=$db->transaction(function()use($db,$map){
    $row=$db->table(self::TABLE)->where('organization_mapping_uuid',$map->mapping_uuid)->where('organization_id',$map->solastock_organization_id)
     ->whereIn('state',['pending','retrying','unknown_outcome','delivering'])->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now()))
     ->where(fn($q)=>$q->whereNull('lease_expires_at')->orWhere('lease_expires_at','<=',now()))
     ->orderByRaw("CASE entity_type WHEN 'unit' THEN 0 WHEN 'category' THEN 1 ELSE 2 END")->orderBy('id')->lockForUpdate()->first();
    if(!$row)return null;$lease=(string)Uuid::uuid4();$db->table(self::TABLE)->where('id',$row->id)->update(['lease_uuid'=>$lease,'lease_expires_at'=>now()->addSeconds(90),'state'=>'delivering','attempts'=>$row->attempts+1,'updated_at'=>now()]);$row->lease_uuid=$lease;return $row;
   });if(!$row)break;
   try {
    if(!$row->actor_id)throw new \RuntimeException('catalog_source_actor_required',403);
    $fields=json_decode($row->source_snapshot,true,512,JSON_THROW_ON_ERROR);
    foreach(CatalogSourceSnapshot::dependencies($fields)as[$type,$id]){
     $dependency=$db->table(self::TABLE)->where('organization_mapping_uuid',$map->mapping_uuid)->where('entity_type',$type)->where('source_id',$id)->first();
     if(!$dependency||$dependency->state!=='delivered')throw new \RuntimeException('catalog_dependency_pending',409);
    }
    $reply=app(FinanceConnectionClient::class)->projectCatalog(['client_id'=>(int)$map->central_client_id,'organization_id'=>(int)$map->central_organization_id,
     'finance_organization_id'=>(int)$map->finance_organization_id,'actor_id'=>(int)$row->actor_id,'organization_mapping_uuid'=>$map->mapping_uuid,'source_uuid'=>$row->source_uuid,
     'entity_type'=>$row->entity_type,'source_id'=>(int)$row->source_id,'source_revision'=>$row->source_revision,'state_version'=>(int)$row->state_version]);
    if(($reply['organization_mapping_uuid']??null)!==$map->mapping_uuid||(int)($reply['state_version']??0)!==(int)$row->state_version||($reply['source_uuid']??null)!==$row->source_uuid||($reply['source_revision']??null)!==$row->source_revision||(int)($reply['target_id']??0)<1)throw new \RuntimeException('catalog_projection_ack_invalid',409);
    $changes=['state'=>'delivered','target_id'=>(int)$reply['target_id'],'delivery_ack'=>json_encode($reply,JSON_THROW_ON_ERROR),'last_error'=>null,'next_attempt_at'=>null];
   }catch(\Throwable $e){
    $code=(string)$e->getMessage();$safe=in_array($code,['catalog_source_actor_required','catalog_dependency_pending','catalog_projection_ack_invalid','catalog_source_changed','catalog_field_conflict','catalog_shared_reference_change','catalog_identity_conflict','catalog_source_not_authorized','finance_connection_transport_unknown_retry_same_key'],true)?$code:'catalog_projection_delivery_failed';
    $terminal=in_array($safe,['catalog_source_actor_required','catalog_source_changed','catalog_field_conflict','catalog_shared_reference_change','catalog_identity_conflict','catalog_source_not_authorized'],true)||$row->attempts>=11;
    $changes=['state'=>$terminal?'intervention_required':($safe==='finance_connection_transport_unknown_retry_same_key'?'unknown_outcome':'retrying'),'last_error'=>$safe,'next_attempt_at'=>$terminal?null:now()->addSeconds(min(900,5*(2**min(8,$row->attempts))))];
    Log::warning('catalog_sync_delivery_pending',['organization_id'=>$map->solastock_organization_id,'mapping_uuid'=>$map->mapping_uuid,'source_uuid'=>$row->source_uuid,'entity_type'=>$row->entity_type,'source_id'=>$row->source_id,'state_version'=>$row->state_version,'attempt'=>$row->attempts+1,'reason'=>$safe]);
   }
   $updated=$db->table(self::TABLE)->where('id',$row->id)->where('lease_uuid',$row->lease_uuid)->where('state_version',$row->state_version)->update($changes+['lease_uuid'=>null,'lease_expires_at'=>null,'updated_at'=>now()]);
   if($updated===1&&$changes['state']==='delivered')app(\App\Services\Integration\CatalogDocumentResumption::class)->resume($map,$row->entity_type,(int)$row->source_id);$count++;
  }return $count;
 }
}
