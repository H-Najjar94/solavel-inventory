<?php
namespace Tests\Support;
use App\Models\Tenant\{FulfillmentRequest,SalesOrder,Shipment};
use Illuminate\Support\Facades\{DB,Auth};
/** Native operations only. Finance owns credit prepare/post/void and sends real signed commands. */
final class CustomerCreditNativeStockSteps
{
 public static function run(string $step,array $metadata,array $stock,array $args=[]):array
 {
  $db=DB::connection('tenant');$org=(int)($metadata['central_organization_id']??0);
  if(PHP_SAPI!=='cli'||!str_starts_with(base_path(),'/qualification/stock')||!app()->environment('testing')||$org<1
   ||$db->getDatabaseName()!=='tenant_000100'||$db->selectOne('SELECT CURRENT_USER() AS u')->u!=='t_000100@localhost'||$db->transactionLevel()!==0)
   throw new \LogicException('Isolated native Stock selector required');
  app(\App\Tenancy\OrganizationContext::class)->set($org);
  if($step==='seed'){
   $result=SupplierCreditNativeStockFixture::seed($metadata,'average');
   $setting=\App\Models\Tenant\IntegrationSetting::query()->where('organization_id',$org)->where('integration','solabooks')->sole();
   $meta=(array)$setting->meta;$meta['transport_enabled_workflows']=['grn.posted','grn.reversed','shipment.posted','shipment.reversed','sales_return.posted'];$setting->meta=$meta;$setting->save();
   return$result;
  }
  if((int)($stock['organization_id']??0)!==$org)throw new \LogicException('Native Stock fixture identity mismatch');
  if($step==='snapshot')return CustomerCreditNativeStockEvidence::snapshot($org);
  if($step==='journal'){$transport=app(\App\Services\Integration\DurableOutboxTransportService::class);$claim=$transport->claim($org,'private-customer-credit-native');return $claim?$transport->processClaim($claim):['status'=>'no_eligible_event'];}
  if($step==='documents')return ['receipt_delivered'=>app(\App\Services\Purchasing\ReceiptHandoffService::class)->deliverDue(1),'shipment_delivered'=>app(\App\Services\Sales\ShipmentHandoffService::class)->deliverDue(1)];
  if(!in_array($step,['receive','approve','dispatch','return'],true))throw new \LogicException('Unsupported closed Stock selector');
  $actorId=$step==='receive'?(int)$metadata['warehouse_actor_id']:17003;
  $actor=\App\Models\User::findOrFail($actorId);Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
  $access=app(\App\Services\Access\CentralAppAccess::class);
  if(($access->decision($actorId,$org,'inventory')['allowed']??false)!==true||($access->decision($actorId,$org,'finance')['allowed']??false)===true)
   throw new \LogicException('Actual warehouse-only canonical access required');
  $permissions=app(\App\Services\Access\InventoryPermissionService::class);
  if($step==='receive')return SupplierCreditNativeStockFixture::receive($org,(int)$metadata['finance_bill_id'],(int)$stock['warehouse_id'],(string)$args['quantity'],(string)$args['date']);
  if($step==='return'){
   if(!$permissions->can($actor,'inventory.manage_returns'))throw new \LogicException('Actual native return permission required');
   $shipment=Shipment::query()->where('organization_id',$org)->whereKey((int)$args['shipment_id'])->where('status','posted')->firstOrFail();
   $line=$shipment->lines()->whereKey((int)$args['shipment_line_id'])->firstOrFail();
   $native=app(\App\Services\Documents\SalesReturnService::class);
   $return=$native->createDraft(['shipment_id'=>$shipment->id,'return_date'=>(string)$args['date'],'reason'=>'Private actual customer partial return'],
    [['source_line_id'=>$line->id,'item_id'=>$line->item_id,'entered_unit_id'=>$line->entered_unit_id,'returned_qty'=>(string)$args['quantity'],'condition'=>'resellable']]);
   $return=$native->authorizeReturn($return);$return=$native->post($return);
   return['sales_return_id'=>$return->id,'return_number'=>$return->return_number,'shipment_id'=>$shipment->id,'status'=>$return->status];
  }
  $request=FulfillmentRequest::query()->where('organization_id',$org)->where('source_invoice_id',(int)$args['finance_invoice_id'])->where('status','!=','cancelled')->sole();
  if($request->source_status!=='posted'||(int)$request->posted_invoice_journal_id<1)throw new \LogicException('Actually posted native invoice request required');
  $service=app(\App\Services\Sales\FulfillmentRequestService::class);
  if($step==='approve')return $service->approve($request,(int)$stock['warehouse_id']);
  foreach(['inventory.manage_sales_orders','inventory.manage_reservations','inventory.manage_shipments'] as$permission)
   if(!$permissions->can($actor,$permission))throw new \LogicException('Actual native dispatch permission required');
  if(!$request->approved_at||!$request->sales_order_id)throw new \LogicException('Explicit prior native approval required');
  $qty=(string)($args['quantity']??'');$date=(string)($args['date']??'');
  if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$qty)||bccomp($qty,'0',4)<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new \LogicException('Explicit positive quantity/date required');
  $order=SalesOrder::query()->where('organization_id',$org)->whereKey($request->sales_order_id)->firstOrFail();
  app(\App\Services\Documents\SalesOrderService::class)->reserve($order);
  $line=$request->lines()->where('source_line_id',(string)$args['source_invoice_line_id'])->sole();
  $source=$order->lines()->whereKey($line->sales_order_line_id)->firstOrFail();
  $shipments=app(\App\Services\Documents\ShipmentService::class);
  $shipment=$shipments->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$stock['warehouse_id'],'ship_date'=>$date],
   [['sales_order_line_id'=>$source->id,'item_id'=>$source->item_id,'entered_unit_id'=>$source->entered_unit_id,'quantity'=>$qty]]);
  $shipment=$shipments->post($shipment);
  return ['fulfillment_request_id'=>$request->id,'sales_order_id'=>$order->id,'shipment_id'=>$shipment->id,'shipment_number'=>$shipment->shipment_number,'shipment_line_ids'=>$shipment->lines()->pluck('id')->all(),'status'=>$shipment->status,'request'=>$service->status($request->fresh())];
 }
}
