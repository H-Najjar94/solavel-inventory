<?php
namespace App\Services\Sales;

use App\Models\Tenant\{FulfillmentRequest,IntegrationMasterDataMapping,IntegrationOrganizationMapping,SalesOrder,SalesOrderLine,Shipment,Warehouse};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open, unshipped Stock-born sales orders that a Finance invoice may explicitly claim
 * instead of creating a second order for the same demand.
 *
 * The claim is the invoice's own FulfillmentRequest row (origin_order.source_order_claim).
 * It is written while the order row is locked, so two invoices can never hold the same
 * order. Coverage must be exact: every unshipped order line is matched by exactly one
 * invoice line for the same item and unit with the same remaining quantity. Anything
 * else is refused with a reason; nothing is merged silently.
 */
final class OpenOrderClaims
{
    public const CLAIMABLE_STATUSES=['confirmed','partially_reserved','reserved','partially_picked','picked','partially_packed','packed'];
    private const LIMIT=25;

    /** Read-only: open Stock-born orders of the invoice's customer. Creates nothing. */
    public function list(IntegrationOrganizationMapping $mapping,int $financeCustomerId,int $sourceInvoiceId):array
    {
        $customer=$this->localCustomer($mapping,$financeCustomerId);
        if(!$customer)return['customer_external_id'=>$financeCustomerId,'customer_mapped'=>false,'orders'=>[]];
        $orders=$this->orders()->where('organization_id',$mapping->solastock_organization_id)->where('customer_id',$customer)
            ->where('source_app','<>','solabooks')->whereIn('status',self::CLAIMABLE_STATUSES)
            ->orderBy('order_date')->orderBy('id')->limit(self::LIMIT)->get();
        $result=[];
        foreach($orders as $order){
            if($this->hasPostedShipments($order))continue;
            $holder=$this->holder($order);
            if($holder&&(int)$holder->source_invoice_id!==$sourceInvoiceId)continue;
            $result[]=$this->present($mapping,$order);
        }
        return['customer_external_id'=>$financeCustomerId,'customer_mapped'=>true,'orders'=>$result];
    }

    /**
     * Upsert-time claim. Runs inside the request transaction, after the connection lock.
     * $lines are the request lines already mapped to local item/unit ids.
     */
    public function assertUpsertClaim(IntegrationOrganizationMapping $mapping,array $data,int $customerId,array $lines,?int $requestId):void
    {
        $origin=(array)($data['origin_order']??[]);
        if(($origin['source_order_claim']??false)!==true)return;
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409);
        abort_if(isset($origin['source_shipment_refs']),422);
        $order=$this->orders()->where('organization_id',$mapping->solastock_organization_id)->whereKey((int)($origin['sales_order_id']??0))->lockForUpdate()->first();
        if(!$order)$this->refuse('order_claim_unavailable');
        $this->assertClaimable($order,$customerId,(int)($origin['warehouse_id']??0),$requestId);
        if(!hash_equals($this->revision($order),(string)($origin['order_revision']??'')))$this->refuse('order_claim_changed');
        $requested=[];foreach(array_values($data['lines'])as$i=>$line)$requested[(string)$line['source_line_id']]=(int)($line['original_sales_order_line_id']??0);
        $this->assertCoverage($order,$lines,$requested);
    }

    /** Approval-time: re-verify under lock and bind request lines to the claimed order lines. */
    public function lockedClaim(FulfillmentRequest $request,int $warehouse):SalesOrder
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409);
        $origin=(array)data_get($request->source_payload,'origin_order',[]);
        abort_unless(($origin['source_order_claim']??false)===true&&(int)($origin['warehouse_id']??0)===$warehouse,409,__('inventory.sales_handoff.order_claim_warehouse'));
        $order=$this->orders()->where('organization_id',$request->organization_id)->whereKey((int)($origin['sales_order_id']??0))->lockForUpdate()->first();
        if(!$order)$this->refuse('order_claim_unavailable');
        $this->assertClaimable($order,(int)$request->customer_id,$warehouse,(int)$request->id);
        $sourceLines=collect((array)data_get($request->source_payload,'lines',[]))->keyBy(fn($l)=>(string)$l['source_line_id']);
        $lines=$request->lines()->orderBy('id')->lockForUpdate()->get();
        $this->assertCoverage($order,$lines->map(fn($l)=>['source_line_id'=>(string)$l->source_line_id,'item_id'=>(int)$l->item_id,'entered_unit_id'=>(int)$l->entered_unit_id,'requested_qty'=>(string)$l->requested_qty])->all(),
            $sourceLines->map(fn($l)=>(int)($l['original_sales_order_line_id']??0))->all());
        foreach($lines as$line){
            $native=SalesOrderLine::query()->where('organization_id',$request->organization_id)->where('sales_order_id',$order->id)
                ->whereKey((int)data_get($sourceLines->get((string)$line->source_line_id),'original_sales_order_line_id'))->lockForUpdate()->firstOrFail();
            $line->update(['sales_order_line_id'=>$native->id,'source_shipped_qty_base'=>$native->shipped_qty,'source_cancelled_qty_base'=>$native->cancelled_qty]);
        }
        return $order;
    }

    /** A claimed order waits for the warehouse approval of its Finance request before shipping. */
    public function pendingClaim(int $orderId):?FulfillmentRequest
    {
        $order=$this->orders()->whereKey($orderId)->first();
        if(!$order)return null;
        $holder=$this->holder($order);
        return $holder&&$holder->sales_order_id===null?$holder:null;
    }

    public function revision(SalesOrder $order):string
    {
        $lines=SalesOrderLine::query()->where('organization_id',$order->organization_id)->where('sales_order_id',$order->id)->orderBy('id')->get()
            ->map(fn($l)=>[(int)$l->id,(int)$l->item_id,(int)$l->entered_unit_id,Decimal::qty((string)$l->unit_conversion_factor),Decimal::qty((string)$l->ordered_qty),Decimal::qty((string)$l->shipped_qty),Decimal::qty((string)$l->cancelled_qty)])->all();
        return hash('sha256',SolaStockJournalContract::canonicalJson(['order'=>(int)$order->id,'customer'=>(int)$order->customer_id,'warehouse'=>(int)$order->warehouse_id,'lines'=>$lines]));
    }

    private function present(IntegrationOrganizationMapping $mapping,SalesOrder $order):array
    {
        $lines=[];$mapped=true;
        foreach(SalesOrderLine::query()->where('organization_id',$order->organization_id)->where('sales_order_id',$order->id)->orderBy('id')->get()as$line){
            $item=$this->financeId($mapping,'item',(int)$line->item_id);$unit=$this->financeId($mapping,'unit',(int)$line->entered_unit_id);$mapped=$mapped&&$item&&$unit;
            $factor=(string)($line->unit_conversion_factor?:'1');
            $lines[]=['sales_order_line_id'=>(int)$line->id,'item_external_id'=>$item,'unit_external_id'=>$unit,
                'ordered'=>Decimal::qty(Decimal::div((string)$line->ordered_qty,$factor)),'shipped'=>Decimal::qty(Decimal::div((string)$line->shipped_qty,$factor)),
                'cancelled'=>Decimal::qty(Decimal::div((string)$line->cancelled_qty,$factor)),'unshipped'=>Decimal::qty(Decimal::div($this->remaining($line),$factor))];
        }
        return['sales_order_id'=>(int)$order->id,'number'=>(string)$order->order_number,'order_date'=>$order->order_date?->format('Y-m-d'),'status'=>(string)$order->status,
            'warehouse_id'=>(int)$order->warehouse_id,'warehouse_name'=>Warehouse::withoutGlobalScopes()->whereKey($order->warehouse_id)->value('name'),
            'order_revision'=>$this->revision($order),'mapped'=>$mapped,'lines'=>$lines];
    }

    private function assertClaimable(SalesOrder $order,int $customerId,int $warehouse,?int $requestId):void
    {
        if((int)$order->customer_id!==$customerId||$order->source_app==='solabooks')$this->refuse('order_claim_unavailable');
        if((int)$order->warehouse_id!==$warehouse)$this->refuse('order_claim_changed');
        if(!in_array($order->status,self::CLAIMABLE_STATUSES,true)||$this->hasPostedShipments($order))$this->refuse('order_claim_unavailable');
        if(FulfillmentRequest::query()->where('organization_id',$order->organization_id)->where('sales_order_id',$order->id)->when($requestId,fn($q)=>$q->whereKeyNot($requestId))->exists())$this->refuse('order_claim_taken');
        $holder=$this->holder($order,$requestId);
        if($holder)$this->refuse('order_claim_taken',['invoice'=>$holder->source_invoice_number?:('#'.$holder->source_invoice_id)]);
    }

    /** @param array<string,int> $requested source_line_id => original_sales_order_line_id */
    private function assertCoverage(SalesOrder $order,array $lines,array $requested):void
    {
        $native=SalesOrderLine::query()->where('organization_id',$order->organization_id)->where('sales_order_id',$order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $used=[];
        foreach($lines as$line){
            $id=(int)($requested[(string)$line['source_line_id']]??0);$source=$native->get($id);
            if($id<=0||!$source||isset($used[$id]))$this->refuse('order_claim_lines');
            $used[$id]=true;$factor=(string)$source->unit_conversion_factor;
            if(!Decimal::gt($factor,'0')||(int)$source->item_id!==(int)$line['item_id']||(int)$source->entered_unit_id!==(int)$line['entered_unit_id'])$this->refuse('order_claim_lines');
            if(Decimal::cmp(Decimal::mul((string)$line['requested_qty'],$factor),$this->remaining($source))!==0)$this->refuse('order_claim_partial');
        }
        foreach($native as$id=>$source)if(!isset($used[$id])&&Decimal::gt($this->remaining($source),'0'))$this->refuse('order_claim_partial');
    }

    private function holder(SalesOrder $order,?int $except=null):?FulfillmentRequest
    {
        return FulfillmentRequest::query()->where('organization_id',$order->organization_id)->where('customer_id',$order->customer_id)
            ->whereNull('sales_order_id')->whereNotIn('status',['cancelled'])->when($except,fn($q)=>$q->whereKeyNot($except))->orderBy('id')->get()
            ->first(fn($r)=>data_get($r->source_payload,'origin_order.source_order_claim')===true&&(int)data_get($r->source_payload,'origin_order.sales_order_id')===(int)$order->id);
    }

    private function hasPostedShipments(SalesOrder $order):bool
    {
        return Shipment::withoutGlobalScope('warehouse_access')->where('organization_id',$order->organization_id)->where('sales_order_id',$order->id)->where('status','posted')->exists();
    }

    private function remaining(SalesOrderLine $line):string
    {
        return Decimal::sub(Decimal::sub((string)$line->ordered_qty,(string)$line->shipped_qty),(string)$line->cancelled_qty);
    }

    /** Finance posting users need not hold a warehouse assignment to see the customer's order identity. */
    private function orders()
    {
        return SalesOrder::withoutGlobalScope('warehouse_access');
    }

    private function localCustomer(IntegrationOrganizationMapping $mapping,int $financeId):?int
    {
        $id=$this->pair($mapping,'customer')->where('solabooks_record_id',(string)$financeId)->value('solastock_record_id');
        return $id?(int)$id:null;
    }

    private function financeId(IntegrationOrganizationMapping $mapping,string $type,int $localId):?int
    {
        $id=$this->pair($mapping,$type)->where('solastock_record_id',(string)$localId)->value('solabooks_record_id');
        return $id?(int)$id:null;
    }

    private function pair(IntegrationOrganizationMapping $mapping,string $type)
    {
        return IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('finance_organization_id',$mapping->finance_organization_id)
            ->where('solastock_organization_id',$mapping->solastock_organization_id)->where('entity_type',$type)->where('status','verified')
            ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false);
    }

    private function refuse(string $reason,array $replace=[]):never
    {
        $message=__('inventory.sales_handoff.'.$reason,$replace);
        $e=ValidationException::withMessages(['origin_order'=>$message]);
        $e->response=response()->json(['message'=>$message,'errors'=>$e->errors(),'claim'=>['reason'=>$reason]],422);
        throw $e;
    }
}
