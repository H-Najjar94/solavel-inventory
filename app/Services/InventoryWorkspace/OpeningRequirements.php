<?php

namespace App\Services\InventoryWorkspace;

use App\Models\Tenant\{IntegrationAccountMapping, IntegrationMasterDataMapping, IntegrationOrganizationMapping, IntegrationSetting, Item, StockBalance, Warehouse};
use App\Services\Access\WarehouseAccessService;
use App\Services\Integration\{FinanceBaseValuation, OrganizationAccountRequirements};
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only owner facts for a bounded opening plan; no inferred master matches. */
final class OpeningRequirements
{
    public function read(int $warehouseId, array $financeItemIds): array
    {
        $org=app(OrganizationContext::class)->idOrFail();
        app(WarehouseAccessService::class)->assertAllowed($warehouseId);
        $warehouse=Warehouse::query()->whereKey($warehouseId)->where('is_active',true)->firstOrFail();
        $mapping=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)
            ->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())
            ->where('status','verified')->where('activation_state','active')->first();
        $setting=IntegrationSetting::query()->where('integration','solabooks')->where('organization_id',$org)->first();
        if (!$mapping || $setting?->mode!=='active') $this->fail('connection','An active verified connection is required for migration opening stock.');
        app(OrganizationAccountRequirements::class)->assertOperationReady($org,'opening_stock.posted');
        $currency=app(FinanceBaseValuation::class)->contract($org);
        if (!preg_match('/^[A-Z]{3}$/D',(string)($currency['base_currency_code'] ?? ''))) $this->fail('currency','The reviewed Finance base-currency contract is unavailable.');
        $accounts=IntegrationAccountMapping::query()->where('organization_id',$org)->where('integration','solabooks')
            ->whereIn('mapping_type',['inventory_asset','opening_offset'])->whereIn('status',['mapped','verified'])
            ->pluck('solabooks_account_id','mapping_type')->all();
        $identities=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('entity_type','item')->whereIn('solabooks_record_id',array_map('strval',$financeItemIds))
            ->whereIn('status',['mapped','verified'])->whereNull('conflict_code')->get()->keyBy('solabooks_record_id');
        $items=Item::query()->with('baseUnit')->whereIn('id',$identities->pluck('solastock_record_id'))->get()->keyBy('id');
        $units=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('entity_type','unit')->whereIn('solastock_record_id',$items->pluck('base_unit_id')->map(fn($id)=>(string)$id))
            ->whereIn('status',['mapped','verified'])->whereNull('conflict_code')->get()->keyBy('solastock_record_id');
        $balances=StockBalance::query()->where('warehouse_id',$warehouseId)->whereIn('item_id',$items->keys())
            ->selectRaw('item_id, SUM(on_hand_qty) quantity, SUM(total_value) value')->groupBy('item_id')->get()->keyBy('item_id');
        $result=[];
        foreach ($financeItemIds as $financeId) {
            $identity=$identities->get((string)$financeId);
            $item=$identity ? $items->get((int)$identity->solastock_record_id) : null;
            $unit=$item ? $units->get((string)$item->base_unit_id) : null;
            if (!$item || !$item->is_active || $item->item_type!=='inventory' || $item->tracking_type!=='none'
                || $item->is_variant_parent || !in_array($item->costing_method,['average','fifo'],true)
                || !$unit || !$item->baseUnit?->is_active) {
                $this->fail('items','Finance item '.$financeId.' needs an active immutable Stock item/base-unit mapping. Lot, serial and variant opening schedules are not represented by this migration format.');
            }
            $balance=$balances->get($item->id);
            $result[]=['finance_item_id'=>(int)$financeId,'stock_item_id'=>(int)$item->id,'name'=>$item->name,'sku'=>$item->sku,
                'base_unit_id'=>(int)$item->base_unit_id,'finance_unit_id'=>(int)$unit->solabooks_record_id,
                'item_mapping_uuid'=>$identity->mapping_uuid,'unit_mapping_uuid'=>$unit->mapping_uuid,
                'costing_method'=>$item->costing_method,'quantity'=>bcadd((string)($balance?->quantity ?? '0'),'0',4),
                'value'=>bcadd((string)($balance?->value ?? '0'),'0',2)];
        }
        $facts=['warehouse'=>['id'=>$warehouseId,'name'=>$warehouse->name,'code'=>$warehouse->code],
            'currency_code'=>$currency['base_currency_code'],'inventory_account_id'=>(int)$accounts['inventory_asset'],
            'opening_offset_account_id'=>(int)$accounts['opening_offset'],'items'=>$result];
        return $facts+['version'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR))];
    }

    private function fail(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }
}
