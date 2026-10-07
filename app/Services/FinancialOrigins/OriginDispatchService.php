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
    public function options(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->optionsAdmitted($data,$actor,OriginSourceAdmission::finance($r,$actor));
    }
    public function optionsNative(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->optionsAdmitted($data,$actor,OriginSourceAdmission::stock($r,$actor,$r->side==='sales'?'inventory.view_sales':'inventory.view_stock'));
    }
    private function optionsAdmitted(array $data,int $actor,OriginSourceAdmission $admission):array
    {
        $r=app(OriginRequestService::class)->find($data);$user=request()->user();
        $view=$r->side==='sales'?'inventory.view_sales':'inventory.view_stock';
        abort_unless($user && (int)$user->getAuthIdentifier()===$actor && app(InventoryPermissionService::class)->can($user,$view),403);
        $dto=OriginRequestPayload::fromArray($r->source_payload);
        DB::connection('tenant')->transaction(fn()=>$admission->lock($r));
        $allowed=app(WarehouseAccessService::class)->allowedIds();$warehouses=Warehouse::query()->where('is_active',true)->when($allowed!==null,fn($q)=>$q->whereIn('id',$allowed))->get();
        $ids=$warehouses->pluck('id')->all();$canMove=app(InventoryPermissionService::class)->can($user,$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods');
        $ready=$canMove && $r->approved_at && $r->approved_revision===$r->source_revision && in_array($r->status,['pending','partial'],true) && in_array((int)$r->warehouse_id,$ids,true);
        $default=(int)(\App\Models\Tenant\InventorySetting::query()->first()?->default_warehouse_id??0);
        return ['request'=>app(OriginRequestService::class)->summary($r),'request_revision'=>$r->source_revision,'can_execute'=>(bool)$ready,
            'can_approve'=>!$r->approved_at && $r->status==='pending' && app(InventoryPermissionService::class)->can($user,$r->side==='sales'?'inventory.manage_sales_orders':'inventory.manage_adjustments'),
            'default_warehouse_id'=>in_array($default,$ids,true)?$default:null,
            'warehouses'=>$warehouses->map(fn($w)=>['id'=>$w->id,'name'=>$w->name,'bins'=>\App\Models\Tenant\WarehouseBin::query()->where('warehouse_id',$w->id)->where('is_active',true)->get(['id','code','name'])->toArray()])->all(),
            'lines'=>$r->lines()->get()->map(function($l){$item=Item::query()->where('is_active',true)->findOrFail($l->item_id);return ['request_line_id'=>$l->id,'source_document_line_id'=>$l->source_document_line_id,
                'item_id'=>$l->item_id,'item_name'=>$item->name,'unit_id'=>$l->unit_id,'unit_conversion_factor'=>$l->unit_conversion_factor,
                'remaining_quantity'=>Decimal::sub(Decimal::sub((string)$l->requested_quantity,(string)$l->fulfilled_quantity),(string)$l->cancelled_quantity),
                'unit_price'=>$l->unit_price,'requires_lot'=>$item->tracksLots(),'requires_serials'=>$item->tracksSerials(),'requires_variant'=>(bool)$item->is_variant_parent];})->all()];
    }

    public function prepare(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->prepareAdmitted($data,$actor,OriginSourceAdmission::finance($r,$actor));
    }
    public function prepareNative(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->prepareAdmitted($data,$actor,OriginSourceAdmission::stock($r,$actor,$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods'));
    }
    private function prepareAdmitted(array $data,int $actor,OriginSourceAdmission $admission):array
    {
        Validator::make($data,['operation_uuid'=>'required|uuid','request_revision'=>'required|string|size:64','lines'=>'required|array|min:1','warehouse_id'=>'required|integer|min:1','physical_date'=>'required|date_format:Y-m-d'])->validate();
        $r=app(OriginRequestService::class)->find($data);$dto=OriginRequestPayload::fromArray($r->source_payload);$user=request()->user();
        abort_unless($user && (int)$user->getAuthIdentifier()===$actor && app(InventoryPermissionService::class)->can($user,$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods'),403);
        app(WarehouseAccessService::class)->assertAllowed((int)$data['warehouse_id']);Warehouse::query()->where('is_active',true)->findOrFail($data['warehouse_id']);
        $hash=SolaStockJournalContract::payloadHash($data);
        return DB::connection('tenant')->transaction(function()use($r,$admission,$actor,$data,$hash){
            $admission->lock($r);
            $r=$r->newQuery()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->source_revision===$data['request_revision'] && $r->approved_at && $r->approved_revision===$r->source_revision && (int)$r->warehouse_id===(int)$data['warehouse_id'] && in_array($r->status,['pending','partial'],true),409);
            $c=FinancialOriginCommand::query()->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
            if($c){abort_unless($c->request_uuid===$r->request_uuid && (int)$c->actor_id===$actor && $c->payload_hash===$hash && $c->status!=='abandoned',409);return $this->commandSummary($c);}
            $seen=[];foreach($data['lines']as$line){
                $l=$r->lines()->findOrFail($line['request_line_id']??0);abort_if(isset($seen[$l->id]),422);$seen[$l->id]=true;
                abort_unless((int)$l->source_document_line_id===(int)($line['source_document_line_id']??0) && (int)$l->unit_id===(int)($line['unit_id']??0)
                    && Decimal::gt((string)($line['quantity']??'0'),'0') && Decimal::cmp((string)$line['quantity'],Decimal::sub(Decimal::sub((string)$l->requested_quantity,(string)$l->fulfilled_quantity),(string)$l->cancelled_quantity))<=0,422);
            }
            $c=FinancialOriginCommand::create(['organization_id'=>$r->organization_id,'operation_uuid'=>$data['operation_uuid'],'request_uuid'=>$r->request_uuid,
                'source_document_type'=>$r->source_document_type,'source_document_id'=>$r->source_document_id,'source_journal_id'=>$r->source_journal_id,'actor_id'=>$actor,'payload_hash'=>$hash,'payload'=>$data,'status'=>'pending']);
            return $this->commandSummary($c);
        },3);
    }

    public function status(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);$dto=OriginRequestPayload::fromArray($r->source_payload);
        app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$dto->origin,'view',['request_uuid'=>$r->request_uuid]);
        $c=FinancialOriginCommand::query()->where('request_uuid',$r->request_uuid)->where('operation_uuid',$data['operation_uuid']??'')->where('actor_id',$actor)->firstOrFail();return $this->commandSummary($c);
    }

    public function abandon(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->abandonAdmitted($data,$actor,OriginSourceAdmission::finance($r,$actor));
    }
    public function abandonNative(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->abandonAdmitted($data,$actor,OriginSourceAdmission::stock($r,$actor,$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods'));
    }
    private function abandonAdmitted(array $data,int $actor,OriginSourceAdmission $admission):array
    {
        $r=app(OriginRequestService::class)->find($data);$dto=OriginRequestPayload::fromArray($r->source_payload);
        return DB::connection('tenant')->transaction(function()use($r,$admission,$actor,$data){
            $admission->lock($r);
            $r=$r->newQuery()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            $c=FinancialOriginCommand::query()->where('operation_uuid',$data['operation_uuid']??'')->lockForUpdate()->firstOrFail();
            abort_unless($c->request_uuid===$r->request_uuid && (int)$c->actor_id===$actor && !$c->shipment_id && !$c->goods_receipt_id && in_array($c->status,['pending','abandoned'],true),409);
            $c->update(['status'=>'abandoned']);return $this->commandSummary($c);
        },3);
    }
    private function commandSummary(FinancialOriginCommand $c):array{return ['operation_uuid'=>$c->operation_uuid,'status'=>$c->status==='pending'?'prepared':$c->status,'shipment_id'=>$c->shipment_id,'goods_receipt_id'=>$c->goods_receipt_id,'request'=>$c->response];}

    public function execute(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->executeAdmitted($data,$actor,OriginSourceAdmission::finance($r,$actor));
    }
    public function executeNative(array $data,int $actor):array
    {
        $r=app(OriginRequestService::class)->find($data);return $this->executeAdmitted($data,$actor,OriginSourceAdmission::stock($r,$actor,$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods'));
    }
    private function executeAdmitted(array $data,int $actor,OriginSourceAdmission $admission):array
    {
        Validator::make($data,['operation_uuid'=>'required|uuid','request_uuid'=>'required|uuid','request_revision'=>'required|string|size:64','warehouse_id'=>'required|integer|min:1',
            'physical_date'=>'required|date_format:Y-m-d','lines'=>'required|array|min:1','lines.*.request_line_id'=>'required|integer|min:1|distinct',
            'lines.*.source_document_line_id'=>'required|integer|min:1|distinct','lines.*.quantity'=>'required|numeric|gt:0',
            'lines.*.unit_id'=>'required|integer|min:1','lines.*.bin_id'=>'nullable|integer|min:1','lines.*.lot_id'=>'nullable|integer|min:1',
            'lines.*.variant_id'=>'nullable|integer|min:1','lines.*.serial_ids'=>'nullable|array','lines.*.serial_ids.*'=>'integer|min:1|distinct'])->validate();
        $r=app(OriginRequestService::class)->find($data);abort_unless(($data['request_revision']??null)===$r->source_revision,409);$dto=OriginRequestPayload::fromArray($r->source_payload);
        $user=request()->user();$permission=$r->side==='sales'?'inventory.manage_shipments':'inventory.receive_goods';
        abort_unless($user && (int)$user->getAuthIdentifier()===$actor && app(InventoryPermissionService::class)->can($user,$permission),403);
        app(WarehouseAccessService::class)->assertAllowed((int)$data['warehouse_id']);
        Warehouse::query()->where('is_active',true)->findOrFail($data['warehouse_id']);
        $hash=SolaStockJournalContract::payloadHash($data);
        return DB::connection('tenant')->transaction(function()use($r,$admission,$actor,$data,$hash){
            $admission->lock($r);
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
                $attrs=['supplier_id'=>$r->party_id,'warehouse_id'=>$r->warehouse_id,'receipt_date'=>$data['physical_date'],'integration_currency_code'=>$r->source_payload['currency_code']];
                $native=app(GoodsReceiptService::class);$document=$native->createDraft($attrs,$nativeLines);
                $command->update(['goods_receipt_id'=>$document->id]);app(OriginPhysicalService::class)->bindNativeLines($command,$document,$r);$document=$native->post($document);
            }
            return app(OriginPhysicalService::class)->posted($document);
        },3);
    }
}
