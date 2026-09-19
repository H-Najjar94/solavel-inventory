<?php
namespace App\Services\Integration;

use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Same Finance lifecycle contract as Projects: fresh shared-tenant organization
 * completion markers, independently of product grants, mappings, and holds.
 * Stock organization IDs are CENTRAL IDs; Finance wizard IDs are tenant-local.
 */
class FinanceOnboardingReadiness
{
    public function resolve(int $centralOrgId, ?object $user = null): array
    {
        $result=['state'=>'READINESS_UNAVAILABLE','readiness_available'=>false,'finance_setup_complete'=>false,
            'finance_provisioned'=>false,'premium_entitled'=>false,'can_manage'=>false,'setup_url'=>null,
            'manage_access_url'=>rtrim((string)config('tenancy.parent_base_url'),'/').'/portal/orgs/by-id/'.$centralOrgId.'/projects','checked_at'=>now()->toIso8601String()];
        try {
            $access=app(InventoryCommercialEntitlementService::class)->checkConnectionSetupReadiness($centralOrgId);
            if (str_contains($access['reason_code'],'unavailable') || str_contains($access['reason_code'],'identity')) return $result;
            [$org, $provisioned, $complete] = $this->setupFacts($centralOrgId);
            $policy=app(ConnectionManagementPolicy::class)->status($centralOrgId,$user);
            $manage=(bool)($policy['can_manage_connection']??false);
            $entitled=(bool)$access['allowed'];
            $central=rtrim((string)config('tenancy.parent_base_url'),'/');
            $url=$manage && $entitled && $provisioned && !$complete && $central
                ? $central.'/sso/finance/redirect?'.http_build_query(['organization_id'=>$centralOrgId,
                    'intended_url'=>'/settings/organizations/'.$org->id.'/enter?'.http_build_query(['central_organization_id'=>$centralOrgId])]) : null;
            return array_replace($result,['state'=>!$entitled?'ACCESS_REQUIRED':(!$provisioned?'PROVISIONING_PENDING':(!$complete?'FINANCE_PROVISIONED_SETUP_INCOMPLETE':'FINANCE_READY')),
                'readiness_available'=>true,'finance_setup_complete'=>(bool)$complete,'finance_provisioned'=>(bool)$provisioned,
                'premium_entitled'=>$entitled,'can_manage'=>$manage,'setup_url'=>$url]);
        } catch (\Throwable $e) {report($e);return $result;}
    }
    public function assertComplete(int $centralOrgId):void
    {
        try {
            [, $provisioned, $complete] = $this->setupFacts($centralOrgId);
            if ($provisioned && $complete) return;
        } catch (\Throwable) {
            // Missing schema or unreadable Finance source is never readiness.
        }
        throw new \App\Exceptions\FinanceSetupRequired();
    }
    /** Finance-owned tenant facts; usable by workers without an HTTP session.
     * Commercial authorization stays in ApprovedFinanceIntegrationEntitlement.
     */
    private function setupFacts(int $centralOrgId): array
    {
        $org=Schema::connection('tenant')->hasTable('organizations')
            ? DB::connection('tenant')->table('organizations')->where('central_org_id',$centralOrgId)->first() : null;
        if ($org && (!property_exists($org,'setup_status') || !property_exists($org,'finance_setup_completed_at'))) {
            throw new RuntimeException('Finance readiness contract unavailable.');
        }
        $provisioned=$org && Schema::connection('tenant')->hasTable('accounts') && Schema::connection('tenant')->hasTable('invoices');
        $complete=$org && $org->setup_status==='complete' && $org->finance_setup_completed_at!==null;
        return [$org, $provisioned, $complete];
    }

}
