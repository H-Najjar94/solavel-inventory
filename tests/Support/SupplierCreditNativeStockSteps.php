<?php
namespace Tests\Support;
use Illuminate\Support\Facades\{Auth,DB};
/** Closed selectors for the root-owned, separately bootstrapped signed kernel harness. */
final class SupplierCreditNativeStockSteps
{
 public static function run(string $step,array $metadata,array $stock=[],array $arguments=[]):array
 {
  if(PHP_SAPI!=='cli'||!str_starts_with(base_path(),'/qualification/stock')||!app()->environment('testing')
   ||DB::connection('tenant')->getDatabaseName()!=='tenant_000100'
   ||DB::connection('tenant')->selectOne('SELECT CURRENT_USER() AS actual_user')->actual_user!=='t_000100@localhost'
   ||DB::connection('tenant')->transactionLevel()!==0)throw new \LogicException('Isolated native kernel at transaction zero required');
  $org=(int)($metadata['central_organization_id']??0);
  if($org<1)throw new \LogicException('Exact native organization required');
  app(\App\Tenancy\OrganizationContext::class)->set($org);
  if($step==='seed')return SupplierCreditNativeStockFixture::seed($metadata,$arguments['costing_method']??'average');
  if((int)($stock['organization_id']??0)!==$org)throw new \LogicException('Native Stock identity mismatch');
  if($step==='snapshot')return SupplierCreditNativeStockEvidence::snapshot($org);
  if(in_array($step,['receive','consume'],true)){
   $actorId=(int)($metadata['warehouse_actor_id']??0);
   $actor=\App\Models\User::findOrFail($actorId);
   Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
   $access=app(\App\Services\Access\CentralAppAccess::class);
   if(($access->decision($actorId,$org,'inventory')['allowed']??false)!==true
    ||($access->decision($actorId,$org,'finance')['allowed']??false)===true)
    throw new \LogicException('Actual warehouse-only canonical access required');
   $quantity=(string)($arguments['quantity']??'');$date=(string)($arguments['date']??'');
   if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$quantity)||bccomp($quantity,'0',4)<=0
    ||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new \LogicException('Explicit native quantity/date required');
   if($step==='consume')return SupplierCreditNativeStockFixture::consume($org,$stock,$quantity,$date);
   return SupplierCreditNativeStockFixture::receive($org,(int)$metadata['finance_bill_id'],(int)$stock['warehouse_id'],
    $quantity,$date,isset($arguments['unit_cost'])?(string)$arguments['unit_cost']:null);
  }
  if($step==='receipt-documents')return ['delivered_count'=>app(\App\Services\Purchasing\ReceiptHandoffService::class)->deliverDue(1)];
  if($step==='journal'){
   $transport=app(\App\Services\Integration\DurableOutboxTransportService::class);
   $claim=$transport->claim($org,'private-supplier-credit-native-pair');
   return $claim?$transport->processClaim($claim):['status'=>'no_eligible_event'];
  }
  throw new \LogicException('Unsupported native Stock selector');
 }
}
