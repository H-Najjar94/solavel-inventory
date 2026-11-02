<?php

namespace App\Services\Sales;

use App\Models\Tenant\{FulfillmentRequest,Reservation,SalesOrder,Shipment};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Amend outstanding demand only. Shipping, returns, reservations and costs are never invented. */
final class AcceptedInvoiceDemandAmendment
{
    public function plan(FulfillmentRequest $request,array $target,InvoiceCommercialAmendmentScope $scope,int $actor): array
    {
        $unsupported=__('inventory.sales_handoff.invoice_amendment_accepted_evidence');
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409,$unsupported);
        abort_unless(request()->user() && (int)request()->user()->getAuthIdentifier()===$actor
            && app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders'),403);
        abort_unless($request->approved_at && $request->approved_revision===$request->source_revision
            && $request->sales_order_id && $request->warehouse_id && in_array($request->status,['pending','partial','complete'],true)
            && $request->currency_code===$request->base_currency_code
            && empty($request->source_payload['origin_order']) && empty($request->source_payload['commercial_source']),409,$unsupported);
        app(WarehouseAccessService::class)->assertAllowed((int)$request->warehouse_id);
        $scope->assertOrder((int)$request->organization_id,(int)$request->sales_order_id);
        $order=SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$request->organization_id)
            ->whereKey($request->sales_order_id)->lockForUpdate()->firstOrFail();
        abort_unless($order->source_app==='solabooks' && (string)$order->source_document_id===(string)$request->source_invoice_id
            && (int)$order->customer_id===(int)$request->customer_id && (int)$order->warehouse_id===(int)$request->warehouse_id
            && in_array($order->status,['confirmed','partially_shipped','shipped'],true),409,$unsupported);
        // Existing reservations and in-flight drafts need a separate warehouse amendment.
        abort_if(Reservation::query()->where('organization_id',$request->organization_id)->where('source_type','sales_order')
            ->where('source_id',$order->id)->where('status','active')->lockForUpdate()->exists(),409,__('inventory.sales_handoff.invoice_amendment_reserved'));
        abort_if(Shipment::withoutGlobalScope('warehouse_access')->where('organization_id',$request->organization_id)
            ->where('sales_order_id',$order->id)->whereNotIn('status',['posted','cancelled','reversed'])->lockForUpdate()->exists(),409,__('inventory.sales_handoff.invoice_amendment_dispatch_draft'));
        $orderLines=$order->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $requestLines=$request->lines()->orderBy('id')->lockForUpdate()->get();
        $targets=collect($target['lines']??[])->keyBy('source_line_id');
        abort_unless($requestLines->count()===$orderLines->count() && $targets->count()===$requestLines->count(),409,$unsupported);
        $changes=[];$changed=false;
        foreach($requestLines as $line){
            $source=$orderLines->get($line->sales_order_line_id);$proposed=$targets->get($line->source_line_id);
            abort_unless($source && $proposed,409,$unsupported);
            foreach(['unit_conversion_factor','ordered_qty','shipped_qty','reserved_qty','cancelled_qty','discount_rate','discount_amount','tax_rate','tax_amount','unit_price','line_total'] as $field)abort_unless(is_numeric($source->$field),409,$unsupported);
            abort_unless($source && $proposed && (int)$source->item_id===(int)$line->item_id
                && (int)$source->entered_unit_id===(int)$line->entered_unit_id && Decimal::gt((string)$source->unit_conversion_factor,'0')
                && !Decimal::gt((string)$source->reserved_qty,'0') && !Decimal::gt((string)$source->cancelled_qty,'0')
                && !Decimal::gt((string)$line->cancelled_qty,'0') && !Decimal::gt((string)$source->discount_rate,'0')
                && bccomp((string)$source->tax_rate,'0',6)===0 && bccomp((string)$source->discount_rate,'0',6)===0
                && bccomp((string)$source->tax_amount,'0',6)===0 && bccomp((string)$source->discount_amount,'0',6)===0,409,$unsupported);
            $factor=(string)$source->unit_conversion_factor;$quantity=(string)$proposed['quantity'];
            $base=Decimal::mul($quantity,$factor);$oldBase=Decimal::mul((string)$line->requested_qty,$factor);
            abort_unless(Decimal::gte($quantity,(string)$line->fulfilled_qty),409,__('inventory.sales_handoff.invoice_amendment_return_required'));
            abort_unless(Decimal::gt($quantity,'0') && Decimal::gte($quantity,(string)$line->fulfilled_qty)
                && bccomp($base,Decimal::qty($base),8)===0 && bccomp($oldBase,(string)$source->ordered_qty,8)===0
                && bccomp((string)$source->line_total,Decimal::money(Decimal::mul($oldBase,(string)$source->unit_price)),6)===0
                && bccomp(Decimal::mul((string)$line->fulfilled_qty,$factor),(string)$source->shipped_qty,8)===0,409,$unsupported);
            $changed=$changed || bccomp($quantity,(string)$line->requested_qty,4)!==0;
            $changes[]=['order_line_id'=>(int)$source->id,'source_line_id'=>(string)$line->source_line_id,
                'before'=>$source->getRawOriginal(),'requested_before'=>(string)$line->requested_qty,'requested_after'=>Decimal::qty($quantity),
                'ordered_qty'=>Decimal::qty($base),'entered_qty'=>Decimal::qty($quantity),
                'line_total'=>Decimal::money(Decimal::mul($base,(string)$source->unit_price)),
                'fulfilled_qty'=>(string)$line->fulfilled_qty,'remaining_qty'=>Decimal::qty(Decimal::sub($quantity,(string)$line->fulfilled_qty))];
        }
        $oldTotal='0';foreach($orderLines as $source)$oldTotal=Decimal::add($oldTotal,(string)$source->line_total);
        abort_unless(is_numeric($order->total) && is_numeric($order->subtotal) && is_numeric($order->discount_total) && is_numeric($order->tax_total)
            && bccomp((string)$order->total,Decimal::money($oldTotal),6)===0 && bccomp((string)$order->subtotal,Decimal::money($oldTotal),6)===0
            && bccomp((string)$order->discount_total,'0',6)===0 && bccomp((string)$order->tax_total,'0',6)===0,409,$unsupported);
        abort_unless($changed,409,$unsupported);
        return ['order'=>$order->getRawOriginal(),'lines'=>$changes];
    }

    /** Caller holds the original request, invoice barrier and order locks. */
    public function apply(FulfillmentRequest $request,array $target,InvoiceCommercialAmendmentScope $scope,int $actor,array $approved): array
    {
        $unsupported=__('inventory.sales_handoff.invoice_amendment_accepted_evidence');
        $current=$this->plan($request,$target,$scope,$actor);
        abort_unless(hash_equals(hash('sha256',json_encode($approved,JSON_THROW_ON_ERROR)),hash('sha256',json_encode($current,JSON_THROW_ON_ERROR))),409,$unsupported);
        $order=SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$request->organization_id)->whereKey($request->sales_order_id)->firstOrFail();
        foreach($current['lines'] as $change){
            $order->lines()->whereKey($change['order_line_id'])->update(['ordered_qty'=>$change['ordered_qty'],
                'entered_qty'=>$change['entered_qty'],'line_total'=>$change['line_total']]);
        }
        // Native Finance-created orders have zero Stock tax/discount. Preserve
        // their original quotation prices and snapshots; amend operational demand.
        $lines=$order->lines()->get();$total='0';
        foreach($lines as $line)$total=Decimal::add($total,(string)$line->line_total);
        $all=$lines->every(fn($line)=>Decimal::gte((string)$line->shipped_qty,(string)$line->ordered_qty));
        $some=$lines->contains(fn($line)=>Decimal::gt((string)$line->shipped_qty,'0'));
        $order->update(['subtotal'=>Decimal::money($total),'total'=>Decimal::money($total),
            'status'=>$all?'shipped':($some?'partially_shipped':'confirmed')]);
        return ['order_id'=>(int)$order->id,'state'=>$all?'fulfilled':'pending_fulfillment','physical_effects'=>0,
            'lines'=>array_map(fn($line)=>array_intersect_key($line,array_flip(['source_line_id','requested_before','requested_after','fulfilled_qty','remaining_qty'])),$current['lines'])];
    }
}
