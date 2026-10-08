<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginRequest,SalesReturn,Shipment,StockLedger};
use App\Services\Documents\SalesReturnService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Actual native physical return only. The original sale, payment, VAT and revenue remain untouched. */
final class CashPartialReturnService {
 public function createDraft(array $data,int $actor):SalesReturn {
  $data=validator($data,['operation_uuid'=>'required|uuid','shipment_id'=>'required|integer|min:1','return_number'=>'sometimes|string|max:50','notes'=>'sometimes|nullable|string','return_date'=>'required|date_format:Y-m-d','reason'=>'required|string|min:3|max:1000','lines'=>'required|array|min:1','lines.*.source_line_id'=>'required|integer|min:1','lines.*.source_stock_ledger_id'=>'required|integer|min:1','lines.*.item_id'=>'required|integer|min:1','lines.*.returned_qty'=>'required|numeric|gt:0','lines.*.entered_qty'=>'sometimes|nullable|numeric|gt:0','lines.*.entered_unit_id'=>'required|integer|min:1','lines.*.condition'=>'required|in:resellable,quarantine,damaged,retired'])->validate();
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()===0,409);$org=app(OrganizationContext::class)->idOrFail();
  $shipment=Shipment::query()->where('organization_id',$org)->whereKey($data['shipment_id'])->firstOrFail();
  $command=FinancialOriginCommand::query()->where('organization_id',$org)->where('shipment_id',$shipment->id)->where('status','completed')->where('source_document_type','sales_receipt')->firstOrFail();
  $request=FinancialOriginRequest::query()->where('organization_id',$org)->where('request_uuid',$command->request_uuid)->where('source_document_type','sales_receipt')->firstOrFail();
  OriginSourceAdmission::stock($request,$actor,'inventory.manage_returns');
  $context=app(OriginPhysicalService::class)->beforeReverse($shipment);abort_unless($context instanceof OriginCashPhysicalReversalContext,409);
  $admission=CashPartialReturnAdmission::fromNativeSource($shipment,$context);$hash=SolaStockJournalContract::payloadHash($data);
  return$db->transaction(function()use($db,$org,$actor,$shipment,$data,$admission,$hash){
   $request=$admission->assertSource($shipment);
   $existing=$db->table('stock_cash_partial_returns')->where('organization_id',$org)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   if($existing){abort_unless($existing->payload_hash===$hash&&(int)$existing->actor_id===$actor,409);return SalesReturn::query()->where('organization_id',$org)->whereKey($existing->sales_return_id)->firstOrFail();}
   $lines=[];$requestedByLedger=[];foreach($data['lines']as$input){
    $source=$shipment->lines()->whereKey($input['source_line_id'])->lockForUpdate()->firstOrFail();
    $ledger=StockLedger::query()->where('organization_id',$org)->where('source_type',Shipment::class)->where('source_id',$shipment->id)->where('source_line_id',$source->id)->where('direction','out')->whereKey($input['source_stock_ledger_id'])->lockForUpdate()->firstOrFail();
    $base=Decimal::mul((string)($input['entered_qty']??$input['returned_qty']),(string)$source->unit_conversion_factor);
    abort_unless(Decimal::cmp(Decimal::qty($base),$base)===0&&(int)$input['entered_unit_id']===(int)$source->entered_unit_id&&(int)$input['item_id']===(int)$source->item_id,409);
    $prior=$db->table('sales_return_lines as l')->join('sales_returns as r','r.id','=','l.sales_return_id')->where('l.organization_id',$org)->where('l.source_stock_ledger_id',$ledger->id)->whereNull('r.deleted_at')->where('r.status','!=','cancelled')->lockForUpdate()->get(['l.returned_qty'])->reduce(fn($sum,$row)=>Decimal::add($sum,(string)$row->returned_qty),'0');
    $requestedByLedger[$ledger->id]=Decimal::add($requestedByLedger[$ledger->id]??'0',$base);
    abort_unless(Decimal::cmp(Decimal::add($prior,$requestedByLedger[$ledger->id]),(string)$ledger->quantity)<=0,409);
    $lines[]=$input+['unit_conversion_factor'=>$source->unit_conversion_factor];
   }
   $return=app(SalesReturnService::class)->createDraft(array_filter(['return_number'=>$data['return_number']??null,'notes'=>$data['notes']??null,'shipment_id'=>$shipment->id,'customer_id'=>$shipment->customer_id,'return_date'=>$data['return_date'],'reason'=>$data['reason']],fn($value)=>$value!==null),$lines,$admission);
   $db->table('stock_cash_partial_returns')->insert(['organization_id'=>$org,'organization_mapping_uuid'=>$request->organization_mapping_uuid,'operation_uuid'=>$data['operation_uuid'],'actor_id'=>$actor,'request_id'=>$request->id,'shipment_id'=>$shipment->id,'sales_return_id'=>$return->id,'payload_hash'=>$hash,'payload'=>SolaStockJournalContract::canonicalJson($data),'state'=>'draft','created_at'=>now(),'updated_at'=>now()]);return$return;
  },3);
 }
 public function post(SalesReturn $return,int $actor):SalesReturn {
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()===0,409);$org=app(OrganizationContext::class)->idOrFail();abort_unless((int)$return->organization_id===$org,403);
  $operation=$db->table('stock_cash_partial_returns')->where('organization_id',$org)->where('sales_return_id',$return->id)->first();abort_unless($operation,404);
  $shipment=Shipment::query()->where('organization_id',$org)->whereKey($operation->shipment_id)->firstOrFail();$request=FinancialOriginRequest::query()->where('organization_id',$org)->whereKey($operation->request_id)->firstOrFail();
  OriginSourceAdmission::stock($request,$actor,'inventory.manage_returns');$context=app(OriginPhysicalService::class)->beforeReverse($shipment);abort_unless($context instanceof OriginCashPhysicalReversalContext,409);
  $admission=CashPartialReturnAdmission::fromNativeSource($shipment,$context);
  return$db->transaction(function()use($db,$org,$return,$operation,$admission){
   $admission->lockAndValidate($return);$saved=$db->table('stock_cash_partial_returns')->where('organization_id',$org)->where('id',$operation->id)->lockForUpdate()->first();
   abort_unless($saved&&$saved->payload_hash===SolaStockJournalContract::payloadHash(json_decode($saved->payload,true,512,JSON_THROW_ON_ERROR)),409);
   $posted=app(SalesReturnService::class)->post($return,$admission);
   // Native sales_return.posted outbox owns Inventory/COGS. No recognition or payment reversal is emitted here.
   $db->table('stock_cash_partial_returns')->where('id',$saved->id)->update(['state'=>'posted','updated_at'=>now()]);return$posted;
  },3);
 }
 public function cancel(SalesReturn $return,int $actor):SalesReturn {
  $db=DB::connection('tenant');$org=app(OrganizationContext::class)->idOrFail();abort_unless((int)$return->organization_id===$org,403);
  $operation=$db->table('stock_cash_partial_returns')->where('organization_id',$org)->where('sales_return_id',$return->id)->first();abort_unless($operation,404);
  $request=FinancialOriginRequest::query()->where('organization_id',$org)->whereKey($operation->request_id)->firstOrFail();$admission=OriginSourceAdmission::stock($request,$actor,'inventory.manage_returns');
  return$db->transaction(function()use($db,$admission,$request,$return,$operation){
   $admission->lock($request);$cancelled=app(SalesReturnService::class)->cancel($return);
   $db->table('stock_cash_partial_returns')->where('id',$operation->id)->update(['state'=>'cancelled','updated_at'=>now()]);return$cancelled;
  },3);
 }

}
