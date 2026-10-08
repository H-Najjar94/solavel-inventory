<?php
namespace App\Services\Returns;
use App\Models\Tenant\{GoodsReceipt,SupplierReturn,SupplierReturnLine,StockLedger,StockBalance};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
/** Independent admission of immutable unbilled returns excluded from acquisition matching. */
final class UnbilledReturnCostCohortProof
{
 public function verify(array $snapshot,array $authority,object $mapping,GoodsReceipt $receipt,object $line):array
 {
  abort_unless(($authority['unbilled_return_exclusions']??null)===$snapshot&&($snapshot['version']??null)==='purchase-unbilled-return-exclusions.v1'
   &&$snapshot['receipt_mapping_uuid']===$authority['receipt_mapping_uuid']&&(int)$snapshot['receipt_line_id']===(int)$line->id,403);
  $unsigned=$snapshot;unset($unsigned['snapshot_hash']);abort_unless(hash_equals(hash('sha256',SolaStockJournalContract::canonicalJson($unsigned)),(string)$snapshot['snapshot_hash']),403);
  $db=DB::connection('tenant');$excluded='0';$outIds=[];$seen=[];
  foreach($snapshot['claims']as$claim){
   abort_unless(!isset($seen[$claim['source_line_id']]),403);$seen[$claim['source_line_id']]=true;
   $source=$db->table('finance_supplier_returns')->where('organization_id',$mapping->finance_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('id',$claim['source_id'])->lockForUpdate()->first();
   $nativeLine=$db->table('finance_supplier_return_lines')->where('organization_id',$mapping->finance_organization_id)->where('supplier_return_id',$source?->id)->where('id',$claim['source_line_id'])->lockForUpdate()->first();
   abort_unless($source&&$nativeLine&&$source->receipt_mapping_uuid===$snapshot['receipt_mapping_uuid']&&$source->return_mapping_uuid===$claim['return_mapping_uuid']
    &&hash_equals($source->source_hash,$claim['source_hash'])&&(int)$source->bridge_journal_id===(int)$claim['bridge_journal_id']&&(int)$source->stock_return_id===(int)$claim['stock_return_id']
    &&(int)$nativeLine->source_receipt_line_id===(int)$line->id&&(int)$nativeLine->source_return_line_id===(int)$claim['stock_return_line_id']
    &&(int)$nativeLine->source_stock_ledger_id===(int)$claim['source_stock_ledger_id']&&Decimal::cmp((string)$nativeLine->entered_quantity,$claim['entered_quantity'],8)===0,403);
   abort_unless(hash_equals($source->source_hash,hash('sha256',SolaStockJournalContract::canonicalJson(json_decode($source->payload,true,512,JSON_THROW_ON_ERROR)))),403);
   abort_unless($db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$claim['bridge_journal_id'])->where('source_key','supplier-return-unbilled:'.$claim['return_mapping_uuid'])->where('status','posted')->whereNull('voided_at')->whereNull('deleted_at')->exists(),403);
   $return=SupplierReturn::query()->withoutGlobalScope('warehouse_access')->where('organization_id',$receipt->organization_id)->whereKey($claim['stock_return_id'])->lockForUpdate()->firstOrFail();
   $returnLine=SupplierReturnLine::query()->where('organization_id',$receipt->organization_id)->where('supplier_return_id',$return->id)->whereKey($claim['stock_return_line_id'])->firstOrFail();
   abort_unless($return->status==='posted'&&!$return->reversed_at&&(int)$return->warehouse_id===(int)$receipt->warehouse_id&&(int)$returnLine->item_id===(int)$line->item_id&&(int)$return->goods_receipt_id===(int)$receipt->id&&(int)$returnLine->goods_receipt_line_id===(int)$line->id
    &&(int)$returnLine->source_stock_ledger_id===(int)$claim['source_stock_ledger_id']&&Decimal::cmp((string)$returnLine->entered_qty,$claim['entered_quantity'],8)===0,403);
   $out=StockLedger::query()->withoutGlobalScope('warehouse_access')->where('organization_id',$receipt->organization_id)->where('source_type',SupplierReturn::class)->where('source_id',$return->id)->where('source_line_id',$returnLine->id)->where('direction','out')->lockForUpdate()->get();
   $outQuantity='0';foreach($out as$row)$outQuantity=Decimal::add($outQuantity,(string)$row->quantity,8);abort_unless($out->isNotEmpty()&&Decimal::cmp($outQuantity,(string)$returnLine->quantity,8)===0,403);
   $excluded=Decimal::add($excluded,(string)$returnLine->quantity,8);$outIds=array_merge($outIds,$out->pluck('id')->all());
  }
  abort_unless($seen&&Decimal::gt($excluded,'0',8),403);
  $original=StockLedger::query()->withoutGlobalScope('warehouse_access')->where('organization_id',$receipt->organization_id)->where('source_type',GoodsReceipt::class)->where('source_id',$receipt->id)->where('source_line_id',$line->id)->where('direction','in')->lockForUpdate()->get();
  abort_unless($original->count()===1,409,'Tracked receipt cohorts require an explicit reviewed exclusion allocation.');$in=$original->first();foreach($snapshot['claims']as$claim)abort_unless((int)$claim['source_stock_ledger_id']===(int)$in->id,403);
  $eligible=Decimal::sub((string)$in->quantity,$excluded,8);abort_unless(Decimal::gt($eligible,'0',8)&&Decimal::cmp((string)$authority['quantity'],Decimal::div($eligible,(string)($line->unit_conversion_factor?:'1'),8),8)<=0,403);
  $later=StockLedger::query()->withoutGlobalScope('warehouse_access')->where('organization_id',$receipt->organization_id)->where('item_id',$line->item_id)->where('warehouse_id',$receipt->warehouse_id)->where('id','>',$in->id)->pluck('id')->all();sort($later);sort($outIds);abort_unless($later===$outIds,409,'Other physical dispositions require a reviewed unbilled-return cost cohort.');
  $balance=StockBalance::query()->withoutGlobalScope('warehouse_access')->where('organization_id',$receipt->organization_id)->where('item_id',$line->item_id)->where('warehouse_id',$receipt->warehouse_id)->lockForUpdate()->get();abort_unless($balance->count()===1&&Decimal::cmp((string)$balance->first()->on_hand_qty,$eligible,8)===0,409);
  return ['settlement_uuid'=>$authority['settlement_uuid'],'organization_id'=>$receipt->organization_id,'receipt_ledger_id'=>$in->id,'eligible_quantity'=>$eligible,'snapshot_hash'=>$snapshot['snapshot_hash']];
 }
}
