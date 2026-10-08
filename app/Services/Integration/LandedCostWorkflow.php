<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Landed costs in a connected organization journal to a reviewed
 * `landed_cost_clearing` account. Existing connections were approved without
 * that workflow, so an owner enables it explicitly: the clearing account is
 * bound as a verified, immutable account-role mapping (the same evidence the
 * connection wizard and the default connection write, which Finance trusts),
 * and exactly `landed_cost.posted` / `landed_cost.reversed` are added to the
 * organization's transport workflows. Nothing else in the reviewed scope widens.
 */
final class LandedCostWorkflow
{
    public const VERSION = 'landed-cost-workflow.v1';

    public const OPERATIONS = ['landed_cost.posted', 'landed_cost.reversed'];

    public function status(int $organizationId): array
    {
        $setting = IntegrationSetting::query()->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION)->first();
        $mapping = $this->mapping($organizationId);
        if (! $mapping || ! $setting || $setting->mode === 'disconnected') {
            return ['mode' => $mapping ? 'connection_required' : 'standalone', 'enabled' => ! $mapping,
                'base_currency' => null, 'enabled_currencies' => [], 'missing_roles' => [], 'clearing_account' => null, 'candidates' => []];
        }
        $workflows = (array) data_get($setting->meta, 'transport_enabled_workflows', []);
        $enabled = count(array_intersect(self::OPERATIONS, $workflows)) === count(self::OPERATIONS);
        $requirements = app(OrganizationAccountRequirements::class);
        $valid = $requirements->validMappedRoles($organizationId);
        $missing = array_values(array_diff(AccountRolePolicy::forOperations(self::OPERATIONS), $valid));
        $bound = $this->boundToOtherRoles($organizationId, $mapping);
        $clearingId = (int) DB::connection('tenant')->table('integration_account_mappings')->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION)->where('mapping_type', 'landed_cost_clearing')
            ->whereIn('status', ['mapped', 'verified'])->value('solabooks_account_id');

        return [
            'mode' => 'connected',
            'enabled' => $enabled && $missing === [],
            'workflow_enabled' => $enabled,
            'base_currency' => data_get($setting->meta, 'finance_currency_contract.base_currency_code'),
            'enabled_currencies' => array_values((array) data_get($setting->meta, 'finance_currency_contract.enabled_currency_codes', [])),
            'missing_roles' => $missing,
            'clearing_account' => $clearingId ? $this->present($this->accounts((int) $mapping->finance_organization_id)->firstWhere('id', $clearingId)) : null,
            'candidates' => $this->accounts((int) $mapping->finance_organization_id)
                ->reject(fn ($a) => in_array((string) $a->id, $bound, true))
                ->sortBy(fn ($a) => [$this->rank($a), (string) $a->code])->values()->map(fn ($a) => $this->present($a))->all(),
        ];
    }

    public function enable(int $organizationId, int $financeAccountId, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $financeAccountId, $actorUserId): void {
            $setting = IntegrationSetting::query()->where('organization_id', $organizationId)
                ->where('integration', IntegrationEvents::INTEGRATION)->lockForUpdate()->first();
            $mapping = $this->mapping($organizationId);
            if (! $setting || ! $mapping || $setting->mode === 'disconnected' || $mapping->status !== 'verified') {
                $this->fail('connection_required');
            }
            $account = $this->accounts((int) $mapping->finance_organization_id)->firstWhere('id', $financeAccountId);
            if (! $account) {
                $this->fail('clearing_account_invalid');
            }
            $inventory = (int) DB::connection('tenant')->table('integration_account_mappings')->where('organization_id', $organizationId)
                ->where('integration', IntegrationEvents::INTEGRATION)->where('mapping_type', 'inventory_asset')->value('solabooks_account_id');
            if ($inventory === $financeAccountId) {
                $this->fail('clearing_same_as_inventory');
            }
            $key = ['organization_id' => $organizationId, 'integration' => IntegrationEvents::INTEGRATION, 'mapping_type' => 'landed_cost_clearing'];
            $existing = DB::connection('tenant')->table('integration_account_mappings')->where($key)->lockForUpdate()->first();
            $stable = $existing ? IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->where('entity_type', 'account_role')->where('solastock_record_id', (string) $existing->id)->first() : null;
            if ($stable && (string) $stable->solabooks_record_id !== (string) $financeAccountId) {
                // A reviewed binding is immutable; changing it needs the connection review.
                $this->fail('clearing_immutable');
            }
            // An account already bound to another role (GRNI, AP, input VAT, ...) is the wrong
            // account for clearing, and its account_role binding would violate imdm_org_type_books_uniq.
            if (! $stable && in_array((string) $financeAccountId, $this->boundToOtherRoles($organizationId, $mapping), true)) {
                $this->fail('clearing_account_bound');
            }
            $name = json_decode((string) $account->name, true);
            $values = ['solabooks_account_id' => (string) $financeAccountId, 'account_code' => $account->code,
                'account_name' => mb_substr(is_array($name) ? (string) ($name['en'] ?? reset($name)) : (string) $account->name, 0, 191),
                'status' => 'verified', 'notes' => self::VERSION, 'last_verified_at' => now(), 'updated_at' => now()];
            if ($existing) {
                DB::connection('tenant')->table('integration_account_mappings')->where('id', $existing->id)->update($values);
                $mappingRowId = (int) $existing->id;
            } else {
                $mappingRowId = (int) DB::connection('tenant')->table('integration_account_mappings')->insertGetId($key + $values + ['created_at' => now()]);
            }
            if (! $stable) {
                IntegrationMasterDataMapping::query()->create([
                    'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id,
                    'finance_organization_id' => $mapping->finance_organization_id, 'solastock_organization_id' => $organizationId,
                    'entity_type' => 'account_role', 'solastock_record_id' => (string) $mappingRowId,
                    'solabooks_record_id' => (string) $financeAccountId, 'status' => 'verified',
                    'contract_source_version' => self::VERSION, 'discovery_method' => 'owner_landed_cost_enablement',
                    'last_verified_at' => now(), 'created_by_user_id' => $actorUserId, 'updated_by_user_id' => $actorUserId,
                ]);
            }
            $meta = (array) $setting->meta;
            $meta['transport_enabled_workflows'] = self::withWorkflow((array) ($meta['transport_enabled_workflows'] ?? []));
            $meta['landed_cost_workflow'] = ['version' => self::VERSION, 'state' => 'enabled', 'finance_account_id' => $financeAccountId,
                'enabled_at' => now()->toIso8601String(), 'actor_id' => $actorUserId];
            $setting->update(['meta' => $meta]);
            InventoryAuditLog::create([
                'organization_id' => $organizationId, 'actor_user_id' => $actorUserId,
                'action' => 'inventory.solabooks_landed_cost_workflow.enabled', 'entity_type' => 'integration_settings',
                'entity_id' => $setting->id, 'after' => ['finance_account_id' => $financeAccountId, 'operations' => self::OPERATIONS],
                'created_at' => now(),
            ]);
        });
        app(IntegrationOutboxService::class)->refreshMappingStatus($organizationId);

        return $this->status($organizationId);
    }

    /** Re-applied by activation/resume so a restored run allowlist keeps an enabled landed-cost workflow. */
    public static function preserve(array $meta, array $workflows): array
    {
        return data_get($meta, 'landed_cost_workflow.state') === 'enabled' ? self::withWorkflow($workflows) : array_values($workflows);
    }

    private static function withWorkflow(array $workflows): array
    {
        return array_values(array_unique(array_merge(array_values($workflows), self::OPERATIONS)));
    }

    /**
     * Finance account ids already used by another account role of this connection:
     * reviewed account_role bindings and active role mappings, except the
     * landed_cost_clearing mapping itself.
     *
     * @return list<string>
     */
    private function boundToOtherRoles(int $organizationId, IntegrationOrganizationMapping $mapping): array
    {
        $roles = DB::connection('tenant')->table('integration_account_mappings')->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION);
        $clearingRowIds = (clone $roles)->where('mapping_type', 'landed_cost_clearing')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $mapped = (clone $roles)->where('mapping_type', '!=', 'landed_cost_clearing')->whereIn('status', ['mapped', 'verified'])
            ->whereNotNull('solabooks_account_id')->pluck('solabooks_account_id')->map(fn ($id) => (string) $id)->all();
        $bound = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', 'account_role')->whereNotIn('solastock_record_id', $clearingRowIds ?: ['0'])
            ->pluck('solabooks_record_id')->map(fn ($id) => (string) $id)->all();

        return array_values(array_unique(array_merge($mapped, $bound)));
    }

    private function mapping(int $organizationId): ?IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->where('solastock_organization_id', $organizationId)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->first();
    }

    private function accounts(int $financeOrganizationId)
    {
        if (! DB::connection('tenant')->getSchemaBuilder()->hasTable('accounts')) {
            return collect();
        }

        return DB::connection('tenant')->table('accounts')->where('organization_id', $financeOrganizationId)
            ->where('is_active', true)->where('is_postable', true)->whereIn('type', AccountRolePolicy::ROLE_TYPES['landed_cost_clearing'])
            ->orderBy('code')->get();
    }

    private function rank(object $account): int
    {
        return match (true) {
            (string) ($account->account_role ?? '') === 'landed_cost_clearing' || (string) ($account->system_key ?? '') === 'landed_cost_clearing' => 0,
            (string) ($account->system_key ?? '') === 'asset_clearing' || (string) $account->code === '1580' => 1,
            default => 2,
        };
    }

    private function present(?object $account): ?array
    {
        if (! $account) {
            return null;
        }
        $name = json_decode((string) $account->name, true);

        return ['id' => (int) $account->id, 'code' => (string) $account->code, 'name' => is_array($name) ? $name : (string) $account->name,
            'type' => (string) $account->type, 'recommended' => $this->rank($account) < 2];
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.'.$key)]);
    }
}
