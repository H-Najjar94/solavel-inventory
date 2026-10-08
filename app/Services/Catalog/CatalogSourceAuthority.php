<?php
namespace App\Services\Catalog;
use App\Services\Access\InventoryPermissionService;
use Illuminate\Support\Facades\DB;
/** Closed read-only authority for an actual Stock-owned source revision. */
final class CatalogSourceAuthority {
 public function dispatch(string $action,array $data,object $mapping,object $actor):array {
  if($action==='catalog.source-authorize')return $this->authorize($data,$mapping,$actor);
  abort_unless(app(InventoryPermissionService::class)->can($actor,'inventory.integration.manage'),403,'catalog_source_not_authorized');
  if($action==='catalog.reconcile'){
   $data=validator($data,['entity_type'=>'required|in:item,unit,category','source_id'=>'required|integer|min:1'])->validate();
   $permission=$data['entity_type']==='item'?'inventory.manage_items':'inventory.manage_settings';
   abort_unless(app(InventoryPermissionService::class)->can($actor,$permission),403,'catalog_source_not_authorized');
   abort_unless(app(CatalogSourceSnapshot::class)->capture($data['entity_type'],(int)$data['source_id'],(int)$mapping->solastock_organization_id),404,'catalog_source_missing');
   app(DurableCatalogSync::class)->retry($data['entity_type'],(int)$data['source_id'],(int)$mapping->solastock_organization_id,(int)$actor->id);
  }
  $query=DB::connection('tenant')->table(DurableCatalogSync::TABLE)->where('organization_id',$mapping->solastock_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid);
  if(isset($data['entity_type'],$data['source_id']))$query->where('entity_type',$data['entity_type'])->where('source_id',(int)$data['source_id']);
  return ['organization_mapping_uuid'=>$mapping->mapping_uuid,'rows'=>$query->orderByDesc('updated_at')->limit(100)->get(['source_uuid','entity_type','source_id','target_id','state','attempts','last_error','next_attempt_at','updated_at','source_snapshot'])->map(function($row){$safe=(array)$row;$snapshot=json_decode($row->source_snapshot,true);unset($safe['source_snapshot']);$safe['name']=(string)($snapshot['name']??'');return$safe;})->all()];
 }
 public function authorize(array $data,object $mapping,object $actor):array {
  $data=validator($data,['source_uuid'=>'required|uuid','entity_type'=>'required|in:item,unit,category','source_id'=>'required|integer|min:1','source_revision'=>'required|string|size:64','state_version'=>'required|integer|min:1'])->validate();
  $permission=$data['entity_type']==='item'?'inventory.manage_items':'inventory.manage_settings';
  abort_unless(app(InventoryPermissionService::class)->can($actor,$permission),403,'catalog_source_not_authorized');
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()===0,409,'catalog_authority_transaction_invalid');
  $row=$db->table(DurableCatalogSync::TABLE)->where('organization_id',$mapping->solastock_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_uuid',$data['source_uuid'])->where('entity_type',$data['entity_type'])->where('source_id',$data['source_id'])->first();
  abort_unless($row&&(int)$row->actor_id===(int)$actor->id&&(int)$row->state_version===(int)$data['state_version']&&hash_equals($row->source_revision,$data['source_revision']),409,'catalog_source_changed');
  $snapshot=app(CatalogSourceSnapshot::class)->capture($data['entity_type'],(int)$data['source_id'],(int)$mapping->solastock_organization_id);
  abort_unless($snapshot&&hash_equals(CatalogSourceSnapshot::revision($snapshot),$row->source_revision),409,'catalog_source_changed');
  $dependencies=[];foreach(CatalogSourceSnapshot::dependencies($snapshot)as[$type,$id]){
   $dependency=$db->table(DurableCatalogSync::TABLE)->where('organization_id',$mapping->solastock_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where('source_id',$id)->first();
   abort_unless($dependency&&$dependency->state==='delivered'&&(int)$dependency->target_id>0,409,'catalog_dependency_pending');
   $dependencies[]=['entity_type'=>$type,'source_id'=>$id,'source_uuid'=>$dependency->source_uuid,'target_id'=>(int)$dependency->target_id,'source_revision'=>$dependency->source_revision];
  }
  return ['allowed'=>true,'authority_kind'=>'stock_catalog_source','actor_id'=>(int)$actor->id,'organization_mapping_uuid'=>$mapping->mapping_uuid,'organization_id'=>(int)$mapping->solastock_organization_id,'finance_organization_id'=>(int)$mapping->finance_organization_id]+$data+['snapshot'=>$snapshot,'dependencies'=>$dependencies];
 }
}
