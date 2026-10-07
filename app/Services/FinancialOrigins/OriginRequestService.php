<?php
namespace App\Services\FinancialOrigins;

use App\Models\Tenant\{Customer,FinancialOriginRequest,IntegrationMasterDataMapping,Item,Supplier,Unit,Warehouse};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\SalesOrderService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** New typed origins never enter the legacy Invoice or Bill request namespaces. */
final class OriginRequestService
{
    public function upsert(array $data,int $actor):array
    {
        $dto=OriginRequestPayload::fromArray($data);
        abort_unless(in_array($dto->origin->type,['sales_receipt','expense'],true),422);
        $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$dto->origin,'post',['request_uuid'=>$data['request_uuid']]);
        return DB::connection('tenant')->transaction(function()use($dto,$authority,$actor){
            $mapping=app(ReceivingRequestService::class)->mapping();
            $proof=LockedOriginProof::verify($dto,$mapping,$authority,$actor);
            $mapping=$proof->mapping; $p=$dto->payload;
            $r=FinancialOriginRequest::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type',$dto->origin->type)->where('source_document_id',$dto->origin->documentId)->lockForUpdate()->first();
            if($r){abort_unless($r->status!=='cancelled',409);abort_unless($r->request_uuid===$p['request_uuid'] && $r->source_revision===$p['source_revision'] && (int)$r->source_journal_id===$dto->origin->journalId,409);return $this->summary($r);}
            $partyId=null;
            if($dto->origin->type==='expense')$partyId=$this->mapped($mapping,'supplier',(int)$p['supplier_external_id'],'supplier_external_id');
            elseif(($p['customer_external_id']??null)!==null)$partyId=$this->mapped($mapping,'customer',(int)$p['customer_external_id'],'customer_external_id');
            $lines=[];
            foreach($p['lines'] as $i=>$line){
                $item=$this->mapped($mapping,'item',(int)$line['item_external_id'],"lines.$i.item_external_id");
                $unit=$this->mapped($mapping,'unit',(int)$line['unit_external_id'],"lines.$i.unit_external_id");
                $normalized=app(UnitConversionResolver::class)->normalizeLine(['item_id'=>$item,'entered_unit_id'=>$unit,'quantity'=>$line['quantity']],'quantity');
                abort_unless(Decimal::gt((string)($normalized['unit_conversion_factor']??'0'),'0'),422);
                $lines[]=['source_document_line_id'=>$line['source_document_line_id'],'item_id'=>$item,'unit_id'=>$unit,
                    'unit_conversion_factor'=>$normalized['unit_conversion_factor'],'requested_quantity'=>Decimal::qty((string)$line['quantity']),'unit_price'=>$line['unit_price']];
            }
            $r=FinancialOriginRequest::create(['organization_id'=>app(OrganizationContext::class)->idOrFail(),'organization_mapping_uuid'=>$mapping->mapping_uuid,
                'request_uuid'=>$p['request_uuid'],'source_document_type'=>$dto->origin->type,'source_document_id'=>$dto->origin->documentId,
                'source_document_number'=>$dto->origin->number,'source_journal_id'=>$dto->origin->journalId,'source_revision'=>$p['source_revision'],
                'side'=>$dto->origin->domain()==='sales'?'sales':'purchase','status'=>'pending','party_id'=>$partyId,'source_payload'=>$p]);
            foreach($lines as $line)$r->lines()->create($line+['organization_id'=>$r->organization_id]);
            return $this->summary($r);
        },3);
    }

    private function mapped($mapping,string $type,int $source,string $field):int
    {
        $pair=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('central_client_id',$mapping->central_client_id)->where('central_organization_id',$mapping->central_organization_id)
            ->where('finance_organization_id',$mapping->finance_organization_id)->where('solastock_organization_id',$mapping->solastock_organization_id)
            ->where('entity_type',$type)->where('solabooks_record_id',(string)$source)->where('status','verified')->whereNull('conflict_code')->whereNull('error_state')
            ->where('solastock_archived',false)->where('solabooks_archived',false)->first();
        $model=match($type){'customer'=>Customer::class,'supplier'=>Supplier::class,'item'=>Item::class,'unit'=>Unit::class};
        if(!$pair || !$model::query()->whereKey((int)$pair->solastock_record_id)->where('is_active',true)->exists()) {
            $e=ValidationException::withMessages([$field=>__('inventory.purchasing.mapping_required')]);
            $e->response=response()->json(['message'=>__('inventory.purchasing.mapping_required'),'errors'=>$e->errors(),'dependency'=>['entity_type'=>$type,'source_id'=>$source,'field'=>$field,'reason'=>'mapping_required']],422); throw $e;
        }
        return (int)$pair->solastock_record_id;
    }

    /** Fresh remote proof is obtained before native locks; approval moves no stock. */
    public function approve(array $data,int $actor):array
    {
        $r=$this->find($data);return $this->approveAdmitted($data,$actor,OriginSourceAdmission::finance($r,$actor));
    }
    public function approveNative(array $data,int $actor):array
    {
        $r=$this->find($data);return $this->approveAdmitted($data,$actor,OriginSourceAdmission::stock($r,$actor,$r->side==='sales'?'inventory.manage_sales_orders':'inventory.receive_goods'));
    }
    private function approveAdmitted(array $data,int $actor,OriginSourceAdmission $admission):array
    {
        $r=$this->find($data);abort_unless(($data['request_revision']??null)===$r->source_revision,409); $dto=OriginRequestPayload::fromArray($r->source_payload);
        $user=request()->user();$key=$r->side==='sales'?'inventory.manage_sales_orders':'inventory.receive_goods';
        abort_unless($user && (int)$user->getAuthIdentifier()===$actor && app(InventoryPermissionService::class)->can($user,$key),403);
        $warehouse=(int)($data['warehouse_id']??0);app(WarehouseAccessService::class)->assertAllowed($warehouse);
        Warehouse::query()->where('is_active',true)->findOrFail($warehouse);
        return DB::connection('tenant')->transaction(function()use($r,$admission,$actor,$warehouse){
            $admission->lock($r);
            $r=FinancialOriginRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($r->status,['pending','partial'],true),409);
            if($r->approved_at){abort_unless((int)$r->warehouse_id===$warehouse && $r->approved_revision===$r->source_revision,409);return $this->summary($r);}
            if($r->side==='sales'){
                $order=app(SalesOrderService::class)->createDraft(['warehouse_id'=>$warehouse,'customer_id'=>$r->party_id,'source_app'=>'solabooks',
                    'source_document_id'=>$r->source_document_type.':'.$r->source_document_id,'source_document_number'=>$r->source_document_number,
                    'order_date'=>$r->source_payload['document_date'],'currency_code'=>$r->source_payload['currency_code']],
                    $r->lines()->orderBy('id')->get()->map(fn($l)=>['item_id'=>$l->item_id,'entered_unit_id'=>$l->unit_id,'ordered_qty'=>$l->requested_quantity,'unit_price'=>$l->unit_price,'discount_rate'=>'0','tax_rate'=>'0'])->all());
                app(SalesOrderService::class)->confirm($order);
                $native=$order->lines()->orderBy('id')->get();
                foreach($r->lines()->orderBy('id')->get()->values()as$i=>$line){abort_unless(Decimal::cmp((string)$native[$i]->unit_conversion_factor,(string)$line->unit_conversion_factor)===0,409);$line->update(['sales_order_line_id'=>$native[$i]->id]);}
                $r->sales_order_id=$order->id;
            }
            $r->fill(['warehouse_id'=>$warehouse,'approved_at'=>now(),'approved_by'=>$actor,'approved_revision'=>$r->source_revision])->save();
            return $this->summary($r);
        },3);
    }

    public function cancel(array $data,int $actor):array
    {
        $origin=FinancialOrigin::fromPayload($data);
        $permission=$data['closure_permission']??'post';abort_unless(in_array($permission,['post','unpost','void'],true),403);
        $review=['command'=>'cancel','request_uuid'=>$data['request_uuid']??null,'source_revision'=>$data['source_revision']??null,'expected_revision'=>$data['expected_revision']??null];
        if(isset($data['closure_permission']))$review+=['closure_permission'=>$data['closure_permission'],'closing_source_journal_id'=>$data['closing_source_journal_id']??null];
        $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$origin,$permission,$review);
        abort_unless(($authority['command']??null)==='cancel' && ($authority['command_source_revision']??null)===($data['source_revision']??null)
            && ($authority['expected_revision']??null)===($data['expected_revision']??null),403);
        if(isset($data['closure_permission']))abort_unless(($authority['closure_permission']??null)===$data['closure_permission']
            && (int)($authority['closing_source_journal_id']??0)===$origin->journalId,403);
        $dto=OriginRequestPayload::fromArray((array)($authority['canonical_payload']??[]));
        abort_unless($dto->origin->key()===$origin->key() && $dto->payload['request_uuid']===($data['request_uuid']??null),403);
        return DB::connection('tenant')->transaction(function()use($dto,$authority,$actor,$data){
            $proof=LockedOriginProof::verify($dto,app(ReceivingRequestService::class)->mapping(),$authority,$actor,'cancel');
            $r=FinancialOriginRequest::query()->where('organization_mapping_uuid',$proof->mapping->mapping_uuid)->where('source_document_type',$dto->origin->type)
                ->where('source_document_id',$dto->origin->documentId)->lockForUpdate()->first();
            if(!$r){
                abort_if(\App\Models\Tenant\FinancialOriginCommand::query()->where('request_uuid',$dto->payload['request_uuid'])->where('status','!=','abandoned')->exists(),409);
                $r=FinancialOriginRequest::create(['organization_id'=>app(OrganizationContext::class)->idOrFail(),'organization_mapping_uuid'=>$proof->mapping->mapping_uuid,
                    'request_uuid'=>$dto->payload['request_uuid'],'source_document_type'=>$dto->origin->type,'source_document_id'=>$dto->origin->documentId,
                    'source_document_number'=>$dto->origin->number,'source_journal_id'=>$dto->origin->journalId,'source_revision'=>$dto->payload['source_revision'],
                    'side'=>$dto->origin->domain()==='sales'?'sales':'purchase','source_payload'=>$dto->payload,'status'=>'cancelled']);
            }else{
                abort_unless($r->request_uuid===$dto->payload['request_uuid'] && (int)$r->source_journal_id===$dto->origin->journalId
                    && ($data['expected_revision']??$data['source_revision'])===$r->source_revision,409);
                if($r->status==='cancelled')return $this->summary($r);
                if($r->sales_order_id){$so=\App\Models\Tenant\SalesOrder::query()->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail();
                    if(!in_array($so->status,['shipped','cancelled'],true))app(SalesOrderService::class)->cancel($so);}
                $r->update(['status'=>'cancelled']);
            }
            return $this->summary($r);
        },3);
    }

    public function sourceStatus(array $data,int $actor):array
    {
        $origin=FinancialOrigin::fromPayload($data);
        app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$origin,'view',['request_uuid'=>$data['request_uuid']??null]);
        return $this->summary($this->find($data));
    }

    public function find(array $data):FinancialOriginRequest
    {
        $origin=FinancialOrigin::fromPayload($data);
        return FinancialOriginRequest::query()->where('request_uuid',$data['request_uuid']??'')->where('source_document_type',$origin->type)
            ->where('source_document_id',$origin->documentId)->where('source_journal_id',$origin->journalId)->firstOrFail();
    }

    public function summary(FinancialOriginRequest $r):array
    {
        $r->loadMissing('lines');return ['id'=>$r->id,'request_uuid'=>$r->request_uuid,'source_document_type'=>$r->source_document_type,
            'source_document_id'=>(int)$r->source_document_id,'source_document_number'=>$r->source_document_number,'source_journal_id'=>(int)$r->source_journal_id,
            'source_revision'=>$r->source_revision,'status'=>$r->status,'warehouse_id'=>$r->warehouse_id,'sales_order_id'=>$r->sales_order_id,
            'approved_at'=>$r->approved_at?->toIso8601String(),'approved_revision'=>$r->approved_revision,'physical_documents'=>$this->physicalDocuments($r),
            'lines'=>$r->lines->map(fn($l)=>['id'=>$l->id,'source_document_line_id'=>(int)$l->source_document_line_id,'item_id'=>(int)$l->item_id,'unit_id'=>(int)$l->unit_id,
                'requested_quantity'=>$l->requested_quantity,'fulfilled_quantity'=>$l->fulfilled_quantity,'unit_conversion_factor'=>$l->unit_conversion_factor])->all()];
    }
    /** Human Stock history exposes physical facts, never another actor's operation payload. */
    private function physicalDocuments(FinancialOriginRequest $request):array
    {
        // Human presentation may require canonical access introspection. Never add remote
        // authorization to transactional source ACKs or immutable physical event production.
        if(DB::connection('tenant')->transactionLevel()>0)return [];
        $user=request()->user();
        if(!$user || !app(InventoryPermissionService::class)->can($user,$request->side==='sales'?'inventory.view_sales':'inventory.view_stock'))return [];
        $allowed=app(WarehouseAccessService::class)->allowedIds();if($allowed===[])return [];
        $commands=\App\Models\Tenant\FinancialOriginCommand::query()->where('organization_id',$request->organization_id)
            ->where('request_uuid',$request->request_uuid)->where('source_document_type',$request->source_document_type)
            ->where('source_document_id',$request->source_document_id)->where('source_journal_id',$request->source_journal_id)
            ->where('status','completed')->orderByDesc('id')->limit(25)->get();
        $shipment=$request->side==='sales';$column=$shipment?'shipment_id':'goods_receipt_id';
        $class=$shipment?\App\Models\Tenant\Shipment::class:\App\Models\Tenant\GoodsReceipt::class;
        $ids=$commands->pluck($column)->filter()->unique()->all();if(!$ids)return [];
        $native=$class::query()->where('organization_id',$request->organization_id)->whereIn('id',$ids)
            ->when($allowed!==null,fn($query)=>$query->whereIn('warehouse_id',$allowed))->whereIn('status',['posted','reversed'])->get()->keyBy('id');
        $documents=[];
        foreach($commands as$command){
            $document=$native->get($command->{$column});if(!$document)continue;
            $documents[]=['type'=>$shipment?'shipment':'goods_receipt','id'=>(int)$document->id,
                'number'=>$shipment?$document->shipment_number:$document->grn_number,'status'=>$document->status,
                'warehouse_id'=>(int)$document->warehouse_id,'operation_uuid'=>$command->operation_uuid];
        }
        return $documents;
    }

}
