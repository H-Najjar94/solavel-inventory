<?php

namespace App\Services\Stock;

use App\Models\Tenant\{CostLayer, IntegrationPurchaseCostAdjustment, IntegrationPurchaseCostAdjustmentComponent, StockBalance};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Atomic and retry-safe persistence/application of a reviewed cost plan. */
final class PurchaseCostAdjustmentService
{
    public function __construct(private PurchaseCostAdjustmentPlanner $planner) {}

    public function prepare(array $input): array
    {
        return DB::connection('tenant')->transaction(function () use ($input): array {
            $plan=$this->planner->plan($input);
            if (!hash_equals((string)$plan['organization_mapping_uuid'], (string)$input['organization_mapping_uuid'])) {
                $this->fail('The requested connection does not match the active organization.');
            }
            $key=hash('sha256',$plan['organization_mapping_uuid'].'|'.$plan['destination_document_id'].'|'.$plan['destination_fingerprint']);
            $row=IntegrationPurchaseCostAdjustment::query()->where('organization_mapping_uuid',$plan['organization_mapping_uuid'])
                ->where('idempotency_key',$key)->lockForUpdate()->first();
            if(!$row){
                $row=IntegrationPurchaseCostAdjustment::query()->create([
                    'adjustment_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$plan['organization_mapping_uuid'],
                    'organization_id'=>app(\App\Tenancy\OrganizationContext::class)->idOrFail(),'destination_document_type'=>'supplier_bill',
                    'destination_document_id'=>$plan['destination_document_id'],'destination_fingerprint'=>$plan['destination_fingerprint'],
                    'currency_code'=>$plan['currency_code'],'base_currency_code'=>$plan['base_currency_code'],'exchange_rate'=>$plan['exchange_rate'],
                    'finance_money_scale'=>$plan['finance_money_scale'],'stock_money_scale'=>2,'exact_base_difference'=>$plan['exact_base_difference'],
                    'allocated_base_difference'=>$plan['allocated_base_difference'],'rounding_residual'=>$plan['rounding_residual'],
                    'rounding_bound'=>$plan['rounding_bound'],'state'=>'prepared','idempotency_key'=>$key,
                    'safe_metadata'=>['contract_version'=>PurchaseCostAdjustmentPlanner::CONTRACT_VERSION],
                ]);
                foreach($plan['components'] as $component) IntegrationPurchaseCostAdjustmentComponent::query()->create($component+[
                    'adjustment_uuid'=>$row->adjustment_uuid,'organization_id'=>$row->organization_id]);
            }
            return $this->serialize($row);
        },5);
    }

    public function apply(array $input): array { return $this->transition($input,false); }
    public function reverse(array $input): array { return $this->transition($input,true); }

    private function transition(array $input,bool $reverse): array
    {
        return DB::connection('tenant')->transaction(function()use($input,$reverse):array{
            $organizationId=app(\App\Tenancy\OrganizationContext::class)->idOrFail();
            $active=\App\Models\Tenant\IntegrationOrganizationMapping::query()->where('mapping_uuid',$input['organization_mapping_uuid'])
                ->where('solastock_organization_id',$organizationId)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())
                ->where('status','verified')->where('activation_state','active')->exists();
            if(!$active)$this->fail('The connection is not active for this organization.');
            $row=IntegrationPurchaseCostAdjustment::query()->where('organization_id',$organizationId)->where('organization_mapping_uuid',$input['organization_mapping_uuid'])
                ->where('destination_document_id',$input['destination_document_id'])->where('destination_fingerprint',$input['destination_fingerprint'])
                ->lockForUpdate()->firstOrFail();
            if($reverse ? $row->state==='reversed' : $row->state==='applied') return $this->serialize($row);
            if($reverse ? $row->state!=='applied' : $row->state!=='prepared') $this->fail('The purchase-cost adjustment is not in the required lifecycle state.');
            $sign=$reverse?'-1':'1';
            $components=IntegrationPurchaseCostAdjustmentComponent::query()->where('adjustment_uuid',$row->adjustment_uuid)->lockForUpdate()->get();
            foreach($components->where('destination_role','inventory_asset') as $component){
                $amount=Decimal::money(Decimal::mul((string)$component->posted_base_amount,$sign));
                $ledger=\App\Models\Tenant\StockLedger::query()->findOrFail($component->stock_ledger_id);
                $balance=StockBalance::query()->where('organization_id',$row->organization_id)->where('item_id',$component->item_id)
                    ->where('warehouse_id',$component->warehouse_id)->whereRaw('COALESCE(variant_id,0)=?',[(int)($ledger->variant_id??0)])
                    ->whereRaw('COALESCE(lot_id,0)=?',[(int)($ledger->lot_id??0)])->whereRaw('COALESCE(bin_id,0)=?',[(int)($ledger->bin_id??0)])
                    ->lockForUpdate()->firstOrFail();
                $balance->total_value=Decimal::money(Decimal::add((string)$balance->total_value,$amount));
                $balance->average_cost=Decimal::isZero((string)$balance->on_hand_qty)?'0':Decimal::cost(Decimal::div((string)$balance->total_value,(string)$balance->on_hand_qty));
                $balance->save();
                $layerId=data_get($component->provenance,'cost_layer_id');
                if($layerId){
                    $layer=CostLayer::query()->where('organization_id',$row->organization_id)->lockForUpdate()->findOrFail($layerId);
                    if(Decimal::isZero((string)$layer->remaining_qty)) $this->fail('A FIFO layer changed after cost review.');
                    $layer->unit_cost=Decimal::cost(Decimal::add((string)$layer->unit_cost,Decimal::div($amount,(string)$layer->remaining_qty)));
                    $layer->save();
                }
            }
            $row->state=$reverse?'reversed':'applied'; $row->{$reverse?'reversed_at':'applied_at'}=now(); $row->save();
            return $this->serialize($row);
        },5);
    }

    private function serialize($row):array
    {
        return ['contract_version'=>PurchaseCostAdjustmentPlanner::CONTRACT_VERSION,'adjustment_uuid'=>$row->adjustment_uuid,
            'organization_mapping_uuid'=>$row->organization_mapping_uuid,'destination_document_id'=>(int)$row->destination_document_id,
            'destination_fingerprint'=>$row->destination_fingerprint,'state'=>$row->state,
            'exact_base_difference'=>(string)$row->exact_base_difference,'allocated_base_difference'=>(string)$row->allocated_base_difference,
            'rounding_residual'=>(string)$row->rounding_residual,'rounding_bound'=>(string)$row->rounding_bound,
            'components'=>IntegrationPurchaseCostAdjustmentComponent::query()->where('adjustment_uuid',$row->adjustment_uuid)->orderBy('id')->get()->map(fn($c)=>[
                'destination_role'=>$c->destination_role,'destination_source_type'=>$c->destination_source_type,
                'destination_source_id'=>$c->destination_source_id,'base_quantity'=>(string)$c->base_quantity,
                'posted_base_amount'=>(string)$c->posted_base_amount])->all()];
    }
    private function fail(string $message):never{throw ValidationException::withMessages(['purchase_cost_adjustment'=>$message]);}
}
