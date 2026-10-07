<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginRequest,Item,SalesOrder,Shipment,Warehouse};
use App\Services\Access\{InventoryPermissionService,OperationalReceiving,WarehouseAccessService};
use App\Services\Documents\{GoodsReceiptService,ShipmentService};
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\{DB,Validator};

/** Same immutable operation UUID survives unknown outcomes; native services remain the only physical writers. */
final class OriginDispatchService
{
    public function execute(array $data,int $actor):array
    {
        Validator::make($data,['operation_uuid'=>'required|uuid','request_uuid'=>'required|uuid','warehouse_id'=>'required|integer|min:1',
            'physical_date'=>'required|date_format:Y-m-d','lines'=>'required|array|min:1','lines.*.request_line_id'=>'required|integer|min:1|distinct',
            'lines.*.source_document_line_id'=>'required|integer|min:1|distinct','lines.*.quantity'=>'required|numeric|gt:0',
            'lines.*.unit_id'=>'required|integer|min:1','lines.*.bin_id'=>'nullable|integer|min:1','lines.*.lot_id'=>'nullable|integer|min:1',
            'lines.*.variant_id'=>'nullable|integer|min:1','lines.*.serial_ids'=>'nullable|array','lines.*.serial_ids.*'=>'integer|min:1|distinct'])->validate();
        $r=app(OriginRequestService::class)->find($data);$dto=OriginRequestPayload::fromArray($r->source_payload);
        $user=request()->user();$permission=$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods';
        abort_unless($user && (int)$user->getAuthIdentifier()===$actor && app(InventoryPermissionService::class)->can($user,$permission),403);
        app(WarehouseAccessService::class)->assertAllowed((int)$data['warehouse_id']);
        Warehouse::query()->where('is_active',true)->findOrFail($data['warehouse_id']);
        $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$dto->origin,'view',['request_uuid'=>$r->request_uuid]);
        $hash=SolaStockJournalContract::payloadHash($data);
        return DB::connection('tenant')->transaction(function()use($r,$dto,$authority,$actor,$data,$hash){
            LockedOriginProof::verify($dto,app(ReceivingRequestService::class)->mapping(),$authority,$actor);
            $r=$r->newQuery()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            $command=FinancialOriginCommand::query()->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
            if($command){abort_unless($command->request_uuid===$r->request_uuid && (int)$command->actor_id===$actor && $command->payload_hash===$hash,409);
                if($command->status==='completed')return $command->response;abort_unless($command->status==='pending',409);
                abort_if($command->shipment_id || $command->goods_receipt_id,409,'Reconcile the existing physical operation before retrying.');
            }
            abort_unless($r->approved_at && $r->approved_revision===$r->source_revision && in_array($r->status,['pending','partial'],true) && (int)$r->warehouse_id===(int)$data['warehouse_id'],409);
            $nativeLines=[];$r->loadMissing('lines');
            foreach($data['lines']as$line){
                $source=$r->lines->firstWhere('id',(int)$line['request_line_id']);
                abort_unless($source && (int)$source->source_document_line_id===(int)$line['source_document_line_id'] && (int)$source->unit_id===(int)$line['unit_id']
                    && Decimal::cmp((string)$line['quantity'],Decimal::sub(Decimal::sub((string)$source->requested_quantity,(string)$source->fulfilled_quantity),(string)$source->cancelled_quantity))<=0,422);
                $item=Item::query()->where('is_active',true)->findOrFail($source->item_id);
                $trace=array_intersect_key($line,array_flip(['bin_id','lot_id','variant_id','serial_ids','serials','lot_code','expiry_date']));
                if($r->side==='sales'){
                    $base=$trace+['sales_order_line_id'=>$source->sales_order_line_id,'item_id'=>$source->item_id,'entered_unit_id'=>$source->unit_id,'quantity'=>$line['quantity']];
                    unset($base['serial_ids']);
                    if($item->tracksSerials())foreach($line['serial_ids']??[]as$serial)$nativeLines[]=array_replace($base,['serial_id'=>$serial,'quantity'=>Decimal::div('1',(string)$source->unit_conversion_factor)]);
                    else $nativeLines[]=$base;
                }
                else {
                    $cost=(string)($line['unit_cost']??$source->unit_price);
                    abort_unless(Decimal::cmp($cost,(string)$source->unit_price)===0 || app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_adjustments'),403);
                    abort_unless(Decimal::cmp($cost,'0')>=0,422);
                    $nativeLines[]=$trace+['item_id'=>$source->item_id,'entered_unit_id'=>$source->unit_id,'accepted_qty'=>$line['quantity'],'unit_cost'=>$cost];
                }
                if($item->tracksSerials())abort_unless(Decimal::cmp(Decimal::mul((string)$line['quantity'],(string)$source->unit_conversion_factor),(string)count($line['serial_ids']??$line['serials']??[]))===0,422);
            }
            if(!$command)$command=FinancialOriginCommand::create(['organization_id'=>$r->organization_id,'operation_uuid'=>$data['operation_uuid'],'request_uuid'=>$r->request_uuid,
                'source_document_type'=>$r->source_document_type,'source_document_id'=>$r->source_document_id,'source_journal_id'=>$r->source_journal_id,'actor_id'=>$actor,'payload_hash'=>$hash,'payload'=>$data,'status'=>'pending']);
            if($r->side==='sales'){
                SalesOrder::query()->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail();
                $native=app(ShipmentService::class);$document=$native->createDraft(['shipment_number'=>'SHIP-'.$data['operation_uuid'],'sales_order_id'=>$r->sales_order_id,'warehouse_id'=>$r->warehouse_id,'ship_date'=>$data['physical_date']],$nativeLines);
                $command->update(['shipment_id'=>$document->id]);app(OriginPhysicalService::class)->bindNativeLines($command,$document,$r);$document=$native->post($document);
            }else{
                $attrs=['supplier_id'=>$r->party_id,'warehouse_id'=>$r->warehouse_id,'receipt_date'=>$data['physical_date']];
                $native=app(GoodsReceiptService::class);$document=$native->createDraft($attrs,$nativeLines);
                $command->update(['goods_receipt_id'=>$document->id]);app(OriginPhysicalService::class)->bindNativeLines($command,$document,$r);$document=$native->post($document);
            }
            return app(OriginPhysicalService::class)->posted($document);
        },3);
    }
}
