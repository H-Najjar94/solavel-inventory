<?php
namespace App\Services\Consumption;

use App\Models\Tenant\Item;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Access\InventoryPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConsumptionAccounts {
    public function connection(int $org): ?object {
        return IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)
            ->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->first();
    }
    public function resolve(Item $item, ?int $override = null): array {
        $mapping = $this->connection((int)$item->organization_id);
        $setting = IntegrationSetting::query()->where('organization_id',$item->organization_id)->where('integration','solabooks')->first();
        $connected = $mapping !== null || ($setting && $setting->mode !== 'disconnected');
        if ($connected && (!$mapping || !in_array($mapping->status,['verified','verified_hold'],true))) $this->fail();
        $default = DB::connection('tenant')->table('integration_account_mappings')->where('organization_id',$item->organization_id)
            ->where('integration','solabooks')->where('mapping_type','internal_consumption_expense')->whereIn('status',['mapped','verified'])->value('solabooks_account_id');
        $category = $item->category;
        try {
            $selection = ConsumptionAccountSelection::resolve($override,$item->internal_consumption_account_id,$category?->internal_consumption_account_id,
                $default ? (int)$default : null, app(InventoryPermissionService::class)->can(auth()->user(),'inventory.consumption.override_account'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['account_override_id'=>__('inventory.consumption.'.$e->getMessage())]);
        }
        if (!$connected) return ['connected'=>false,'account_id'=>null,'inventory_account_id'=>null,'source'=>'standalone'];
        if (!$selection) $this->fail();
        $itemMapping=DB::connection('tenant')->table('integration_master_data_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type','item')->where('solastock_record_id',(string)$item->id)->where('status','verified')->first();
        $financeItem=$itemMapping ? DB::connection('tenant')->table('inventory_items')->where('id',$itemMapping->solabooks_record_id)->where('organization_id',$mapping->finance_organization_id)->first():null;
        if (!$financeItem || ($financeItem->item_type ?? $financeItem->type)!=='inventory' || !$financeItem->inventory_asset_account_id || (!empty($financeItem->purchase_account_id ?? $financeItem->default_purchase_account_id ?? null) && (int)($financeItem->purchase_account_id ?? $financeItem->default_purchase_account_id)!==(int)$financeItem->inventory_asset_account_id)) $this->fail();
        $account = DB::connection('tenant')->table('accounts')->where('id',$selection['account_id'])->first();
        if (!ConsumptionAccountSelection::validAccount($account ? (array)$account : null,(int)$mapping->finance_organization_id)) $this->fail();
        $assetId = DB::connection('tenant')->table('integration_account_mappings')->where('organization_id',$item->organization_id)
            ->where('integration','solabooks')->where('mapping_type','inventory_asset')->whereIn('status',['mapped','verified'])->value('solabooks_account_id');
        $asset = DB::connection('tenant')->table('accounts')->where('id',$assetId)->where('organization_id',$mapping->finance_organization_id)
            ->where('is_active',true)->where('is_postable',true)->where('type','asset')->first();
        if (!$asset || (int)$financeItem->inventory_asset_account_id!==(int)$assetId || !empty($asset->deleted_at)) $this->fail();
        return $selection + ['connected'=>true,'inventory_account_id'=>(int)$assetId,'account_name'=>$account->name];
    }
    public function validateDefault(int $org,?int $id): void {
        if ($id === null) return;
        abort_unless(app(InventoryPermissionService::class)->can(auth()->user(),'inventory.consumption.override_account'),403);
        $mapping=$this->connection($org);
        $account=DB::connection('tenant')->table('accounts')->where('id',$id)->first();
        if (!$mapping || !ConsumptionAccountSelection::validAccount($account?(array)$account:null,(int)$mapping->finance_organization_id)) $this->fail();
    }
    public function assertDate(int $org,string $date): void {
        $mapping=$this->connection($org);
        if (!$mapping) $this->fail();
        $finance=(int)$mapping->finance_organization_id;
        $organization=DB::connection('tenant')->table('organizations')->where('id',$finance)->lockForUpdate()->first();
        $period=DB::connection('tenant')->table('accounting_periods')->where('organization_id',$finance)->whereDate('start_date','<=',$date)->whereDate('end_date','>=',$date)->lockForUpdate()->first();
        $year=$period && !empty($period->fiscal_year_id) ? DB::connection('tenant')->table('fiscal_years')->where('id',$period->fiscal_year_id)->where('org_id',$finance)->lockForUpdate()->first():null;
        if (!$organization || (!empty($organization->lock_date) && $date<=substr($organization->lock_date,0,10)) || !$period || $period->status!=='open' || ($year && $year->status!=='open')) {
            throw ValidationException::withMessages(['document_date'=>__('inventory.consumption.closed_period')]);
        }
    }
    private function fail(): never {
        throw ValidationException::withMessages(['account_mappings'=>__('inventory.consumption.mapping_required',['url'=>'/inventory/integrations/solabooks'])]);
    }
}
