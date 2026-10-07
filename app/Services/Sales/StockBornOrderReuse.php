<?php
namespace App\Services\Sales;

use App\Models\Tenant\{FulfillmentRequest,SalesDocumentOutbox,SalesOrder,SalesOrderLine,Shipment};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Canonical Finance source proof is checked by the caller before these native locked facts. */
final class StockBornOrderReuse
{
    public function lockedOrder(FulfillmentRequest $request,int $warehouse):SalesOrder
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409);
        $origin=(array)data_get($request->source_payload,'origin_order',[]);
        abort_unless((int)($origin['sales_order_id']??0)>0 && (int)($origin['warehouse_id']??0)===$warehouse,409);
        $refs=(array)($origin['source_shipment_refs']??[]);abort_unless($refs!==[],409);
        $proven=[];$seen=[];$mappedLines=[];
        foreach($refs as $ref){
            $uuid=(string)($ref['event_uuid']??'');abort_unless($uuid!==''&&!isset($seen[$uuid]),409);$seen[$uuid]=true;
            $event=SalesDocumentOutbox::query()->where('organization_id',$request->organization_id)->where('organization_mapping_uuid',$request->organization_mapping_uuid)
                ->where('event_uuid',$uuid)->where('event_type','sales.shipment.confirmed')->firstOrFail();
            $shipmentFacts=(array)data_get($event->payload,'shipment',[]);
            abort_unless(array_key_exists('request_uuid',$shipmentFacts) && $shipmentFacts['request_uuid']===null && array_key_exists('source_invoice_id',$shipmentFacts) && $shipmentFacts['source_invoice_id']===null,409);
            abort_unless(hash_equals((string)$event->payload_hash,(string)($ref['payload_hash']??'')) && hash_equals($event->payload_hash,SolaStockJournalContract::payloadHash($event->payload))
                && (int)data_get($event->payload,'shipment.id')===(int)($ref['id']??0)
                && data_get($event->payload,'shipment.mapping_uuid')===($ref['mapping_uuid']??null)
                && (int)data_get($event->payload,'shipment.sales_order_id')===(int)$origin['sales_order_id']
                && (int)data_get($event->payload,'shipment.warehouse_id')===$warehouse,409);
            abort_unless(Shipment::query()->where('organization_id',$request->organization_id)->where('sales_order_id',$origin['sales_order_id'])->whereKey($ref['id'])->where('status','posted')->whereNull('reversal_sales_return_id')->exists(),409);
            foreach((array)data_get($event->payload,'shipment.lines',[])as$line){
                $id=(int)($line['sales_order_line_id']??0);abort_unless($id>0,409);$proven[$id]=true;$mappedLines[$id][]=$line;
            }
        }
        $order=SalesOrder::query()->where('organization_id',$request->organization_id)->whereKey($origin['sales_order_id'])->lockForUpdate()->firstOrFail();
        $nativeShipmentIds=Shipment::query()->where('organization_id',$request->organization_id)->where('sales_order_id',$origin['sales_order_id'])->where('status','posted')->whereNull('reversal_sales_return_id')->orderBy('id')->pluck('id')->map(fn($id)=>(int)$id)->all();
        $refIds=array_map('intval',array_column($refs,'id'));sort($refIds);abort_unless($nativeShipmentIds===$refIds,409);
        abort_unless((int)$order->warehouse_id===$warehouse && (int)$order->customer_id===(int)$request->customer_id
            && in_array($order->status,['confirmed','partially_reserved','reserved','partially_picked','picked','partially_packed','packed','partially_shipped'],true),409);
        abort_if(FulfillmentRequest::query()->where('organization_id',$request->organization_id)->where('sales_order_id',$order->id)->whereKeyNot($request->id)->exists(),409);
        $sourceLines=collect((array)data_get($request->source_payload,'lines',[]))->keyBy(fn($l)=>(string)$l['source_line_id']);$used=[];
        foreach($request->lines()->orderBy('id')->lockForUpdate()->get()as$line){
            $id=(int)data_get($sourceLines->get((string)$line->source_line_id),'original_sales_order_line_id');
            abort_unless($id>0&&isset($proven[$id])&&!isset($used[$id]),409);$used[$id]=true;
            $native=SalesOrderLine::query()->where('organization_id',$request->organization_id)->where('sales_order_id',$order->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $factor=(string)$native->unit_conversion_factor;abort_unless(Decimal::gt($factor,'0') && (int)$native->item_id===(int)$line->item_id && (int)$native->entered_unit_id===(int)$line->entered_unit_id,409);
            abort_unless(Decimal::cmp((string)($line->cancelled_qty??'0'),'0')===0,409); // Reviewed prior credit demand needs its own exact allocation before linking.
            $remaining=Decimal::sub(Decimal::sub((string)$native->ordered_qty,(string)$native->shipped_qty),(string)$native->cancelled_qty);
            abort_unless(Decimal::cmp(Decimal::mul((string)$line->requested_qty,$factor),$remaining)===0,409);
            foreach($mappedLines[$id]as$proof)abort_unless((int)$proof['item_id']===(int)$native->item_id && (int)$proof['unit_id']===(int)$native->entered_unit_id,409);
            $line->update(['sales_order_line_id'=>$native->id,'source_shipped_qty_base'=>$native->shipped_qty,'source_cancelled_qty_base'=>$native->cancelled_qty]);
        }
        return $order;
    }
}
