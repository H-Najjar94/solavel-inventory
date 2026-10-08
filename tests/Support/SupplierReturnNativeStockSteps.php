<?php
namespace Tests\Support;
use App\Models\Tenant\{GoodsReceipt,SupplierReturn};
use Illuminate\Support\Facades\{DB,Auth};
/** Closed native return operations; real Finance signed consumer owns the commercial credit. */
final class SupplierReturnNativeStockSteps
{
 public static function run(string $step,array $ids,array $stock,array $args=[]):array
 {
  $db=DB::connection('tenant');$org=(int)$ids['central_organization_id'];
  if(PHP_SAPI!=='cli'||!app()->environment('testing')||!str_starts_with(base_path(),'/qualification/stock')||$org<1||$db->getDatabaseName()!=='tenant_000100'||$db->transactionLevel()!==0||$db->selectOne('SELECT CURRENT_USER() AS u')->u!=='t_000100@localhost')throw new \LogicException('Private native Stock pair only.');
  app(\App\Tenancy\OrganizationContext::class)->set($org);
  if(in_array($step,['seed','receive','journal','documents'],true)){
   $result=CustomerCreditNativeStockSteps::run($step,$ids,$stock,$args);
   if($step==='seed'){$setting=\App\Models\Tenant\IntegrationSetting::where('organization_id',$org)->where('integration','solabooks')->sole();$meta=(array)$setting->meta;$meta['transport_enabled_workflows']=array_values(array_unique(array_merge($meta['transport_enabled_workflows']??[],['supplier_return.posted','supplier_return.reversed'])));$setting->meta=$meta;$setting->save();}
   return$result;
  }
  if($step==='snapshot')return ['physical'=>SupplierCreditNativeStockEvidence::snapshot($org),'returns'=>SupplierReturn::query()->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'return_lines'=>$db->table('supplier_return_lines')->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all()];
  if(!in_array($step,['return','reverse-return'],true))throw new \LogicException('Unsupported closed native return selector.');
  $actor=\App\Models\User::findOrFail(17003);Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $access=app(\App\Services\Access\CentralAppAccess::class);if(($access->decision(17003,$org,'inventory')['allowed']??false)!==true||($access->decision(17003,$org,'finance')['allowed']??false)===true||!app(\App\Services\Access\InventoryPermissionService::class)->can($actor,'inventory.manage_returns'))throw new \LogicException('Actual warehouse-only return authority required.');
  $native=app(\App\Services\Documents\SupplierReturnService::class);
  if($step==='reverse-return'){$return=SupplierReturn::query()->where('organization_id',$org)->whereKey((int)$args['stock_return_id'])->firstOrFail();$inverse=$native->reverse($return,'Private verified supplier return inverse');return ['stock_return_id'=>$return->id,'stock_reversal_id'=>$inverse->id,'status'=>$return->fresh()->status];}
  $receipt=GoodsReceipt::query()->where('organization_id',$org)->whereKey((int)$args['receipt_id'])->where('status','posted')->firstOrFail();$line=$receipt->lines()->whereKey((int)$args['receipt_line_id'])->firstOrFail();
  $return=$native->createDraft(['goods_receipt_id'=>$receipt->id,'return_date'=>(string)$args['date'],'reason'=>'Private actual supplier return'],[['goods_receipt_line_id'=>$line->id,'entered_qty'=>(string)$args['quantity']]+(isset($args['source_stock_ledger_id'])?['source_stock_ledger_id'=>(int)$args['source_stock_ledger_id']]:[])]);$return=$native->post($return);
  return ['stock_return_id'=>$return->id,'return_mapping_uuid'=>$return->return_uuid,'return_number'=>$return->return_number,'status'=>$return->status];
 }
}
