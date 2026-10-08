<?php
namespace Tests\Support;
use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationMasterDataMapping,IntegrationSetting,IntegrationAccountMapping,Unit,Item,Warehouse,InventoryUserWarehouse,ReceivingRequest};
use App\Services\Documents\{GoodsReceiptService,OperationalReceiving};
use App\Services\Purchasing\ReceivingRequestService;
use Illuminate\Support\Facades\{DB,Crypt};
/** Actual native Stock metadata/receiving fixture, used only in the root's private signed pair. */
final class SupplierCreditNativeStockFixture
{
 private static function isolated(int $org):void {
  if(PHP_SAPI!=='cli' || !str_starts_with(base_path(),'/qualification/stock') || !app()->environment('testing')
   || $org<1 || DB::connection('tenant')->getDatabaseName()!=='tenant_000100'
   || DB::connection('tenant')->selectOne('SELECT CURRENT_USER() AS actual_user')->actual_user!=='t_000100@localhost')
   throw new \LogicException('Private native Stock pair only');
  app(\App\Tenancy\OrganizationContext::class)->set($org);
 }
 /** Finance returns real native chart/item/mapping/key facts; no placeholder financial documents are created. */
 public static function seed(array $seed,string $costingMethod='average'):array {
  $org=(int)$seed['central_organization_id'];self::isolated($org);
  if(!in_array($costingMethod,['average','fifo'],true))throw new \LogicException('Native costing method required');
  $map=IntegrationOrganizationMapping::query()->where('mapping_uuid',$seed['organization_mapping_uuid'])
   ->where('central_client_id',100)->where('central_organization_id',$org)->where('solastock_organization_id',$org)
   ->where('finance_organization_id',$seed['finance_organization_id'])->where('status','verified')->where('activation_state','active')->sole();
  if(DB::connection('tenant')->table('stock_ledger')->where('organization_id',$org)->exists())throw new \LogicException('Fresh physical fixture required');
  $unit=Unit::create(['code'=>'CREDIT-PAIR-EACH','name'=>'Credit pair each','kind'=>'count','is_active'=>true]);
  $item=Item::create(['sku'=>'CREDIT-PAIR-INV','name'=>'Private supplier credit inventory','item_type'=>'inventory','tracking_type'=>'none',
   'costing_method'=>$costingMethod,'base_unit_id'=>$unit->id,'is_active'=>true]);
  $warehouse=Warehouse::create(['code'=>'CREDIT-PAIR-WH','name'=>'Private credit warehouse','type'=>'warehouse','is_active'=>true]);
  $master=function($type,$local,$remote)use($map){return IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)\Illuminate\Support\Str::uuid(),
   'organization_mapping_uuid'=>$map->mapping_uuid,'central_client_id'=>$map->central_client_id,'central_organization_id'=>$map->central_organization_id,
   'finance_organization_id'=>$map->finance_organization_id,'solastock_organization_id'=>$map->solastock_organization_id,
   'entity_type'=>$type,'solastock_record_id'=>(string)$local,'solabooks_record_id'=>(string)$remote,'status'=>'verified']);};
  $master('item',$item->id,$seed['finance_item_id']);$master('unit',$unit->id,$seed['finance_unit_id']);
  foreach(['inventory_asset','grni','cogs']as$role){
   $account=(int)$seed['account_roles'][$role];
   if(!DB::connection('tenant')->table('accounts')->where('organization_id',$map->finance_organization_id)->where('id',$account)->where('is_active',true)->exists())
    throw new \LogicException('Actual native Finance account required');
   $reference=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>$role,'solabooks_account_id'=>$account,'status'=>'verified']);
   $master('account_role',$reference->id,$account);
  }
  IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>$map->finance_organization_id,
   'meta'=>['api_key_encrypted'=>Crypt::encryptString($seed['synthetic_api_key']),'client_id'=>100,'central_organization_id'=>$org,
    'signing_key_id'=>$seed['signing_key_id'],'signing_secret_encrypted'=>Crypt::encryptString($seed['synthetic_signing_secret']),
    'signing_protocol_version'=>'v1','transport_enabled'=>true,'transport_enabled_workflows'=>['grn.posted','grn.reversed'],
    'finance_currency_contract'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],'money_scale'=>2,'rate_scale'=>8,
      'inventory_valuation_basis'=>\App\Services\Integration\FinanceBaseValuation::BASIS]]]);
  InventoryUserWarehouse::create(['user_id'=>$seed['warehouse_actor_id'],'warehouse_id'=>$warehouse->id,'assigned_by'=>$seed['central_actor_id']]);
  // Supplier deliberately absent: the real normal Bill posting handoff must synchronize it after connection activation.
  return ['organization_id'=>$org,'item_id'=>$item->id,'unit_id'=>$unit->id,'warehouse_id'=>$warehouse->id];
 }
 /** Actor must already be genuinely admitted by the private canonical Central registry, never mocked permission decisions. */
 public static function receive(int $org,int $financeBillId,int $warehouseId,string $quantity,string $date,?string $unitCost=null):array {
  self::isolated($org);
  if(!app(\App\Services\Access\InventoryPermissionService::class)->can(request()->user(),'inventory.receive_goods'))
   throw new \LogicException('Actual warehouse receiving permission required');
  $request=ReceivingRequest::query()->where('organization_id',$org)->where('source_bill_id',$financeBillId)->where('status','!=','cancelled')->sole();
  if(!$request->approved_at){
   if(!app(\App\Services\Access\InventoryPermissionService::class)->can(request()->user(),'inventory.approve_purchase_orders'))
    throw new \LogicException('Actual native receiving approval permission required');
   app(ReceivingRequestService::class)->approve($request,$warehouseId);
  }
  $line=$request->lines()->sole();
  $attributes=['receiving_request_id'=>$request->id,'supplier_id'=>$request->supplier_id,'warehouse_id'=>$warehouseId,'receipt_date'=>$date];
  $prepared=app(OperationalReceiving::class)->prepare($attributes+['lines'=>[['receiving_request_line_id'=>$line->id,'item_id'=>$line->item_id,
   'entered_unit_id'=>$line->entered_unit_id,'received_qty'=>$quantity,'accepted_qty'=>$quantity,'unit_cost'=>$unitCost??$line->unit_cost]]]);
  $receipt=app(GoodsReceiptService::class)->createDraft($attributes,$prepared['lines']);
  app(OperationalReceiving::class)->posting($receipt);$receipt=app(GoodsReceiptService::class)->post($receipt);
  return ['receiving_request_id'=>$request->id,'request_uuid'=>$request->request_uuid,'goods_receipt_id'=>$receipt->id,
   'goods_receipt_number'=>$receipt->grn_number,'lines'=>$receipt->lines()->get()->map(fn($line)=>['id'=>$line->id,'accepted_base_qty'=>$line->accepted_qty])->all()];
 }
 /** Genuine linked native sales order/reservation/shipment: no direct consumption/ledger writes. */
 public static function consume(int $org,array $stock,string $quantity,string $date):array {
  self::isolated($org);
  $permission=app(\App\Services\Access\InventoryPermissionService::class);
  foreach(['inventory.manage_sales_orders','inventory.manage_reservations','inventory.manage_shipments'] as $ability)
   if(!$permission->can(request()->user(),$ability))throw new \LogicException('Actual native dispatch permissions required');
  if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$quantity)||bccomp($quantity,'0',4)<=0)
   throw new \LogicException('Positive explicit native dispatch quantity required');
  $orders=app(\App\Services\Documents\SalesOrderService::class);
  $order=$orders->createDraft(['warehouse_id'=>$stock['warehouse_id'],'order_date'=>$date,'currency_code'=>'JOD'],
   [['item_id'=>$stock['item_id'],'entered_unit_id'=>$stock['unit_id'],'ordered_qty'=>$quantity,'unit_price'=>'7']]);
  $order=$orders->confirm($order);$order=$orders->reserve($order);
  $source=$order->lines()->sole();
  $shipments=app(\App\Services\Documents\ShipmentService::class);
  $shipment=$shipments->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$stock['warehouse_id'],'ship_date'=>$date],
   [['sales_order_line_id'=>$source->id,'item_id'=>$stock['item_id'],'entered_unit_id'=>$stock['unit_id'],'quantity'=>$quantity]]);
  $shipment=$shipments->post($shipment);
  return ['sales_order_id'=>$order->id,'sales_order_line_id'=>$source->id,'shipment_id'=>$shipment->id,
   'shipment_number'=>$shipment->shipment_number,'shipment_status'=>$shipment->status];
 }

}
