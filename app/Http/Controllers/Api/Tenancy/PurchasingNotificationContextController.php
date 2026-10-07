<?php
namespace App\Http\Controllers\Api\Tenancy;

use App\Models\Landlord\Organization;
use App\Models\Tenant\{IntegrationOrganizationMapping, ReceivingRequest};
use App\Models\User;
use App\Services\Access\{CentralAppAccess, InventoryPermissionService, WarehouseAccessService};
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Signed read-only native warehouse authority, never trusts a caller's recipients. */
final class PurchasingNotificationContextController
{
    public function __invoke(Request $request, TenantManager $tenants, OrganizationContext $context)
    {
        $data=$request->validate(['client_id'=>'required|integer|min:1','organization_id'=>'required|integer|min:1','request_id'=>'required|integer|min:1','nonce'=>'required|uuid']);
        $org=Organization::whereKey($data['organization_id'])->where('client_id',$data['client_id'])->where('is_active',true)->firstOrFail();
        $oldConnection=config('database.connections.tenant');$oldDefault=config('database.default');$oldOrg=$context->has()?$context->id():null;
        try {
            $tenants->switchToDatabase($tenants->resolveDatabaseName($data['client_id']));$context->set((int)$org->id);
            $mapping=IntegrationOrganizationMapping::query()->where('central_client_id',$data['client_id'])->where('central_organization_id',$org->id)->where('solastock_organization_id',$org->id)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->where('integration','solabooks')->where('status','verified')->where('activation_state','active')->firstOrFail();
            if (config('inventory_entitlements.feature_enforcement',false)) abort_unless(app(\App\Services\Entitlements\InventoryCommercialEntitlementService::class)->checkPermission('inventory.receive_goods')['allowed']??false,403);
            $rr=ReceivingRequest::query()->with('lines')->where('organization_id',$org->id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->findOrFail($data['request_id']);
            $members=DB::connection(config('tenancy.central_connection','mysql'))->table('user_organizations')->where('organization_id',$org->id)->where(fn($q)=>$q->whereNull('status')->orWhere('status','active'))->pluck('user_id');
            $activeWarehouseExists=DB::connection('tenant')->table('warehouses')->where('organization_id',$org->id)->where('is_active',true)->exists();
            $recipients=[];$warehouse=(int)($rr->warehouse_id??0);
            foreach(User::query()->whereIn('id',$members)->get() as $user) {
                $decision=app(CentralAppAccess::class)->decision((int)$user->id,(int)$org->id,'inventory');
                if (!($decision['allowed']??false) || !app(InventoryPermissionService::class)->can($user,'inventory.receive_goods')) continue;
                $approver=app(InventoryPermissionService::class)->can($user,'inventory.approve_purchase_orders') || app(InventoryPermissionService::class)->can($user,'inventory.manage_adjustments');
                if (($warehouse===0 || !$rr->approved_at) && !$approver) continue;
                $allowed=app(WarehouseAccessService::class)->allowedIds((int)$user->id);
                if ($warehouse>0 && $allowed!==null && !in_array($warehouse,$allowed,true)) continue;
                if ($warehouse===0 && !$approver) continue;
                if ($warehouse>0 && !DB::connection('tenant')->table('warehouses')->where('organization_id',$org->id)->where('id',$warehouse)->where('is_active',true)->exists()) continue;
                $recipients[]=(int)$user->id;
            }
            $lines=$rr->lines->map(fn($line)=>['id'=>(int)$line->id,'requested'=>(string)$line->requested_qty,'received'=>(string)$line->received_qty])->sortBy('id')->values()->all();
            $facts=['request_uuid'=>(string)$rr->request_uuid,'source_revision'=>(string)$rr->source_revision,'status'=>(string)$rr->status,'source_bill_id'=>(int)$rr->source_bill_id,'source_bill_number'=>(string)$rr->source_bill_number,'warehouse_id'=>$warehouse,'approved'=>(bool)$rr->approved_at,'warehouse_setup_required'=>!$activeWarehouseExists,'lines'=>$lines];
            return response()->json(['client_id'=>(int)$data['client_id'],'organization_id'=>(int)$org->id,'request_id'=>(int)$rr->id,'app_key'=>'inventory','facts'=>$facts,'fingerprint'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR)),'eligible_user_ids'=>$recipients])->header('Cache-Control','no-store');
        } finally {
            $context->forget();if($oldOrg!==null)$context->set((int)$oldOrg);DB::purge('tenant');config(['database.connections.tenant'=>$oldConnection,'database.default'=>$oldDefault]);
            app()->forgetInstance(InventoryPermissionService::class);
        }
    }
}
