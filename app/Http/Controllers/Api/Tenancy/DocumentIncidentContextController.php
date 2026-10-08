<?php
namespace App\Http\Controllers\Api\Tenancy;

use App\Models\Landlord\Organization;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\User;
use App\Services\Access\{CentralAppAccess,InventoryPermissionService,WarehouseAccessService};
use App\Services\Integration\{ApprovedFinanceIntegrationEntitlement,ConnectionManagementPolicy,DocumentIncidentFacts,SolaStockJournalContract,SyncIncidentFacts,SyncIncidentNotificationPublisher};
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Signed read-only incident and native audience; never accepts caller-provided recipients. */
final class DocumentIncidentContextController
{
    public function __invoke(Request $request,TenantManager $tenants,OrganizationContext $context)
    {
        // Fail closed even in isolated environments where legacy sync middleware is disabled.
        $secret=(string)config('solavel_sync.secret');$ts=(string)$request->header('X-Solavel-Timestamp');
        $sig=preg_replace('/^sha256=/','',(string)$request->header('X-Solavel-Signature'));
        abort_unless($secret!=='' && ctype_digit($ts) && abs(time()-(int)$ts)<=(int)config('solavel_sync.allowed_skew_seconds',300)
            && hash_equals(hash_hmac('sha256',$ts.'.'.$request->getContent(),$secret),$sig),403);
        $data=$request->validate(['client_id'=>'required|integer|min:1','organization_id'=>'required|integer|min:1',
            'document_kind'=>'required|in:receipt,shipment,party,item,unit,category','outbox_id'=>'required|integer|min:1','nonce'=>'required|uuid',
            'user_ids'=>'prohibited','recipients'=>'prohibited','action_url'=>'prohibited']);
        $org=Organization::query()->where('client_id',$data['client_id'])->where('is_active',true)->findOrFail($data['organization_id']);
        $had=$request->attributes->has('tenant_state');$oldState=$request->attributes->get('tenant_state');
        $oldConnection=config('database.connections.tenant');$oldDefault=config('database.default');$oldOrg=$context->has()?$context->id():null;
        try {
            $request->attributes->set('tenant_state',['client_id'=>(int)$org->client_id,'organization_id'=>(int)$org->id]);
            $tenants->switchToDatabase($tenants->resolveDatabaseName((int)$org->client_id));$context->set((int)$org->id);
            $db=DB::connection('tenant');
            $mapping=IntegrationOrganizationMapping::query()->where('central_client_id',$org->client_id)->where('central_organization_id',$org->id)
                ->where('solastock_organization_id',$org->id)->where('tenant_database_identity',$db->getDatabaseName())
                ->where('integration','solabooks')->where('status','verified')->where('activation_state','active')->firstOrFail();
            app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
            if(in_array($data['document_kind'],SyncIncidentFacts::KINDS,true))return $this->syncContext($data,$org,$mapping);
            $sales=$data['document_kind']==='shipment';
            $row=$db->table($sales?'sales_document_outbox':'purchasing_document_outbox')->where('organization_id',$org->id)->where('id',$data['outbox_id'])->first();
            abort_unless($row,404);$payload=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);
            abort_unless(($payload['identity']['organization_mapping_uuid']??null)===$mapping->mapping_uuid
                && (int)data_get($payload,'identity.central_client_id')===(int)$mapping->central_client_id
                && (int)data_get($payload,'identity.central_organization_id')===(int)$org->id
                && (int)data_get($payload,'identity.finance_organization_id')===(int)$mapping->finance_organization_id
                && (int)data_get($payload,'identity.inventory_organization_id')===(int)$org->id
                && ($payload['event_type']??null)===($sales?'sales.shipment.confirmed':'purchasing.receipt.confirmed')
                && hash_equals($row->payload_hash,hash('sha256',SolaStockJournalContract::canonicalJson($payload))),404);
            $native=$db->table($sales?'shipments':'goods_receipts')->where('organization_id',$org->id)->where('id',data_get($payload,$sales?'shipment.id':'receipt.id'))->first();
            abort_unless($native && (int)$native->warehouse_id>0,404);
            $facts=DocumentIncidentFacts::fromRows($data['document_kind'],$row,$native,$mapping->mapping_uuid);
            $members=DB::connection((string)config('tenancy.central_connection','mysql'))->table('user_organizations')
                ->where('organization_id',$org->id)->where(fn($q)=>$q->whereNull('status')->orWhere('status','active'))->pluck('user_id');
            $managers=[];$operators=[];
            foreach(User::query()->whereIn('id',$members)->get() as $user) {
                $permissions=app(InventoryPermissionService::class);
                $manager=$permissions->can($user,ConnectionManagementPolicy::MANAGEMENT_PERMISSION) && $permissions->can($user,'inventory.integration.view');
                $operator=(int)($native->created_by??0)===(int)$user->id && $permissions->can($user,$sales?'inventory.manage_shipments':'inventory.receive_goods');
                if (!$manager && !$operator) continue;
                if (!(app(CentralAppAccess::class)->decision((int)$user->id,(int)$org->id,'inventory')['allowed']??false)) continue;
                $warehouses=$operator?app(WarehouseAccessService::class)->allowedIds((int)$user->id):null;
                if ($operator && $warehouses!==null && !in_array((int)$native->warehouse_id,$warehouses,true)) $operator=false;
                if ($manager) $managers[]=(int)$user->id;
                elseif ($operator) $operators[]=(int)$user->id;
            }
            return response()->json(['app_key'=>'inventory','client_id'=>(int)$org->client_id,'organization_id'=>(int)$org->id,
                'document_kind'=>$data['document_kind'],'outbox_id'=>(int)$row->id,'facts'=>$facts,
                'fingerprint'=>DocumentIncidentFacts::fingerprint($facts),'eligible_manager_ids'=>$managers,'eligible_operator_ids'=>$operators])->header('Cache-Control','no-store');
        } finally {
            if($had)$request->attributes->set('tenant_state',$oldState);else $request->attributes->remove('tenant_state');
            $context->forget();if($oldOrg!==null)$context->set((int)$oldOrg);DB::purge('tenant');
            config(['database.connections.tenant'=>$oldConnection,'database.default'=>$oldDefault]);
            app()->forgetInstance(InventoryPermissionService::class);app()->forgetInstance(WarehouseAccessService::class);
        }
    }
    /**
     * Party/catalog sync incident (stock-sync-incident.v1). Audience is integration
     * administrators only: inventory.integration.manage + inventory.integration.view with
     * Central app access. There is never an operator audience for sync incidents.
     */
    private function syncContext(array $data,object $org,object $mapping)
    {
        $db=DB::connection('tenant');$kind=$data['document_kind'];
        $row=$db->table(SyncIncidentFacts::table($kind))->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('id',$data['outbox_id'])->first();
        abort_unless($row && ($kind==='party' || $row->entity_type===$kind),404);
        $facts=app(SyncIncidentNotificationPublisher::class)->currentFacts($kind,$row,$mapping,(int)$org->id);
        abort_unless($facts,404);
        $members=DB::connection((string)config('tenancy.central_connection','mysql'))->table('user_organizations')
            ->where('organization_id',$org->id)->where(fn($q)=>$q->whereNull('status')->orWhere('status','active'))->pluck('user_id');
        $managers=[];$permissions=app(InventoryPermissionService::class);
        foreach(User::query()->whereIn('id',$members)->get() as $user) {
            if (!$permissions->can($user,'inventory.integration.manage') || !$permissions->can($user,'inventory.integration.view')) continue;
            if (!(app(CentralAppAccess::class)->decision((int)$user->id,(int)$org->id,'inventory')['allowed']??false)) continue;
            $managers[]=(int)$user->id;
        }
        return response()->json(['app_key'=>'inventory','client_id'=>(int)$org->client_id,'organization_id'=>(int)$org->id,
            'document_kind'=>$kind,'outbox_id'=>(int)$row->id,'facts'=>$facts,'fingerprint'=>SyncIncidentFacts::fingerprint($facts),
            'eligible_manager_ids'=>$managers,'eligible_operator_ids'=>[]])->header('Cache-Control','no-store');
    }
}
