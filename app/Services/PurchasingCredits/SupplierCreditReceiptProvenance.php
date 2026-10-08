<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\{GoodsReceipt,IntegrationOrganizationMapping,IntegrationDocumentLifecycleMapping,IntegrationMasterDataMapping,IntegrationOutboxEvent};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
/** Reads actual native receipt cost cohorts; does not issue a posting authority. */
final class SupplierCreditReceiptProvenance
{
    public static function fromSource(array $source,object $position,IntegrationOrganizationMapping $map,int $org,int $scale,int $supplier,string $invoiceRate):array
    {
        $db=DB::connection('tenant');
        IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('mapping_uuid',$source['receipt_mapping_uuid'])
            ->where('source_document_type','goods_receipt')->where('source_document_id',(string)$source['receipt_id'])->firstOrFail();
        $grn=GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($source['receipt_id'])->lockForUpdate()->firstOrFail();
        abort_unless($grn->status==='posted' && !$grn->reversal_id,409);
        abort_unless(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('entity_type','supplier')
            ->where('central_client_id',$map->central_client_id)->where('central_organization_id',$org)->where('finance_organization_id',$map->finance_organization_id)
            ->where('solastock_organization_id',$org)->where('solastock_record_id',(string)$grn->supplier_id)->where('solabooks_record_id',(string)$supplier)->where('status','verified')
            ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->exists(),403);
        $line=$grn->lines()->whereKey($source['receipt_line_id'])->lockForUpdate()->firstOrFail();
        foreach(['item'=>[$line->item_id,$position->inventory_item_id],'unit'=>[$line->entered_unit_id,$position->entered_unit_id]]as$type=>$pair)
            abort_unless(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('entity_type',$type)
                ->where('central_client_id',$map->central_client_id)->where('central_organization_id',$org)->where('finance_organization_id',$map->finance_organization_id)
                ->where('solastock_organization_id',$org)->where('solastock_record_id',(string)$pair[0])->where('solabooks_record_id',(string)$pair[1])->where('status','verified')
                ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->exists(),403);
        $event=IntegrationOutboxEvent::query()->where('organization_id',$org)->where('event_type','grn.posted')->where('aggregate_id',$grn->id)
            ->where('idempotency_key',$source['receipt_journal_key'])->firstOrFail();
        abort_unless($source['receipt_currency_code']===data_get($event->payload,'currency.code')
            && Decimal::gt((string)$source['receipt_exchange_rate'],'0')
            && Decimal::cmp((string)$source['receipt_exchange_rate'],(string)data_get($event->payload,'currency.exchange_rate','0'),12)===0,409);
        self::activeJournal($db->table('journal_entries')->where('organization_id',$map->finance_organization_id)
            ->where('source_key','external-api:'.hash('sha256',$source['receipt_journal_key']))->lockForUpdate()->first());
        $ledger=$db->table('stock_ledger')->where('organization_id',$org)->where('source_type',GoodsReceipt::class)->where('source_id',$grn->id)
            ->where('source_line_id',$line->id)->where('direction','in')->orderBy('id')->lockForUpdate()->get();
        abort_unless($ledger->isNotEmpty(),409);$cohort='0';
        foreach($ledger as$movement){
            abort_unless(Decimal::gt((string)$movement->quantity,'0') && (int)$movement->item_id===(int)$line->item_id
                && (int)$movement->warehouse_id===(int)$grn->warehouse_id,409);
            $cohort=Decimal::add($cohort,(string)$movement->quantity,8);
        }
        $factor=(string)$line->unit_conversion_factor;$base=Decimal::mul((string)$source['quantity'],$factor,8);
        abort_unless(Decimal::gt($factor,'0') && !empty($line->unit_conversion_hash) && Decimal::gt($base,'0')
            && Decimal::cmp((string)$line->accepted_qty,$cohort,8)===0
            && Decimal::cmp($base,$cohort,8)<=0,409);
        // A bill may settle a subset of one receipt line. The durable settlement scope supplies that subset;
        // the shared native ledger explains the whole cohort. Reject cumulative claims beyond the physical cohort.
        $claims=$db->table('finance_purchase_settlements')->where('organization_id',$map->finance_organization_id)
            ->where('organization_mapping_uuid',$map->mapping_uuid)->where('receipt_mapping_uuid',$source['receipt_mapping_uuid'])
            ->where('receipt_id',$grn->id)->where('receipt_line_id',$line->id)->where('state','settled')->orderBy('id')->lockForUpdate()->get();
        $claimed='0';foreach($claims as$claim){
            abort_unless(Decimal::gt((string)$claim->quantity,'0'),409);
            $claimed=Decimal::add($claimed,Decimal::mul((string)$claim->quantity,$factor,8),8);
        }
        abort_unless(Decimal::cmp($claimed,$cohort,8)<=0,409);
        foreach(['receipt_net_credit_amount','receipt_nonrecoverable_tax_credit_amount']as$key)abort_unless(preg_match('/^\d+(?:\.\d{1,12})?$/D',(string)$source[$key])===1,409);
        // Credit scope amounts are stored Bill-currency amounts; dated receipt FX authenticates the receipt, not the invoice carrying value.
        $delta=Decimal::sub('0',Decimal::round(Decimal::div(Decimal::add((string)$source['receipt_net_credit_amount'],(string)$source['receipt_nonrecoverable_tax_credit_amount'],12),$invoiceRate,12),$scale),8);
        $result=[];$quantityLeft=$base;$deltaLeft=$delta;$last=$ledger->count()-1;
        foreach($ledger as$index=>$movement){
            // Durable native line cohort is distributed by actual ledger quantity; the final member absorbs only arithmetic residuals.
            $quantity=$index===$last?$quantityLeft:Decimal::mul($base,Decimal::div((string)$movement->quantity,$cohort,12),8);
            $value=$index===$last?$deltaLeft:Decimal::mul($delta,Decimal::div((string)$movement->quantity,$cohort,12),8);
            $quantityLeft=Decimal::sub($quantityLeft,$quantity,8);$deltaLeft=Decimal::sub($deltaLeft,$value,8);
            abort_unless(Decimal::gt($quantity,'0') && Decimal::cmp($quantity,(string)$movement->quantity,8)<=0,409);
            $result[]=['settlement_uuid'=>$source['settlement_uuid'],'receipt_id'=>(int)$grn->id,'receipt_line_id'=>(int)$line->id,'stock_ledger_id'=>(int)$movement->id,'position_uuid'=>$position->position_uuid,
                'quantity_base'=>$quantity,'price_delta_base'=>$value,'item_id'=>(int)$movement->item_id,'warehouse_id'=>(int)$movement->warehouse_id,
                'variant_id'=>$movement->variant_id?(int)$movement->variant_id:null,'lot_id'=>$movement->lot_id?(int)$movement->lot_id:null,
                'bin_id'=>$movement->bin_id?(int)$movement->bin_id:null];
        }
        abort_unless(Decimal::isZero($quantityLeft,8) && Decimal::isZero($deltaLeft,8),409);
        return $result;
    }

    private static function activeJournal(?object $row):void {abort_unless($row && $row->status==='posted' && !empty($row->posted_at) && empty($row->voided_at) && empty($row->deleted_at),409);}
}
