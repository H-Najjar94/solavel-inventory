<?php
namespace App\Services\PurchasingCredits;

use App\Models\Tenant\{IntegrationPurchaseCostAdjustment,IntegrationPurchaseCostAdjustmentComponent,PurchaseValuationHold,SupplierCreditValueEffect};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\{PurchaseCostAdjustmentPlanner,PurchaseCostAdjustmentService};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Closed value-only kernel. Caller must construct native authority under the same transaction; no transport or route admission here. */
final class HeldSupplierCreditValueService
{
    public function executeLocked(SupplierCreditCostAuthority $authority):array
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0,403);
        $action=$authority->action();$reverse=$authority->reverse();$purpose=$reverse?'credit_reverse':'credit_apply';
        $holds=app(PurchaseValuationHoldService::class);$holds->lockItems(array_column($authority->sourceAllocations(),'item_id'));
        $service=app(PurchaseCostAdjustmentService::class);
        $row=IntegrationPurchaseCostAdjustment::query()->where('organization_id',$authority->organizationId())
            ->where('organization_mapping_uuid',$authority->mappingUuid())->where('destination_document_type',$reverse?'supplier_credit_inverse':'supplier_credit')
            ->where('destination_document_id',$authority->noteId())->where('destination_fingerprint',$authority->fingerprint())->where('safe_metadata->supplier_credit->plan_revision',$authority->planRevision())->lockForUpdate()->first();
        $quote=$authority->storedQuote();
        if($action==='prepare' && !$quote){
            $plan=app(PurchaseCostAdjustmentPlanner::class)->planSupplierCredit($authority);
            if($reverse){
                $original=$authority->forwardQuote();
                abort_unless(is_array($original),409);
                $forwardRow=IntegrationPurchaseCostAdjustment::query()->where('organization_id',$authority->organizationId())
                    ->where('organization_mapping_uuid',$authority->mappingUuid())->where('adjustment_uuid',$original['adjustment_uuid'])
                    ->where('destination_document_type','supplier_credit')->where('state','applied')->lockForUpdate()->firstOrFail();
                foreach(['exact_base_difference','allocated_base_difference','rounding_residual']as$key)$plan[$key]=Decimal::sub('0',$plan[$key],8);
                foreach($plan['components']as&$component)foreach(['exact_base_amount','posted_base_amount']as$key)$component[$key]=Decimal::sub('0',$component[$key],8);
                unset($component);
                $plan['original_adjustment_uuid']=$forwardRow->adjustment_uuid;
                $plan['original_plan_fingerprint']=$original['plan_fingerprint'];
                $plan['classification_difference']=$this->classificationDifference($original['native_plan']['components'],$plan['components']);
                $prepared=$service->prepareSupplierCreditInverse($authority,$plan);
                $row=IntegrationPurchaseCostAdjustment::query()->where('adjustment_uuid',$prepared['adjustment_uuid'])->lockForUpdate()->firstOrFail();
            }else{
                $prepared=$service->prepareSupplierCredit($authority,$plan);
                $row=IntegrationPurchaseCostAdjustment::query()->where('adjustment_uuid',$prepared['adjustment_uuid'])->lockForUpdate()->firstOrFail();
            }
            $revision=$this->valuationSnapshot($authority,$plan);
            $fingerprint=$this->fingerprint($authority,$purpose,$plan,$revision);
            $quote=['contract_version'=>'purchase-credit-value.v1']+$authority->identity()+['adjustment_uuid'=>$row->adjustment_uuid,
                'plan_fingerprint'=>$fingerprint,'native_plan'=>$plan,'valuation_revision'=>$revision];
        }
        abort_unless($row && is_array($quote) && ($quote['adjustment_uuid']??null)===$row->adjustment_uuid
            && is_array($quote['native_plan']??null) && is_array($quote['valuation_revision']??null)
            && hash_equals((string)($quote['plan_fingerprint']??''),$this->fingerprint($authority,$purpose,$quote['native_plan'],$quote['valuation_revision'])),409);
        foreach($authority->identity()as$key=>$value)abort_unless(($quote[$key]??null)===$value,409);
        $complete=$reverse?$row->state==='reversed':$row->state==='applied';
        $owned=[];$pools=[];
        foreach($quote['native_plan']['components']as$part)$pools[$part['item_id'].'|'.$part['warehouse_id']]=[$part['item_id'],$part['warehouse_id']];
        ksort($pools,SORT_NATURAL);
        foreach($pools as[$item,$warehouse]){
            $uuid=$authority->holdUuid($item,$warehouse,$reverse?'reverse':'apply');
            $hold=PurchaseValuationHold::query()->where('organization_id',$authority->organizationId())->where('settlement_uuid',$uuid)
                ->where('purpose',$purpose)->where('plan_revision',$authority->planRevision())->lockForUpdate()->first();
            if($action==='prepare' && !$hold && !$complete){
                $source=collect($authority->sourceAllocations())->first(fn($source)=>$source['item_id']===$item && $source['warehouse_id']===$warehouse);
                abort_unless($source,409);
                $hold=$holds->acquire(['settlement_uuid'=>$uuid,'purpose'=>$purpose,'plan_revision'=>$authority->planRevision(),
                    'item_id'=>$item,'warehouse_id'=>$warehouse,'receipt_id'=>$source['receipt_id'],'source_bill_id'=>$authority->billId(),
                    'source_document_type'=>'supplier_credit','source_document_id'=>$authority->noteId(),'source_journal_id'=>$authority->billJournalId()],$quote['plan_fingerprint']);
            }
            abort_unless($hold && hash_equals($hold->plan_fingerprint,$quote['plan_fingerprint']),409);
            abort_unless($hold->state==='active'||$complete||($action==='release'&&$hold->state==='released'),409);
            $owned[]=$hold;
        }
        if(in_array($action,['apply','reverse','release'],true))abort_unless(hash_equals((string)$authority->planFingerprint(),$quote['plan_fingerprint']),403);
        // A release may abandon a prepared step, never undo a successfully applied financial/value operation.
        if($action==='release')abort_unless(!$complete && $row->state==='prepared',409);
        $before=$this->valuationSnapshot($authority,$quote['native_plan']);
        $native=match($action){'apply'=>$service->applySupplierCredit($authority),'reverse'=>$service->reverseSupplierCredit($authority),default=>$service->statusSupplierCredit($authority)};
        if(in_array($action,['apply','reverse','release'],true))foreach($owned as$hold)if($hold->state==='active')$hold->update(['state'=>'released']);
        $state=$action==='release'?'released':$native['state'];
        $effect=SupplierCreditValueEffect::query()->where('organization_id',$authority->organizationId())->where('adjustment_uuid',$row->adjustment_uuid)
            ->where('direction',$authority->direction())->lockForUpdate()->first();
        if(in_array($action,['apply','reverse'],true) && !$effect){
            // Never change immutable native adjustment metadata. Actual effects have their own append-only audit sidecar.
            abort_unless(!$complete,409);
            $snapshot=json_encode(['before'=>$before,'after'=>$this->valuationSnapshot($authority,$quote['native_plan'])],JSON_THROW_ON_ERROR);
            $effect=SupplierCreditValueEffect::create(['organization_id'=>$authority->organizationId(),'organization_mapping_uuid'=>$authority->mappingUuid(),
                'allocation_uuid'=>$authority->allocationUuid(),'operation_uuid'=>$authority->operationUuid(),'adjustment_uuid'=>$row->adjustment_uuid,
                'direction'=>$authority->direction(),'plan_fingerprint'=>$quote['plan_fingerprint'],'snapshot'=>$snapshot,'snapshot_hash'=>hash('sha256',$snapshot)]);
        }
        if($effect)abort_unless(hash_equals($effect->snapshot_hash,hash('sha256',$effect->snapshot))
            && $effect->allocation_uuid===$authority->allocationUuid() && $effect->operation_uuid===$authority->operationUuid()
            && $effect->plan_fingerprint===$quote['plan_fingerprint'],409);
        $components=IntegrationPurchaseCostAdjustmentComponent::query()->where('organization_id',$authority->organizationId())
            ->where('adjustment_uuid',$row->adjustment_uuid)->orderBy('id')->get()->map(fn($part)=>['component_id'=>(int)$part->id,
                'stock_ledger_id'=>(int)$part->stock_ledger_id,'destination_role'=>$part->destination_role,'destination_source_type'=>$part->destination_source_type,
                'destination_source_id'=>(int)$part->destination_source_id,'posted_base_amount'=>Decimal::mul((string)$part->posted_base_amount,'1',8)])->all();
        return array_replace($quote,['state'=>$state,'holds'=>array_map(fn($hold)=>['hold_id'=>(int)$hold->id,'settlement_uuid'=>$hold->settlement_uuid,
            'purpose'=>$hold->purpose,'plan_revision'=>(int)$hold->plan_revision,'plan_fingerprint'=>$hold->plan_fingerprint,
            'state'=>$hold->state,'updated_at'=>$hold->getRawOriginal('updated_at')],$owned),
            'native_value_adjustment'=>['adjustment_uuid'=>$row->adjustment_uuid,'state'=>$native['state'],'components'=>$components,
                'allocated_base_difference'=>$quote['native_plan']['allocated_base_difference']],
            'physical_movement_ids'=>[],'physical_quantity_delta'=>'0.00000000','financial_journal_ids'=>[],
            'valuation_effect'=>$effect?json_decode($effect->snapshot,true,512,JSON_THROW_ON_ERROR):null,
            'native_voided_journal_id'=>$authority->financialReverseProven()?$authority->financeJournalId():null,'finance_reversal_journal_id'=>null]);
    }
    private function fingerprint(SupplierCreditCostAuthority $authority,string $purpose,array $plan,array $revision):string
    {
        return SolaStockJournalContract::payloadHash(['identity'=>$authority->identity(),'purpose'=>$purpose,'native_plan'=>$plan,'valuation_revision'=>$revision]);
    }
    /** Zero-sum native destination difference; Finance posts this separately from the original NOTE void. */
    private function classificationDifference(array $forward,array $inverse):array
    {
        $rows=[];
        foreach([[$forward,'1'],[$inverse,'1']]as[$parts,$sign])foreach($parts as$part){
            // Forward credit components are negative; current inverse components positive.
            $key=$part['destination_role'].'|'.$part['destination_source_type'].'|'.$part['destination_source_id'];
            $rows[$key]??=['destination_role'=>$part['destination_role'],'destination_source_type'=>$part['destination_source_type'],
                'destination_source_id'=>(int)$part['destination_source_id'],'base_amount'=>'0'];
            $rows[$key]['base_amount']=Decimal::add($rows[$key]['base_amount'],Decimal::mul($part['posted_base_amount'],$sign,8),8);
        }

        $sum='0';foreach($rows as$row)$sum=Decimal::add($sum,$row['base_amount'],8);
        if(!Decimal::isZero($sum,8))$rows['rounding|native|0']=['destination_role'=>'rounding','destination_source_type'=>'native_rounding',
            'destination_source_id'=>0,'base_amount'=>Decimal::sub('0',$sum,8)];
        ksort($rows);return array_values(array_filter($rows,fn($row)=>!Decimal::isZero($row['base_amount'],8)));
    }
    private function valuationSnapshot(SupplierCreditCostAuthority $authority,array $plan):array
    {
        $items=array_values(array_unique(array_column($plan['components'],'item_id')));sort($items,SORT_NUMERIC);
        $rows=[];foreach(['stock_balances','cost_layers']as$table)$rows[$table]=DB::connection('tenant')->table($table)
            ->where('organization_id',$authority->organizationId())->whereIn('item_id',$items)->orderBy('id')->lockForUpdate()->get()->map(fn($row)=>(array)$row)->all();
        return $rows;
    }
}
