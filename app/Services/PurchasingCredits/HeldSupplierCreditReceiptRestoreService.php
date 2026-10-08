<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\{IntegrationPurchaseCostAdjustment,IntegrationPurchaseCostAdjustmentComponent,PurchaseValuationHold,SupplierCreditValueEffect};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\{PurchaseCostAdjustmentPlanner,PurchaseCostAdjustmentService};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
/** Fixed positive native sibling effects while the exact NOTE's physical cohort stays held. */
final class HeldSupplierCreditReceiptRestoreService
{
 public function executeLocked(SupplierCreditReceiptRestoreAuthority $authority):array
 {
  abort_unless(DB::connection('tenant')->transactionLevel()>0,403);
  $action=$authority->action();$purpose='credit_reverse';$holds=app(PurchaseValuationHoldService::class);
  $holds->lockItems(array_column($authority->sourceAllocations(),'item_id'));
  $service=app(PurchaseCostAdjustmentService::class);
  $row=IntegrationPurchaseCostAdjustment::query()->where('organization_id',$authority->organizationId())->where('organization_mapping_uuid',$authority->mappingUuid())
   ->where('destination_document_type','supplier_credit_receipt_restore')->where('destination_document_id',$authority->noteId())
   ->where('destination_fingerprint',$authority->fingerprint())->where('safe_metadata->supplier_credit->plan_revision',$authority->planRevision())->lockForUpdate()->first();
  $quote=$authority->storedQuote();
  if($action==='prepare'&&!$quote){
   $plan=app(PurchaseCostAdjustmentPlanner::class)->planSupplierCredit($authority);
   foreach(['exact_base_difference','allocated_base_difference','rounding_residual']as$key)$plan[$key]=Decimal::sub('0',$plan[$key],8);
   foreach($plan['components']as&$component)foreach(['exact_base_amount','posted_base_amount']as$key)$component[$key]=Decimal::sub('0',$component[$key],8);unset($component);
   $plan['contract_version']='purchase-credit-receipt-restore.v1';$plan['destination_document_type']='supplier_credit_receipt_restore';
   $prepared=$service->prepareSupplierCreditReceiptRestore($authority,$plan);
   $row=IntegrationPurchaseCostAdjustment::query()->where('adjustment_uuid',$prepared['adjustment_uuid'])->lockForUpdate()->firstOrFail();
   $revision=$this->valuation($authority,$plan);
   $quote=['contract_version'=>'purchase-credit-receipt-restore.v1']+$authority->identity()+['adjustment_uuid'=>$row->adjustment_uuid,
    'native_plan'=>$plan,'valuation_revision'=>$revision];
   $quote['plan_fingerprint']=$this->fingerprint($authority,$plan,$revision);
  }
  abort_unless($row&&is_array($quote)&&($quote['adjustment_uuid']??null)===$row->adjustment_uuid
   &&hash_equals((string)($quote['plan_fingerprint']??''),$this->fingerprint($authority,$quote['native_plan'],$quote['valuation_revision'])),409);
  foreach($authority->identity()as$key=>$value)abort_unless(($quote[$key]??null)===$value,409);
  $complete=$row->state==='applied';$owned=[];$pools=[];
  foreach($quote['native_plan']['components']as$part)$pools[$part['item_id'].'|'.$part['warehouse_id']]=[$part['item_id'],$part['warehouse_id']];ksort($pools,SORT_NATURAL);
  foreach($pools as[$item,$warehouse]){
   $uuid=$authority->holdUuid($item,$warehouse);
   $hold=PurchaseValuationHold::query()->where('organization_id',$authority->organizationId())->where('settlement_uuid',$uuid)
    ->where('purpose',$purpose)->where('plan_revision',$authority->planRevision())->lockForUpdate()->first();
   if($action==='prepare'&&!$hold&&!$complete){
    $source=collect($authority->sourceAllocations())->first(fn($s)=>$s['item_id']===$item&&$s['warehouse_id']===$warehouse);abort_unless($source,409);
    $hold=$holds->acquire(['settlement_uuid'=>$uuid,'purpose'=>$purpose,'plan_revision'=>$authority->planRevision(),'item_id'=>$item,'warehouse_id'=>$warehouse,
     'receipt_id'=>$source['receipt_id'],'source_bill_id'=>$authority->billId(),'source_document_type'=>'supplier_credit_receipt_restore',
     'source_document_id'=>$authority->noteId(),'source_journal_id'=>$authority->billJournalId()],$quote['plan_fingerprint']);
   }
   abort_unless($hold&&hash_equals($hold->plan_fingerprint,$quote['plan_fingerprint'])&&($hold->state==='active'||$complete||($action==='release'&&$hold->state==='released')),409);$owned[]=$hold;
  }
  if(in_array($action,['apply','release'],true))abort_unless(hash_equals((string)$authority->planFingerprint(),$quote['plan_fingerprint']),403);
  if($action==='release')abort_unless(!$complete&&$row->state==='prepared',409);
  $before=$this->valuation($authority,$quote['native_plan']);
  $native=$action==='apply'?$service->applySupplierCreditReceiptRestore($authority):$service->statusSupplierCreditReceiptRestore($authority);
  if(in_array($action,['apply','release'],true))foreach($owned as$hold)if($hold->state==='active')$hold->update(['state'=>'released']);
  $effect=SupplierCreditValueEffect::query()->where('organization_id',$authority->organizationId())->where('adjustment_uuid',$row->adjustment_uuid)->where('direction','receipt_restore')->lockForUpdate()->first();
  if($action==='apply'&&!$effect){
   abort_unless(!$complete,409);$snapshot=json_encode(['before'=>$before,'after'=>$this->valuation($authority,$quote['native_plan'])],JSON_THROW_ON_ERROR);
   $effect=SupplierCreditValueEffect::create(['organization_id'=>$authority->organizationId(),'organization_mapping_uuid'=>$authority->mappingUuid(),
    'allocation_uuid'=>$authority->allocationUuid(),'operation_uuid'=>$authority->operationUuid(),'direction'=>'receipt_restore','plan_revision'=>$authority->planRevision(),
    'adjustment_uuid'=>$row->adjustment_uuid,'snapshot'=>$snapshot,'snapshot_hash'=>hash('sha256',$snapshot)]);
  }
  $components=IntegrationPurchaseCostAdjustmentComponent::query()->where('adjustment_uuid',$row->adjustment_uuid)->orderBy('id')->get()->map(fn($part)=>[
   'component_id'=>(int)$part->id,'component_uuid'=>$part->component_uuid,'allocation_uuid'=>$part->allocation_uuid,'stock_ledger_id'=>(int)$part->stock_ledger_id,
   'destination_role'=>$part->destination_role,'destination_source_type'=>$part->destination_source_type,'destination_source_id'=>(int)$part->destination_source_id,
   'posted_base_amount'=>(string)$part->posted_base_amount])->all();
  return array_replace($quote,['state'=>$action==='release'?'released':($native['state']==='applied'?'restored':$native['state']),
   'native_value_adjustment'=>['adjustment_uuid'=>$row->adjustment_uuid,'state'=>$native['state'],'components'=>$components,'allocated_base_difference'=>$quote['native_plan']['allocated_base_difference']],
   'holds'=>array_map(fn($h)=>['hold_id'=>(int)$h->id,'settlement_uuid'=>$h->settlement_uuid,'purpose'=>$h->purpose,'plan_revision'=>(int)$h->plan_revision,
    'plan_fingerprint'=>$h->plan_fingerprint,'state'=>$h->state,'updated_at'=>$h->getRawOriginal('updated_at')],$owned),
   'physical_movement_ids'=>[],'physical_quantity_delta'=>'0.00000000','financial_journal_ids'=>[],
   'native_voided_journal_id'=>$authority->financialReverseProven()?(int)$authority->identity()['original_journal_id']:null,
   'finance_reversal_journal_id'=>null,'restore_journal_id'=>$authority->restoreJournalId(),
   'valuation_effect'=>$effect?json_decode($effect->snapshot,true,512,JSON_THROW_ON_ERROR):null]);
 }
 private function fingerprint(SupplierCreditReceiptRestoreAuthority $authority,array $plan,array $revision):string
 {return SolaStockJournalContract::payloadHash(['identity'=>$authority->identity(),'purpose'=>'credit_reverse','native_plan'=>$plan,'valuation_revision'=>$revision]);}
 private function valuation(SupplierCreditReceiptRestoreAuthority $authority,array $plan):array
 {
  $pools=[];foreach($plan['components']as$p)$pools[$p['item_id'].'|'.$p['warehouse_id']]=[$p['item_id'],$p['warehouse_id']];ksort($pools,SORT_NATURAL);$rows=[];
  foreach(['stock_balances','cost_layers']as$table)$rows[$table]=DB::connection('tenant')->table($table)->where('organization_id',$authority->organizationId())
   ->where(function($q)use($pools){if(!$pools)$q->whereRaw('1=0');foreach($pools as[$i,$w])$q->orWhere(fn($p)=>$p->where('item_id',$i)->where('warehouse_id',$w));})
   ->orderBy('id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();return $rows;
 }
}
