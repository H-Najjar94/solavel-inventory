<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Configuration only. Custom mappings and organizations with history are never auto-activated. */
final class DefaultStockConnection
{
    public const VERSION = 'default-stock-connection.v1';

    public function initialize(int $clientId, int $orgId, int $financeId, int $actorId, bool $apply = true): array
    {
        $db = DB::connection('tenant');
        if ($db->getDatabaseName() !== app(\App\Services\Tenancy\TenantManager::class)->resolveDatabaseName($clientId)
            || ! $db->table('organizations')->where('id',$financeId)->where('central_org_id',$orgId)->exists()) {
            throw new RuntimeException('default_connection_identity_invalid');
        }
        app(FinanceOnboardingReadiness::class)->assertComplete($orgId);
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved(new IntegrationOrganizationMapping([
            'central_client_id'=>$clientId, 'central_organization_id'=>$orgId]));
        $lock = 'stock-default:'.substr(hash('sha256',$db->getDatabaseName().':'.$orgId),0,48);
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired',[$lock])->acquired !== 1) {
            throw new RuntimeException('default_connection_retry_in_progress');
        }
        try {
            $setting = IntegrationSetting::query()->where('organization_id',$orgId)->where('integration','solabooks')->first();
            $owned = data_get($setting?->meta, 'default_connection.version') === self::VERSION;
            $mapping = IntegrationOrganizationMapping::query()->where('central_organization_id',$orgId)->first();
            if ($owned && $mapping?->status === 'verified' && $mapping?->activation_state === 'active'
                && $setting?->mode === 'active'
                && app(OrganizationAccountRequirements::class)->missingRoles($orgId) === []) {
                return ['status'=>'ready','changed'=>false,'organization_id'=>$orgId];
            }
            if (($setting || $mapping || $db->table('integration_account_mappings')->where('organization_id',$orgId)->exists()) && ! $owned) {
                return ['status'=>'manual_review','reason'=>'existing_connection_preserved','organization_id'=>$orgId];
            }
            PristineStockHistory::assertEmpty($financeId,$orgId);
            $payload = ['client_id'=>$clientId,'organization_id'=>$orgId,'finance_organization_id'=>$financeId,
                'actor_id'=>$actorId,'idempotency_key'=>'default-connection-'.$orgId];
            $plan = app(FinanceConnectionClient::class)->command($payload+['action'=>'connection.default-plan']);
            if (($plan['version']??null) !== self::VERSION || ($plan['organization_id']??null) !== $orgId
                || ($plan['finance_organization_id']??null) !== $financeId) throw new RuntimeException('default_plan_scope_invalid');
            $operations = array_values(config('integration_connection_wizard.allowed_workflows', []));
            foreach (AccountRolePolicy::forOperations($operations) as $role) {
                if (empty($plan['accounts'][$role])) throw new RuntimeException('default_required_role_missing:'.$role);
            }
            $this->validateAccounts($plan,$financeId);
            if (! $apply) return ['status'=>'eligible','organization_id'=>$orgId,'roles'=>array_keys($plan['accounts'])];
            foreach (['activation_enabled','production_phase6b_enabled','receiver_confirmed_enabled'] as $gate) {
                if (! config('integration_connection_wizard.'.$gate)) throw new RuntimeException('default_connection_activation_gate_closed');
            }
            app(IntegrationSafetyHold::class)->assertDeliveryEnabledFor($orgId);
            $setting ??= IntegrationSetting::query()->create(['organization_id'=>$orgId,'integration'=>'solabooks',
                'solabooks_organization_id'=>$financeId,'mode'=>'connected_pending_mapping','require_mapping_before_post'=>true,
                'meta'=>['client_id'=>$clientId,'central_organization_id'=>$orgId,'transport_enabled'=>false,
                    'default_connection'=>['version'=>self::VERSION,'state'=>'preparing']]]);
            $credentials = app(FinanceConnectionClient::class)->command($payload+['action'=>'connection.prepare']);
            return $db->transaction(function () use ($db,$orgId,$financeId,$clientId,$actorId,$plan,$operations,$credentials): array {
                $db->table('organizations')->where('id',$financeId)->lockForUpdate()->first();
                PristineStockHistory::assertEmpty($financeId,$orgId);
                $this->validateAccounts($plan,$financeId);
                $mapping = IntegrationOrganizationMapping::query()->where('central_client_id',$clientId)
                    ->where('central_organization_id',$orgId)->where('finance_organization_id',$financeId)
                    ->where('solastock_organization_id',$orgId)->where('tenant_database_identity',$db->getDatabaseName())
                    ->lockForUpdate()->firstOrFail();
                if (! $mapping->current_v2_signing_key_id || ! in_array($mapping->v2_key_scope_status,['provisioned_held','active'],true)) {
                    throw new RuntimeException('default_connection_credentials_not_ready');
                }
                $setting = IntegrationSetting::query()->where('organization_id',$orgId)->where('integration','solabooks')->lockForUpdate()->firstOrFail();
                foreach ($plan['accounts'] as $role=>$a) {
                    $key = ['organization_id'=>$orgId,'integration'=>'solabooks','mapping_type'=>$role];
                    $existing = $db->table('integration_account_mappings')->where($key)->first();
                    if ($existing && (! str_starts_with((string)$existing->notes,self::VERSION) || (string)$existing->solabooks_account_id !== (string)$a['id'])) {
                        throw new RuntimeException('custom_mapping_preserved:'.$role);
                    }
                    if (! $existing) {
                        $id = $db->table('integration_account_mappings')->insertGetId($key+[
                            'solabooks_account_id'=>(string)$a['id'],'account_code'=>$a['code'],'account_name'=>mb_substr((json_decode((string)$a['name'],true)['en'] ?? (string)$a['name']),0,255),
                            'status'=>'verified','notes'=>self::VERSION,'last_verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                    } else $id = $existing->id;
                    $stable = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
                        ->where('entity_type','account_role')->where('solastock_record_id',(string)$id)->first();
                    if ($stable && ((string)$stable->solabooks_record_id !== (string)$a['id'] || $stable->status !== 'verified')) {
                        throw new RuntimeException('immutable_role_requires_review:'.$role);
                    }
                    if (! $stable) IntegrationMasterDataMapping::query()->create([
                        'mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$mapping->mapping_uuid,
                        'central_client_id'=>$clientId,'central_organization_id'=>$orgId,'finance_organization_id'=>$financeId,
                        'solastock_organization_id'=>$orgId,'entity_type'=>'account_role','solastock_record_id'=>(string)$id,
                        'solabooks_record_id'=>(string)$a['id'],'status'=>'verified','contract_source_version'=>self::VERSION,
                        'discovery_method'=>'canonical_pristine_chart','last_verified_at'=>now(),
                        'created_by_user_id'=>$actorId,'updated_by_user_id'=>$actorId]);
                }
                $meta = (array)$setting->meta;
                $meta['api_key_encrypted'] = Crypt::encryptString((string)$credentials['api_key']);
                $meta['signing_key_id'] = (string)$credentials['signing_key_id'];
                $meta['signing_secret_encrypted'] = Crypt::encryptString((string)$credentials['signing_secret']);
                $meta['signing_protocol_version'] = ExternalRequestSignature::VERSION;
                $meta['transport_enabled'] = true;
                $meta['transport_enabled_workflows'] = $operations;
                $meta['finance_currency_contract'] = $plan['currency'];
                $meta['default_connection'] = ['version'=>self::VERSION,'state'=>'ready','configured_at'=>now()->toIso8601String(),
                    'actor_id'=>$actorId,'roles'=>array_keys($plan['accounts']),'historical_policy'=>'no_history_no_replay'];
                $setting->update(['mode'=>'active','meta'=>$meta]);
                $mapping->update(['status'=>'verified','activation_state'=>'active']);
                if (app(OrganizationAccountRequirements::class)->missingRoles($orgId) !== []) throw new RuntimeException('default_mapping_validation_failed');
                app(\App\Services\Catalog\FinanceReferenceDefaultsService::class)->sync($orgId,true);
                return ['status'=>'ready','changed'=>true,'organization_id'=>$orgId,'roles'=>array_keys($plan['accounts'])];
            },3);
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);
        }
    }

    private function validateAccounts(array $plan, int $financeId): void
    {
        foreach ($plan['accounts'] as $role=>$a) {
            $account = DB::connection('tenant')->table('accounts')->where('organization_id',$financeId)->where('id',$a['id'])
                ->where('is_active',true)->where('is_postable',true)->first();
            if (! $account || $account->type !== $a['type'] || $account->system_key !== $a['system_key']
                || $account->account_role !== $a['account_role'] || (string)$account->code !== (string)$a['code']
                || (isset(AccountRolePolicy::ROLE_TYPES[$role]) && ! in_array($account->type,AccountRolePolicy::ROLE_TYPES[$role],true))) {
                throw new RuntimeException('default_account_invalid:'.$role);
            }
        }
    }
}
