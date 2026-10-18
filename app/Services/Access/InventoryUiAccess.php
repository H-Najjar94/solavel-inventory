<?php
namespace App\Services\Access;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Illuminate\Support\Facades\Route;
/** Mirrors existing API gates for presentation; it never grants server access. */
final class InventoryUiAccess {
 public function __construct(private InventoryCommercialEntitlementService $entitlements){}
 public function describe(array $rolePermissions):array {
  $enforced=(bool)config('inventory_entitlements.feature_enforcement',false);$permissionDecisions=[];$featureDecisions=[];
  $checkPermission=function($permission)use(&$permissionDecisions,$rolePermissions,$enforced){return $permissionDecisions[$permission]??= !in_array($permission,$rolePermissions,true)?['allowed'=>false,'reason_code'=>'permission_denied']:($enforced?$this->entitlements->checkPermission($permission):['allowed'=>true,'reason_code'=>'allowed']);};
  $effective=array_values(array_filter($rolePermissions,fn($permission)=>$checkPermission($permission)['allowed']));$rules=[];
  foreach(Route::getRoutes() as $route){
   $uri=preg_replace('#^(?:inventory/)?api/v1#','',$route->uri());if($uri===$route->uri()||$uri==='')continue;
   $decision=['allowed'=>true,'reason_code'=>'allowed'];$features=[];
   foreach($route->gatherMiddleware()as$middleware){if(str_starts_with($middleware,'perm:')){$d=$checkPermission(substr($middleware,5));if(!$d['allowed']){$decision=$d;break;}}if(str_starts_with($middleware,'feature:'))$features=array_merge($features,explode(',',substr($middleware,8)));}
   // Laravel dot paths cannot read literal route names containing dots.
   $mapped=((array)config('inventory_entitlements.route_features',[]))[$route->getName()]??null;if($mapped)$features[]=$mapped;
   if($decision['allowed']&&$enforced)foreach(array_unique($features)as$feature){$d=$featureDecisions[$feature]??=$this->entitlements->checkFeature($feature);if(!$d['allowed']){$decision=$d;break;}}
   foreach(array_diff($route->methods(),['HEAD','OPTIONS'])as$method)$rules[]=['method'=>$method,'pattern'=>$uri,'allowed'=>(bool)$decision['allowed'],'reason'=>$decision['reason_code']??null];
  }
  return ['permissions'=>$effective,'routes'=>$rules];
 }
}
