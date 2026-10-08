<?php
namespace App\Services\PurchasingCredits;

use App\Models\Tenant\{CostLayer,StockBalance,StockLedger};
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Check the native aggregate value floor while all affected item pools are locked. */
final class SupplierCreditCostFloorGuard
{
    public function assertPlan(SupplierCreditCostAuthority $authority,array $plan,bool $inverse=false):void
    {
        $org=$authority->organizationId();
        abort_unless(DB::connection('tenant')->transactionLevel()>0 && $org===app(OrganizationContext::class)->idOrFail()
            && ($plan['operation_uuid']??null)===$authority->operationUuid() && ($plan['allocation_uuid']??null)===$authority->allocationUuid(),403);
        $parts=collect($plan['components']??[])->where('destination_role','inventory_asset')->sortBy(fn($p)=>sprintf('%020d:%020d:%020d',$p['item_id'],$p['warehouse_id'],$p['stock_ledger_id']));
        app(PurchaseValuationHoldService::class)->lockItems(collect($plan['components']??[])->pluck('item_id')->all());
        $balances=[];$layers=[];
        foreach($parts as$part){
            $ledger=StockLedger::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($part['stock_ledger_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int)$ledger->item_id===(int)$part['item_id'] && (int)$ledger->warehouse_id===(int)$part['warehouse_id'],409);
            $amount=Decimal::money(Decimal::mul((string)$part['posted_base_amount'],$inverse?'-1':'1'));
            $key=implode('|',[$ledger->item_id,$ledger->warehouse_id,(int)$ledger->variant_id,(int)$ledger->lot_id,(int)$ledger->bin_id]);
            if(!isset($balances[$key])){
                $query=StockBalance::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->where('item_id',$ledger->item_id)->where('warehouse_id',$ledger->warehouse_id);
                foreach(['variant_id','lot_id','bin_id']as$field)$query->whereRaw('COALESCE('.$field.',0)=?',[(int)$ledger->$field]);
                $balances[$key]=['balance'=>$query->lockForUpdate()->firstOrFail(),'amount'=>'0'];
            }
            $balances[$key]['amount']=Decimal::add($balances[$key]['amount'],$amount);
            $layer=(int)data_get($part,'provenance.cost_layer_id',0);
            if($layer)$layers[$layer]=Decimal::add($layers[$layer]??'0',$amount);
        }
        foreach($balances as$entry)abort_unless(Decimal::cmp(Decimal::add((string)$entry['balance']->total_value,$entry['amount']),'0')>=0,409,'Supplier credit exceeds the current native inventory value.');
        ksort($layers,SORT_NUMERIC);
        foreach($layers as$id=>$amount){
            $layer=CostLayer::where('organization_id',$org)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(Decimal::gt((string)$layer->remaining_qty,'0'),409,'Supplier-credit FIFO provenance changed after review.');
            $cost=Decimal::add((string)$layer->unit_cost,Decimal::div($amount,(string)$layer->remaining_qty));
            abort_unless(Decimal::cmp($cost,'0')>=0,409,'Supplier credit exceeds the remaining native FIFO acquisition cost.');
        }
    }
}
