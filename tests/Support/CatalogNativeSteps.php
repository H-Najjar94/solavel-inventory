<?php
namespace Tests\Support;
use App\Models\Tenant\{Item,ItemCategory,Unit,IntegrationOrganizationMapping};
use App\Services\Catalog\DurableCatalogSync;
use Illuminate\Support\Facades\{Auth,DB};
/** Genuine native source writes and outbox transport; no financial/physical fixtures. */
final class CatalogNativeSteps {
 public static function run(string $step,int $org):array {
  if(PHP_SAPI!=='cli'||base_path()!=='/qualification/stock'||!app()->environment('testing')||$org!==16001||DB::connection('tenant')->getDatabaseName()!=='tenant_000100'||DB::connection('tenant')->selectOne('SELECT CURRENT_USER() AS actual')->actual!=='t_000100@localhost'||DB::connection('tenant')->transactionLevel()!==0)throw new \LogicException('Exact private native Stock required');
  app(\App\Tenancy\OrganizationContext::class)->set($org);$actor=\App\Models\User::findOrFail(17003);Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $access=app(\App\Services\Access\CentralAppAccess::class);if(($access->decision(17003,$org,'inventory')['allowed']??false)!==true||($access->decision(17003,$org,'finance')['allowed']??false)===true)throw new \LogicException('Actual Stock-only actor required');
  foreach(['inventory.manage_items','inventory.manage_settings']as$permission)if(!app(\App\Services\Access\InventoryPermissionService::class)->can($actor,$permission))throw new \LogicException('Canonical native catalog permission required');
  $map=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('status','verified')->where('activation_state','active')->sole();
  if($step==='catalog-create'){
   if(Item::query()->where('sku','CATALOG-NATIVE-PANEL')->exists())throw new \LogicException('Create selector must run once');
   $unit=Unit::create(['name'=>'Private native catalog each','code'=>'CATALOG-NATIVE-EACH','kind'=>'count','is_active'=>true]);
   $parent=ItemCategory::create(['name'=>'Private catalog parent','is_active'=>true]);$category=ItemCategory::create(['name'=>'Private catalog child','parent_id'=>$parent->id,'is_active'=>true]);
   $item=Item::create(['name'=>'Private native catalog panel','sku'=>'CATALOG-NATIVE-PANEL','base_unit_id'=>$unit->id,'category_id'=>$category->id,'tracking_type'=>'none','costing_method'=>'average','is_active'=>true]);
  }elseif($step==='catalog-deliver')app(DurableCatalogSync::class)->process($map,20);
  elseif($step==='catalog-edit'){ $item=Item::query()->where('sku','CATALOG-NATIVE-PANEL')->sole();$item->name='Private native catalog updated';$item->save(); }
  elseif($step!=='catalog-evidence')throw new \LogicException('Closed native catalog selector required');
  $rows=DB::connection('tenant')->table(DurableCatalogSync::TABLE)->where('organization_id',$org)->where('organization_mapping_uuid',$map->mapping_uuid)->orderBy('id')->get()->map(function($row){$r=(array)$row;unset($r['source_snapshot']);return$r;})->all();
  return ['canonical_stock_access'=>true,'canonical_finance_access'=>false,'mapping_uuid'=>$map->mapping_uuid,'rows'=>$rows,'stock_ledger_count'=>DB::connection('tenant')->table('stock_ledger')->where('organization_id',$org)->count(),'journal_count'=>DB::connection('tenant')->table('journal_entries')->where('organization_id',$map->finance_organization_id)->count()];
 }
}
