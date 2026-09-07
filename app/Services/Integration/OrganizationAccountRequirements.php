<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;

final class OrganizationAccountRequirements
{
    public function operations(int $organizationId): array
    {
        $meta = DB::connection('tenant')->table('integration_settings')
            ->where('organization_id', $organizationId)->where('integration', 'solabooks')->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true, 512, JSON_THROW_ON_ERROR) : (array) $meta;
        // An explicitly empty scope is different from absent legacy configuration.
        $operations = $meta['transport_enabled_workflows'] ?? config('integration_connection_wizard.allowed_workflows', []);
        AccountRolePolicy::forOperations($operations); // Unknown operations fail closed.
        return $operations;
    }

    public function roles(int $organizationId): array
    {
        return AccountRolePolicy::forOperations($this->operations($organizationId));
    }
    public function assertOperationReady(int $organizationId, string $operation): void
    {
        $setting = DB::connection('tenant')->table('integration_settings')
            ->where('organization_id', $organizationId)->where('integration', 'solabooks')->lockForUpdate()->first();
        if (! $setting || $setting->mode === 'disconnected') return;
        $roles = AccountRolePolicy::forOperations([$operation]);
        if ($roles === []) return;
        if (! in_array($operation, $this->operations($organizationId), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['workflow' => 'operation_not_in_reviewed_scope']);
        }
        $available = $this->validMappedRoles($organizationId);
        $missing = array_values(array_diff($roles, $available));
        if ($missing !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['account_mappings' => 'required_account_mappings_missing: '.implode(', ', $missing)]);
        }
    }
    public function validMappedRoles(int $organizationId): array
    {
        $financeOrg = DB::connection('tenant')->table('integration_organization_mappings')
            ->where('solastock_organization_id', $organizationId)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->whereIn('status', ['verified', 'verified_hold'])->value('finance_organization_id');
        return DB::connection('tenant')->table('integration_account_mappings as m')
            ->join('accounts as a', 'a.id', '=', 'm.solabooks_account_id')
            ->where('m.organization_id', $organizationId)->where('m.integration', 'solabooks')
            ->whereIn('m.status', ['mapped', 'verified'])->where('a.organization_id', $financeOrg ?? 0)
            ->where('a.is_active', true)->where('a.is_postable', true)->get(['m.mapping_type', 'a.type'])
            ->filter(fn ($row) => in_array(strtolower($row->type), AccountRolePolicy::ROLE_TYPES[$row->mapping_type] ?? [], true))->pluck('mapping_type')->all();
    }
}
