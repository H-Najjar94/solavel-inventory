<?php
namespace App\Services\Returns;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** An additional receipt match is admitted only for an actual credited return's native physical inverse. */
final class SupplierReturnInverseOriginProof
{
    public function assertLocked(array $origin,array $facts,object $mapping): array
    {
        $db=DB::connection('tenant');$org=(int)$mapping->finance_organization_id;$stock=(int)$mapping->solastock_organization_id;
        abort_unless(($origin['version']??null)==='purchase-return-inverse.v1',403);
        $uuid=\Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL,$facts['position_uuid'].'|'.$facts['receipt_mapping_uuid'].'|'.$facts['receipt_line_id'].'|return_inverse|'.($origin['stock_reversal_id']??0).'|'.($origin['credit_allocation_id']??0))->toString();
        abort_unless(hash_equals($uuid,(string)$facts['settlement_uuid']),403);
        foreach(['proof_id','stock_return_id','stock_reversal_id','credit_allocation_id','original_note_journal_id','bridge_journal_id','bridge_reversal_journal_id']as$id)abort_unless((int)($origin[$id]??0)>0,403);
        $proof=$db->table('finance_supplier_return_reversals')->where('organization_id',$org)->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('id',$origin['proof_id'])->where('return_mapping_uuid',$origin['return_mapping_uuid']??'')->where('stock_return_id',$origin['stock_return_id'])
            ->where('stock_reversal_id',$origin['stock_reversal_id'])->where('voided_note_journal_id',$origin['original_note_journal_id'])->lockForUpdate()->first();
        abort_unless($proof&&hash_equals((string)$proof->source_hash,(string)($origin['source_hash']??'')),403);
        $payload=json_decode($proof->payload,true,512,JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($proof->source_hash,hash('sha256',SolaStockJournalContract::canonicalJson($payload))),403);
        $source=$db->table('finance_supplier_returns')->where('organization_id',$org)->where('id',$proof->supplier_return_id)
            ->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('return_mapping_uuid',$proof->return_mapping_uuid)
            ->where('stock_return_id',$proof->stock_return_id)->where('bridge_journal_id',$origin['bridge_journal_id'])->lockForUpdate()->first();
        abort_unless($source&&(int)$source->bill_id===(int)$facts['source_bill_id']&&(int)$source->reviewed_bill_journal_id===(int)$facts['bill_journal_id'],403);
        $claim=$db->table('finance_supplier_return_credit_allocations')->where('organization_id',$org)->where('id',$origin['credit_allocation_id'])
            ->where('supplier_return_id',$source->id)->where('bill_id',$facts['source_bill_id'])->where('bill_journal_id',$facts['bill_journal_id'])
            ->where('position_uuid',$facts['position_uuid'])->where('debit_note_id',$proof->debit_note_id)->where('journal_entry_id',$proof->voided_note_journal_id)
            ->where('branch','bridged_unmatched')->where('state','voided')->lockForUpdate()->first();
        abort_unless($claim&&Decimal::cmp((string)$claim->quantity,(string)$facts['quantity'],8)===0,403);
        $line=$db->table('finance_supplier_return_lines')->where('organization_id',$org)->where('id',$claim->supplier_return_line_id)
            ->where('supplier_return_id',$source->id)->where('source_receipt_line_id',$facts['receipt_line_id'])->first();
        abort_unless($line&&(int)$line->source_stock_ledger_id>0,403);
        $return=$db->table('supplier_returns')->where('organization_id',$stock)->where('id',$proof->stock_return_id)
            ->where('goods_receipt_id',$facts['receipt_id'])->where('reversal_id',$proof->stock_reversal_id)->where('status','reversed')->whereNotNull('reversed_at')->first();
        $inverse=$db->table('inventory_reversals')->where('organization_id',$stock)->where('id',$proof->stock_reversal_id)
            ->where('source_type','supplier_return')->where('source_id',$proof->stock_return_id)->where('status','posted')->whereNotNull('posted_at')->first();
        abort_unless($return&&$inverse&&$inverse->original_event_uuid===$proof->original_event_uuid,403);
        $nativeLine=$db->table('supplier_return_lines')->where('organization_id',$stock)->where('supplier_return_id',$return->id)
            ->where('goods_receipt_line_id',$facts['receipt_line_id'])->where('source_stock_ledger_id',$line->source_stock_ledger_id)->first();
        abort_unless($nativeLine&&Decimal::cmp((string)$nativeLine->entered_qty,(string)$facts['quantity'],8)===0,403);
        $physical=$db->table((new \App\Models\Tenant\StockLedger)->getTable())->where('organization_id',$stock)->where('source_type','App\\Models\\Tenant\\InventoryReversal')
            ->where('source_id',$inverse->id)->where('source_line_id',$nativeLine->id)->where('direction','in')->where('item_id',$nativeLine->item_id)->where('warehouse_id',$nativeLine->warehouse_id)->get();
        $restored='0';$cost='0';foreach($physical as$row){$restored=Decimal::add($restored,(string)$row->quantity,8);$cost=Decimal::add($cost,(string)$row->total_cost,8);}
        abort_unless(Decimal::cmp($restored,(string)$nativeLine->quantity,8)===0,403);
        $void=$db->table('journal_entries')->where('organization_id',$org)->where('id',$proof->voided_note_journal_id)
            ->where('source','NOTE')->where('source_type','App\\Models\\DebitNote')->where('source_id',$proof->debit_note_id)
            ->whereNotNull('voided_at')->where('status','voided')->first();
        abort_unless($void&&$db->table('action_logs')->where('controller','ReversalEngine')->where('method','voidDebitNote')
            ->where('user_id',$void->voided_by)->where('data->document_type','App\\Models\\DebitNote')->where('data->document_id',$proof->debit_note_id)->exists(),403);
        abort_unless($this->journal($org,(int)$proof->original_out_journal_id)&&$this->journal($org,(int)$proof->inverse_import_journal_id),403);
        $bridge=$this->journal($org,(int)$origin['bridge_journal_id']);
        $mirror=$this->journal($org,(int)$origin['bridge_reversal_journal_id']);
        abort_unless($bridge&&$mirror&&$mirror->source==='AP'&&$mirror->source_type==='App\\Services\\Integration\\FinanceSupplierReturnReceiver'
            &&(int)$mirror->source_id===(int)$source->id&&$mirror->source_key==='supplier-return-unbilled-reversal:'.$source->return_mapping_uuid,403);
        abort_unless($this->vectors($bridge->id,true)===$this->vectors($mirror->id,false),403);
        abort_unless(isset($facts['settlement_date'])&&substr((string)$inverse->reversal_date,0,10)===$facts['settlement_date'],403);
        return ['settlement_uuid'=>$facts['settlement_uuid'],'organization_id'=>$stock,'item_id'=>(int)$nativeLine->item_id,'warehouse_id'=>(int)$nativeLine->warehouse_id,'inverse_id'=>(int)$inverse->id,'return_id'=>(int)$return->id,'return_line_id'=>(int)$nativeLine->id,'ledger_ids'=>$physical->pluck('id')->map(fn($id)=>(int)$id)->all(),'quantity'=>$restored,'base_cost'=>$cost];
    }
    private function journal(int $org,int $id):?object
    {return DB::connection('tenant')->table('journal_entries')->where('organization_id',$org)->where('id',$id)->where('status','posted')->whereNotNull('posted_at')->whereNull('voided_at')->whereNull('deleted_at')->first();}
    private function vectors(int $id,bool $inverse):array
    {
        $vectors=[];foreach(DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id',$id)->get()as$line){
            $key=implode('|',[(int)$line->account_id,(string)$line->currency_code,Decimal::round((string)$line->exchange_rate,12)]);
            $signed=Decimal::sub((string)$line->debit,(string)$line->credit,8);
            $base=Decimal::sub((string)$line->base_debit,(string)$line->base_credit,8);
            if($inverse){$signed=Decimal::sub('0',$signed,8);$base=Decimal::sub('0',$base,8);}
            $vectors[$key]=Decimal::add($vectors[$key]??'0',$signed,8);
            $vectors[$key.'|base']=Decimal::add($vectors[$key.'|base']??'0',$base,8);
        }ksort($vectors);return$vectors;
    }
}
