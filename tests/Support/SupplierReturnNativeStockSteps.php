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
  if($step==='receive'){ $actor=\App\Models\User::findOrFail((int)$ids['warehouse_actor_id']);Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);return SupplierCreditNativeStockFixture::receive($org,(int)$ids['finance_bill_id'],(int)$stock['warehouse_id'],(string)$args['quantity'],(string)$args['date'],isset($args['unit_cost'])?(string)$args['unit_cost']:null); }
  if(in_array($step,['seed','journal','documents'],true)){
   $result=$step==='seed'?SupplierCreditNativeStockFixture::seed($ids,(string)($args['costing_method']??'average')):CustomerCreditNativeStockSteps::run($step,$ids,$stock,$args);
   if($step==='seed'){
    $account=(int)$ids['account_roles']['supplier_return_clearing'];if(!$db->table('accounts')->where('organization_id',$ids['finance_organization_id'])->where('id',$account)->where('account_role','supplier_return_clearing')->where('is_active',true)->exists())throw new \LogicException('Actual canonical supplier return account required.');
    $ref=\App\Models\Tenant\IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'supplier_return_clearing','solabooks_account_id'=>$account,'status'=>'verified']);
    \App\Models\Tenant\IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)\Illuminate\Support\Str::uuid(),'organization_mapping_uuid'=>$ids['organization_mapping_uuid'],'central_client_id'=>100,'central_organization_id'=>$org,'finance_organization_id'=>$ids['finance_organization_id'],'solastock_organization_id'=>$org,'entity_type'=>'account_role','solastock_record_id'=>(string)$ref->id,'solabooks_record_id'=>(string)$account,'status'=>'verified']);
    $setting=\App\Models\Tenant\IntegrationSetting::where('organization_id',$org)->where('integration','solabooks')->sole();$meta=(array)$setting->meta;$meta['transport_enabled_workflows']=array_values(array_unique(array_merge($meta['transport_enabled_workflows']??[],['supplier_return.posted','supplier_return.reversed'])));$setting->meta=$meta;$setting->save();}
   return$result;
  }
  if($step==='snapshot')return ['physical'=>CustomerCreditNativeStockEvidence::snapshot($org),'returns'=>SupplierReturn::query()->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'cost_layers'=>$db->table('cost_layers')->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'adjustments'=>$db->table('integration_purchase_cost_adjustments')->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'adjustment_components'=>$db->table('integration_purchase_cost_adjustment_components')->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'return_lines'=>$db->table('supplier_return_lines')->where('organization_id',$org)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all()];
  if(!in_array($step,['return','reverse-return'],true))throw new \LogicException('Unsupported closed native return selector.');
  $actor=\App\Models\User::findOrFail(17003);Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $access=app(\App\Services\Access\CentralAppAccess::class);if(($access->decision(17003,$org,'inventory')['allowed']??false)!==true||($access->decision(17003,$org,'finance')['allowed']??false)===true||!app(\App\Services\Access\InventoryPermissionService::class)->can($actor,'inventory.manage_returns'))throw new \LogicException('Actual warehouse-only return authority required.');
  $native=app(\App\Services\Documents\SupplierReturnService::class);
  if($step==='reverse-return'){$return=SupplierReturn::query()->where('organization_id',$org)->whereKey((int)$args['stock_return_id'])->firstOrFail();$inverse=$native->reverse($return,'Private verified supplier return inverse');return ['stock_return_id'=>$return->id,'stock_reversal_id'=>$inverse->id,'status'=>$return->fresh()->status];}
  $receipt=GoodsReceipt::query()->where('organization_id',$org)->whereKey((int)$args['receipt_id'])->where('status','posted')->firstOrFail();$line=$receipt->lines()->whereKey((int)$args['receipt_line_id'])->firstOrFail();
  $return=$native->createDraft(['goods_receipt_id'=>$receipt->id,'return_date'=>(string)$args['date'],'reason'=>'Private actual supplier return'],[['goods_receipt_line_id'=>$line->id,'entered_qty'=>(string)$args['quantity']]+(isset($args['source_stock_ledger_id'])?['source_stock_ledger_id'=>(int)$args['source_stock_ledger_id']]:[])]);$return=$native->post($return);
  $life=\App\Models\Tenant\IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$ids['organization_mapping_uuid'])->where('source_document_type','supplier_return')->where('source_document_id',(string)$return->id)->sole();
  return ['stock_return_id'=>$return->id,'return_mapping_uuid'=>$life->mapping_uuid,'return_number'=>$return->return_number,'status'=>$return->status];
 }
}
