<?php

namespace App\Services\Sales;

use App\Models\Tenant\{FulfillmentCommand, FulfillmentRequest, Item, ItemVariant, InventorySetting, Lot, Reservation, SalesOrder, SalesOrderLine, SerialNumber, Shipment, StockBalance, Unit, Warehouse, WarehouseBin};
use App\Services\Access\{InventoryPermissionService, WarehouseAccessService};
use App\Services\Documents\ShipmentService;
use App\Services\Integration\{IntegrationOutboxService, SolaBooksOutboxDeliveryService, SolaStockJournalContract, WorkflowValidationService};
use App\Services\Stock\{StockReservationService};
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Finance dispatch never borrows a Finance permission to move stock. */
final class FinanceDispatchService
{
    private function source(array $data, string $permission = 'inventory.manage_shipments'): FulfillmentRequest
    {
        Validator::make($data, ['source_invoice_id'=>'required|integer|min:1', 'request_uuid'=>'required|uuid', 'invoice_revision'=>'required|string|size:64'])->validate();
        $actor = (int) (request()->user()?->getAuthIdentifier() ?? 0);
        abort_unless($actor > 0 && app(InventoryPermissionService::class)->can(request()->user(), $permission), 403);
        $authority = app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor, (int) $data['source_invoice_id'], 'view', ['request_uuid'=>$data['request_uuid']]);
        $r = FulfillmentRequest::query()->where('request_uuid', $data['request_uuid'])->where('source_invoice_id', $data['source_invoice_id'])->firstOrFail();
        abort_unless(($authority['request_revision'] ?? null) === $r->source_revision && ($authority['source_revision'] ?? null) === $data['invoice_revision'], 409, __('inventory.purchasing.refresh_required'));
        return $r;
    }

    public function options(array $data): array
    {
        $r = $this->source($data, 'inventory.view_sales');
        $allowed = app(WarehouseAccessService::class)->allowedIds();
        $warehouses = Warehouse::query()->where('is_active', true)->when($allowed !== null, fn ($q)=>$q->whereIn('id',$allowed))->get();
        $ids = $warehouses->pluck('id')->all();
        $canShip = app(InventoryPermissionService::class)->can(request()->user(), 'inventory.manage_shipments');
        $ready = $canShip && $r->approved_at && $r->approved_revision === $r->source_revision && in_array($r->status, ['pending', 'partial'], true) && $r->sales_order_id && in_array((int) $r->warehouse_id, $ids, true);
        $default = (int) (InventorySetting::query()->first()?->default_warehouse_id ?? 0);
        return [
            'request'=>app(FulfillmentRequestService::class)->status($r),
            'can_dispatch'=>(bool) $ready,
            'can_approve'=>app(InventoryPermissionService::class)->can(request()->user(), 'inventory.manage_sales_orders') && $r->status === 'pending' && !$r->sales_order_id,
            'can_reserve'=>app(InventoryPermissionService::class)->can(request()->user(), 'inventory.manage_reservations'),
            'dispatch_block_message'=>$ready ? null : __('inventory.sales_handoff.order_not_dispatchable'),
            'default_warehouse_id'=>in_array($default, $ids, true) ? $default : null,
            'warehouses'=>$warehouses->map(fn ($w)=>['id'=>$w->id, 'name'=>$w->name, 'bins'=>WarehouseBin::query()->where('warehouse_id', $w->id)->where('is_active', true)->get(['id','code','name'])->toArray()])->all(),
            'lines'=>$r->lines->map(function ($l) use ($r, $ids) {
                $item = Item::query()->where('is_active', true)->findOrFail($l->item_id);
                $source = $l->sales_order_line_id ? SalesOrderLine::query()->findOrFail($l->sales_order_line_id) : null;
                $factor = (string) ($source?->unit_conversion_factor ?: '1');
                $serialReservations = Reservation::query()->where('source_type','sales_order')->where('source_id',$r->sales_order_id ?? 0)->where('status','active')->whereNotNull('serial_id')->pluck('serial_id')->all();
                return [
                    'source_invoice_line_id'=>$l->source_line_id, 'request_line_id'=>$l->id,
                    'item_id'=>$l->item_id, 'item_name'=>$item->name, 'sku'=>$item->sku,
                    'unit_id'=>$l->entered_unit_id, 'unit_label'=>Unit::query()->find($l->entered_unit_id)?->code,
                    'requested_quantity'=>$l->requested_qty, 'fulfilled_quantity'=>$l->fulfilled_qty,
                    'remaining_quantity'=>Decimal::sub($l->requested_qty, $l->fulfilled_qty),
                    'unit_conversion_factor'=>$factor,
                    'requires_lot'=>$item->tracksLots(), 'requires_serials'=>$item->tracksSerials(),
                    'tracking_type'=>$item->tracking_type, 'requires_variant'=>(bool) $item->is_variant_parent,
                    'variants'=>ItemVariant::query()->where('item_id',$item->id)->where('is_active',true)->get(['id','sku','variant_attributes'])->toArray(),
                    'lots'=>Lot::query()->whereIn('id', StockBalance::query()->where('item_id',$item->id)->whereIn('warehouse_id',$ids)->where('on_hand_qty','>',0)->whereNotNull('lot_id')->pluck('lot_id'))->where('item_id',$item->id)->where('status','active')->where(fn ($q)=>$q->whereNull('expiry_date')->orWhereDate('expiry_date','>=',today()))->get(['id','lot_code','expiry_date'])->toArray(),
                    'serials'=>SerialNumber::query()->whereNotIn('id',Reservation::query()->where('status','active')->whereNotNull('serial_id')->where(fn($q)=>$q->where('source_type','!=','sales_order')->orWhere('source_id','!=',$r->sales_order_id ?? 0))->pluck('serial_id'))->where('item_id',$item->id)->whereIn('warehouse_id',$ids)->where(fn ($q)=>$q->whereIn('status',['available','in_stock','returned'])->orWhereIn('id',$serialReservations))->get(['id','serial','warehouse_id','lot_id','bin_id','variant_id','status'])->map(fn ($s)=>$s->toArray()+['reserved_for_order'=>in_array($s->id,$serialReservations,true)])->all(),
                ];
            })->all(),
        ];
    }

    public function approve(array $data): array
    {
        Validator::make($data, ['warehouse_id'=>'required|integer|min:1'])->validate();
        $r = $this->source($data, 'inventory.manage_sales_orders');
        return app(FulfillmentRequestService::class)->approve($r, (int) $data['warehouse_id']);
    }

    private function validateCommand(array $data): void
    {
        Validator::make($data, [
            'operation_uuid'=>'required|uuid', 'warehouse_id'=>'required|integer|min:1', 'ship_date'=>'required|date_format:Y-m-d',
            'reserve_stock'=>'nullable|boolean', 'lines'=>'required|array|min:1',
            'lines.*.source_invoice_line_id'=>'required', 'lines.*.request_line_id'=>'required|integer|min:1',
            'lines.*.quantity'=>'required|numeric|gt:0', 'lines.*.unit_id'=>'required|integer|min:1',
            'lines.*.bin_id'=>'nullable|integer|min:1', 'lines.*.lot_id'=>'nullable|integer|min:1', 'lines.*.variant_id'=>'nullable|integer|min:1',
            'lines.*.serial_ids'=>'nullable|array', 'lines.*.serial_ids.*'=>'integer|min:1|distinct',
        ])->validate();
    }

    private function lockSource(FulfillmentRequest $request): FulfillmentRequest
    {
        $check = new Shipment(['organization_id'=>$request->organization_id, 'sales_order_id'=>$request->sales_order_id, 'warehouse_id'=>$request->warehouse_id]);
        if ($request->sales_order_id) {
            return app(FulfillmentRequestService::class)->lockShipmentSource($check);
        }
        // Even a rejected unapproved request is serialized with cancel/accept, before an operation is saved.
        return FulfillmentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /** Validate every supplied trace ID before saving a command; native ledger validates again at posting. */
    private function shipmentLines(FulfillmentRequest $r, array $data): array
    {
        abort_unless($r->sales_order_id && $r->approved_at && $r->approved_revision === $r->source_revision && in_array($r->status,['pending','partial'],true) && (int) $r->warehouse_id === (int) $data['warehouse_id'], 409, __('inventory.sales_handoff.order_not_dispatchable'));
        Warehouse::query()->whereKey($data['warehouse_id'])->where('is_active',true)->firstOrFail();
        app(WarehouseAccessService::class)->assertAllowed((int) $data['warehouse_id']);
        if (!empty($data['reserve_stock'])) abort_unless(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_reservations'),403);
        $r->loadMissing('lines'); $lines=[]; $used=[]; $serials=[];
        foreach ($data['lines'] as $i=>$line) {
            $source = $r->lines->first(fn ($l)=>(int) $l->id === (int) $line['request_line_id'] && (string) $l->source_line_id === (string) $line['source_invoice_line_id']);
            if (!$source || (int) $source->entered_unit_id !== (int) $line['unit_id'] || isset($used[$source->id])) $this->invalid("lines.$i.request_line_id");
            $used[$source->id]=true;
            if (Decimal::gt((string) $line['quantity'], Decimal::sub($source->requested_qty,$source->fulfilled_qty))) throw ValidationException::withMessages(["lines.$i.quantity"=>__('inventory.sales_handoff.exceeds_remaining')]);
            $item=Item::query()->where('is_active',true)->findOrFail($source->item_id);
            $nativeSource=SalesOrderLine::query()->where('sales_order_id',$r->sales_order_id)->whereKey($source->sales_order_line_id)->firstOrFail();
            $factor=(string) ($nativeSource->unit_conversion_factor ?: '1');
            if (!empty($line['bin_id']) && !WarehouseBin::query()->whereKey($line['bin_id'])->where('warehouse_id',$data['warehouse_id'])->where('is_active',true)->exists()) $this->invalid("lines.$i.bin_id");
            if ($item->is_variant_parent && empty($line['variant_id'])) $this->invalid("lines.$i.variant_id");
            if (!empty($line['variant_id']) && !ItemVariant::query()->whereKey($line['variant_id'])->where('item_id',$item->id)->where('is_active',true)->exists()) $this->invalid("lines.$i.variant_id");
            $base=['sales_order_line_id'=>$source->sales_order_line_id,'item_id'=>$item->id,'entered_unit_id'=>$source->entered_unit_id]+array_intersect_key($line,array_flip(['bin_id','lot_id','variant_id']));
            if ($item->tracksSerials()) {
                $selected=array_values(array_unique(array_map('intval',$line['serial_ids'] ?? [])));
                $quantity=Decimal::qty(Decimal::mul((string)$line['quantity'],$factor));
                $enteredOne=Decimal::qty(Decimal::div('1',$factor));
                if (!$selected || Decimal::cmp($quantity,(string)count($selected))!==0 || Decimal::cmp(Decimal::qty(Decimal::mul($enteredOne,$factor)),'1')!==0) $this->invalid("lines.$i.serial_ids");
                foreach ($selected as $serialId) {
                    if(isset($serials[$serialId]))$this->invalid("lines.$i.serial_ids"); $serials[$serialId]=true;
                    $serial=SerialNumber::query()->whereKey($serialId)->where('item_id',$item->id)->where('warehouse_id',$data['warehouse_id'])->lockForUpdate()->first();
                    $owned=Reservation::query()->where('source_type','sales_order')->where('source_id',$r->sales_order_id)->where('status','active')->where('serial_id',$serialId)->exists();
                    if (!$owned && empty($data['reserve_stock'])) $this->invalid("lines.$i.serial_ids");
                    if(!$serial || (! $serial->isAvailable() && ! Reservation::query()->where('source_type','sales_order')->where('source_id',$r->sales_order_id)->where('status','active')->where('serial_id',$serialId)->exists()))$this->invalid("lines.$i.serial_ids");
                    if(!empty($line['variant_id'])&&(int)$serial->variant_id!==(int)$line['variant_id'])$this->invalid("lines.$i.variant_id");
                    if(!empty($line['lot_id'])&&(int)$serial->lot_id!==(int)$line['lot_id'])$this->invalid("lines.$i.lot_id");
                    $lines[]=array_replace($base,['quantity'=>$enteredOne,'entered_qty'=>$enteredOne,'serial_id'=>$serialId,'lot_id'=>$serial->lot_id,'bin_id'=>$serial->bin_id,'variant_id'=>$serial->variant_id]);
                }
            } else {
                if (!empty($line['serial_ids']))$this->invalid("lines.$i.serial_ids");
                if($item->tracksLots()&&empty($line['lot_id']))$this->invalid("lines.$i.lot_id");
                if(!empty($line['lot_id'])){
                    $lot=Lot::query()->whereKey($line['lot_id'])->where('item_id',$item->id)->first();
                    if(!$lot||$lot->effectiveStatus()!=='active'||!StockBalance::query()->where('item_id',$item->id)->where('warehouse_id',$data['warehouse_id'])->where('lot_id',$lot->id)->where('on_hand_qty','>',0)->exists())$this->invalid("lines.$i.lot_id");
                }
                $lines[]=$base+['quantity'=>$line['quantity'],'entered_qty'=>$line['quantity']];
            }
        }
        return $lines;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field=>__('inventory.sales_handoff.tracking_required')]);
    }

    public function prepare(array $data): array
    {
        $this->validateCommand($data); $r=$this->source($data); $hash=SolaStockJournalContract::payloadHash($data);
        return DB::connection('tenant')->transaction(function () use ($r,$data,$hash) {
            $r=$this->lockSource($r);
            $c=FulfillmentCommand::query()->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
            if($c){abort_unless((int)$c->fulfillment_request_id===(int)$r->id && $c->status!=='abandoned' && (int)$c->actor_id===(int)request()->user()->getAuthIdentifier() && hash_equals($c->payload_hash,$hash),409);}
            else {
                $this->shipmentLines($r,$data);
                $c=FulfillmentCommand::create(['organization_id'=>app(OrganizationContext::class)->idOrFail(),'operation_uuid'=>$data['operation_uuid'],'fulfillment_request_id'=>$r->id,'source_invoice_id'=>$r->source_invoice_id,'actor_id'=>request()->user()->getAuthIdentifier(),'payload_hash'=>$hash,'payload'=>$data]);
            }
            return $this->result($c);
        },3);
    }

    public function execute(array $data): array
    {
        $prepared=$this->prepare($data); if($prepared['status']==='posted')return $prepared;
        return DB::connection('tenant')->transaction(function () use ($data) {
            $r=FulfillmentRequest::query()->where('request_uuid',$data['request_uuid'])->firstOrFail(); $r=$this->lockSource($r);
            $c=FulfillmentCommand::query()->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->firstOrFail();
            if($c->status==='posted')return $this->result($c); abort_unless($c->status==='prepared',409,__('inventory.purchasing.edits_locked'));
            $lines=$this->shipmentLines($r,$data);
            $order=SalesOrder::query()->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail();
            if(!empty($data['reserve_stock'])){
                abort_unless(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_reservations'),403);
                app(WorkflowValidationService::class)->assertOperationalDocumentReady($order,'stock_reserved');
                foreach($lines as$line){
                    if(empty($line['serial_id']))continue;
                    $exists=Reservation::query()->where('source_type','sales_order')->where('source_id',$order->id)->where('serial_id',$line['serial_id'])->where('status','active')->exists();
                    if(!$exists)app(StockReservationService::class)->reserveSerial((int)$line['item_id'],(int)$data['warehouse_id'],(int)$line['serial_id'],'sales_order',(int)$order->id);
                }
                // Non-serial dispatch needs no reservation; serial reservation uses only the chosen physical units.
                app(IntegrationOutboxService::class)->record('stock_reserved',$order,'sales_order',$order->order_number,$order->order_date?->toDateString());
            }
            $native=app(ShipmentService::class);
            $shipment=$native->createDraft(['shipment_number'=>'SHIP-'.$data['operation_uuid'],'sales_order_id'=>$order->id,'warehouse_id'=>$data['warehouse_id'],'ship_date'=>$data['ship_date']],$lines);
            $shipment=$native->post($shipment);
            $c->update(['shipment_id'=>$shipment->id,'status'=>'posted']); return $this->result($c);
        },3);
    }

    public function abandon(array $data): array
    {
        $this->validateCommand($data); $r=$this->source($data); $hash=SolaStockJournalContract::payloadHash($data);
        return DB::connection('tenant')->transaction(function () use ($r,$data,$hash) {
            $r=$this->lockSource($r);
            $c=FulfillmentCommand::query()->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
            abort_if(Shipment::query()->where('shipment_number','SHIP-'.$data['operation_uuid'])->exists(),409,__('inventory.purchasing.edits_locked'));
            if(!$c)$c=FulfillmentCommand::create(['organization_id'=>$r->organization_id,'operation_uuid'=>$data['operation_uuid'],'fulfillment_request_id'=>$r->id,'source_invoice_id'=>$r->source_invoice_id,'actor_id'=>request()->user()->getAuthIdentifier(),'payload_hash'=>$hash,'payload'=>$data,'status'=>'abandoned']);
            abort_unless((int)$c->fulfillment_request_id===(int)$r->id && (int)$c->actor_id===(int)request()->user()->getAuthIdentifier() && hash_equals($c->payload_hash,$hash) && $c->status!=='posted' && $c->shipment_id===null,409);
            $c->update(['status'=>'abandoned']); return $this->result($c);
        },3);
    }

    public function status(array $data): array
    {
        $r=$this->source($data);
        $c=FulfillmentCommand::query()->where('operation_uuid',$data['operation_uuid'])->where('fulfillment_request_id',$r->id)->where('actor_id',request()->user()->getAuthIdentifier())->firstOrFail();
        return $this->result($c);
    }

    private function result(FulfillmentCommand $command): array
    {
        $shipment=$command->shipment_id ? Shipment::query()->findOrFail($command->shipment_id) : null;
        $request=FulfillmentRequest::query()->findOrFail($command->fulfillment_request_id);
        return ['operation_uuid'=>$command->operation_uuid,'status'=>$command->status,'shipment_id'=>$shipment?->id,'shipment_number'=>$shipment?->shipment_number,'request_id'=>$request->id,'fulfillment_request'=>app(FulfillmentRequestService::class)->status($request)];
    }
}
