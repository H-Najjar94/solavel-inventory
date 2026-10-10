<?php
namespace Tests\Support;
use App\Models\Tenant\{FinancialOriginRequest,Shipment,StockLedger};
use App\Services\FinancialOrigins\{OriginRequestService,OriginDispatchService,CashPartialReturnService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Actual Stock-only native actor and physical services on the sealed shared SQL fixture. */
final class Cash219NativeStockPairSteps
{
 public static function run(string $step,array $state):array {
  if(!app()->environment('testing')||DB::connection('tenant')->getDatabaseName()!=='tenant_000100'||DB::connection('tenant')->transactionLevel()!==0)throw new \LogicException('Sealed native pair only.');
  $org=(int)$state['organization_id'];$actor=in_array($step,['opening','approve','return','return-replay'],true)?17003:(int)$state['warehouse_actor_id'];
  $user=\App\Models\User::findOrFail($actor);\Illuminate\Support\Facades\Auth::setUser($user);request()->setUserResolver(fn()=>$user);
  $access=app(\App\Services\Access\CentralAppAccess::class);
  if(($access->decision($actor,$org,'inventory')['allowed']??false)!==true||($access->decision($actor,$org,'finance')['allowed']??false)===true)throw new \LogicException('Actual Stock-only native actor required.');
  if($step==='opening'){
   app(\App\Services\Access\WarehouseAccessService::class)->assertAllowed((int)$state['warehouse_id']);
   if(!app(\App\Services\Access\InventoryPermissionService::class)->can($user,'inventory.manage_opening_stock'))throw new \LogicException('Native opening-stock authority required.');
   $service=app(\App\Services\Documents\OpeningStockService::class);
   $entry=$service->createDraft(['warehouse_id'=>$state['warehouse_id'],'opening_date'=>'2024-06-13','notes'=>'Private Cash219 initial stock'],
    [['item_id'=>$state['item_id'],'entered_unit_id'=>$state['unit_id'],'quantity'=>'10','unit_cost'=>'2']]);
   $entry=$service->post($entry);return['opening_stock_id'=>$entry->id,'status'=>$entry->status];
  }
  $r=FinancialOriginRequest::query()->where('organization_id',$org)->where('request_uuid',$state['request_uuid'])->where('source_document_type','sales_receipt')->sole();
  $context=['request_uuid'=>$r->request_uuid,'source_document_type'=>'sales_receipt','source_document_id'=>$r->source_document_id,'source_journal_id'=>$r->source_journal_id,'request_revision'=>$r->source_revision];
  if($step==='approve')return app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$state['warehouse_id']],$actor);
  if(in_array($step,['dispatch','dispatch-replay','dispatch-held-denial'],true)){
   $line=$r->lines()->sole();
   $op=$context+['operation_uuid'=>$state['dispatch_uuid'],'warehouse_id'=>$state['warehouse_id'],'physical_date'=>'2024-06-14',
    'lines'=>[['request_line_id'=>$line->id,'source_document_line_id'=>$line->source_document_line_id,'quantity'=>'2','unit_id'=>$line->unit_id]]];
   $service=app(OriginDispatchService::class);
   if($step==='dispatch-held-denial'){
    $op['operation_uuid']=(string)Str::uuid();$before=DB::connection('tenant')->table('stock_financial_origin_commands')->where('organization_id',$org)->count();
    try{$service->prepareNative($op,$actor);throw new \LogicException('Prepared Cash refund failed to fence new dispatch.');}
    catch(\Illuminate\Validation\ValidationException $e){if(!isset($e->errors()['workflow']))throw$e;}
    if($before!==DB::connection('tenant')->table('stock_financial_origin_commands')->where('organization_id',$org)->count())throw new \LogicException('Denied dispatch persisted a command.');
    return['denied'=>true,'command_count_unchanged'=>true];
   }
   $service->prepareNative($op,$actor);return$service->executeNative($op,$actor);
  }
  if($step==='return'||$step==='return-replay'){
   $shipment=Shipment::query()->where('organization_id',$org)->findOrFail($state['shipment_id']);$line=$shipment->lines()->sole();
   $ledger=StockLedger::query()->where('organization_id',$org)->where('source_type',Shipment::class)->where('source_id',$shipment->id)->where('source_line_id',$line->id)->where('direction','out')->sole();
   $data=['operation_uuid'=>$state['return_uuid'],'shipment_id'=>$shipment->id,'return_number'=>'CASH-RET-QUAL-'.$shipment->id,'return_date'=>'2024-06-15','reason'=>'Private native partial physical return',
    'lines'=>[['source_line_id'=>$line->id,'source_stock_ledger_id'=>$ledger->id,'item_id'=>$line->item_id,'returned_qty'=>'1','entered_qty'=>'1','entered_unit_id'=>$line->entered_unit_id,'condition'=>'resellable']]];
   $service=app(CashPartialReturnService::class);$return=$service->createDraft($data,$actor);$return=$service->post($return,$actor);
   return['sales_return_id'=>$return->id,'status'=>$return->status];
  }
  if($step!=='evidence')throw new \InvalidArgumentException('Unknown native Cash pair step.');
  $db=DB::connection('tenant');$result=['request'=>app(OriginRequestService::class)->summary($r),'request_lines'=>$r->lines()->orderBy('id')->get()->toArray()];
  foreach(['stock_ledger','stock_cash_refund_demands','stock_cash_partial_returns','stock_financial_origin_commands','stock_financial_origin_outbox']as$table)
   $result[$table]=$db->table($table)->where('organization_id',$org)->orderBy('id')->get()->toArray();
  return$result;
 }
}
