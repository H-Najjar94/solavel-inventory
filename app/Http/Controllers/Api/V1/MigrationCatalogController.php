<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Models\Tenant\{IntegrationMasterDataMapping,IntegrationOrganizationMapping,InventoryAuditLog,Item};
use App\Services\InventoryWorkspace\MigrationCatalogScope;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\{Request,JsonResponse};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable owner creation precedes Finance projection; explicit linking completes the saga. */
final class MigrationCatalogController extends ApiController
{
    private function guard(Request $request,string $action): IntegrationOrganizationMapping
    {
        abort_unless($request->attributes->get('verified_workspace_action')===$action,403,'signed_migration_workspace_required');
        return IntegrationOrganizationMapping::query()->where('solastock_organization_id',app(OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())
            ->where('status','verified')->where('activation_state','active')->firstOrFail();
    }

    private function prepare(Request $request,IntegrationOrganizationMapping $mapping): array
    {
        $data=$request->validate(['source_hash'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'name'=>'required|string|max:191','sku'=>'required|string|max:50','barcode'=>'nullable|string|max:50',
            'finance_category_id'=>'required|integer|min:1','finance_unit_id'=>'required|integer|min:1',
            'unit_price'=>['nullable','string','regex:/^[0-9]{1,12}(\.[0-9]{1,2})?$/D'],
            'item_type'=>'required|in:inventory','valuation_method'=>'required|in:fifo']);
        $references=[];
        foreach (['category'=>'finance_category_id','unit'=>'finance_unit_id'] as $type=>$field) {
            $reference=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
                ->where('entity_type',$type)->where('solabooks_record_id',(string)$data[$field])->where('status','verified')
                ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->first();
            abort_unless($reference,422,'reviewed_catalog_reference_required:'.$type);
            $references[$type]=['mapping_uuid'=>$reference->mapping_uuid,'stock_id'=>(int)$reference->solastock_record_id,'finance_id'=>(int)$reference->solabooks_record_id];
        }
        $native=['name'=>$data['name'],'sku'=>$data['sku'],'barcode'=>$data['barcode']??null,
            'category_id'=>$references['category']['stock_id'],'base_unit_id'=>$references['unit']['stock_id'],
            'item_type'=>'inventory','tracking_type'=>'none','costing_method'=>'fifo','sales_price'=>$data['unit_price']??'0','is_active'=>true];
        $form=StoreItemRequest::createFrom($request);
        $form->replace($native); $form->setContainer(app()); $form->setRedirector(app('redirect'));
        $form->validateResolved(); // Exactly the native SKU/barcode, category, unit and item-domain rules.
        $facts=['source_hash'=>$data['source_hash'],'mapping_uuid'=>$mapping->mapping_uuid,
            'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,
            'finance_organization_id'=>$mapping->finance_organization_id,'stock_organization_id'=>$mapping->solastock_organization_id,
            'references'=>$references,'native'=>$form->validated()];
        return [$facts+['version'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR))],$form];
    }

    public function requirements(Request $request): JsonResponse
    {
        $mapping=$this->guard($request,'items.migration-requirements');
        [$facts]=$this->prepare($request,$mapping);
        return $this->success($facts);
    }

    public function store(Request $request): JsonResponse
    {
        $mapping=$this->guard($request,'items.migration-create');
        $request->validate(['requirements_version'=>'required|string|regex:/^[a-f0-9]{64}$/D']);
        [$facts,$form]=$this->prepare($request,$mapping);
        abort_unless(hash_equals($facts['version'],$request->input('requirements_version')),409,'catalog_requirements_changed');
        // Dispatcher atomically commits this native controller mutation and its
        // receipt. Finance is contacted only after this owner transaction ends.
        $response=app(MigrationCatalogScope::class)->run(fn()=>app(ItemController::class)->store($form));
        $body=$response->getData(true);
        abort_unless($response->getStatusCode()===201 && (int)data_get($body,'data.id')>0,503,'native_catalog_create_failed');
        return $this->success(['stock_item_id'=>(int)$body['data']['id'],'source_hash'=>$facts['source_hash'],
            'mapping_uuid'=>$mapping->mapping_uuid,'requirements_version'=>$facts['version'],'native'=>$facts['native']],201);
    }

    public function link(Request $request): JsonResponse
    {
        $mapping=$this->guard($request,'items.migration-link');
        $data=$request->validate(['creation_key'=>'required|string|min:16|max:128','source_hash'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'stock_item_id'=>'required|integer|min:1','finance_item_id'=>'required|integer|min:1']);
        $receipt=InventoryAuditLog::query()->where('entity_type','finance_workspace_command')->where('entity_id',$mapping->id)
            ->where('actor_user_id',$request->user()->id)->where('action','items.migration-create')
            ->where('document_ref',hash('sha256',$data['creation_key']))->firstOrFail();
        abort_unless((int)data_get($receipt->after,'body.data.stock_item_id')===$data['stock_item_id']
            && hash_equals((string)data_get($receipt->after,'body.data.source_hash'),$data['source_hash']),409,'migration_catalog_receipt_mismatch');
        Item::query()->whereKey($data['stock_item_id'])->where('is_active',true)->firstOrFail();
        $existing=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type','item')
            ->where(fn($q)=>$q->where('solastock_record_id',(string)$data['stock_item_id'])->orWhere('solabooks_record_id',(string)$data['finance_item_id']))->get();
        if ($existing->isNotEmpty()) {
            abort_unless($existing->count()===1 && $existing[0]->solastock_record_id===(string)$data['stock_item_id']
                && $existing[0]->solabooks_record_id===(string)$data['finance_item_id'] && $existing[0]->status==='verified',409,'migration_catalog_identity_conflict');
            return $this->success(['mapping_uuid'=>$existing[0]->mapping_uuid,'replayed'=>true]);
        }
        $record=IntegrationMasterDataMapping::query()->create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$mapping->mapping_uuid,
            'central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,
            'finance_organization_id'=>$mapping->finance_organization_id,'solastock_organization_id'=>$mapping->solastock_organization_id,
            'entity_type'=>'item','solastock_record_id'=>(string)$data['stock_item_id'],'solabooks_record_id'=>(string)$data['finance_item_id'],
            'status'=>'verified','contract_source_version'=>'migration-catalog.v1','discovery_method'=>'signed_finance_migration',
            'last_verified_at'=>now(),'created_by_user_id'=>$request->user()->id,'updated_by_user_id'=>$request->user()->id]);
        return $this->success(['mapping_uuid'=>$record->mapping_uuid,'replayed'=>false]);
    }
}
