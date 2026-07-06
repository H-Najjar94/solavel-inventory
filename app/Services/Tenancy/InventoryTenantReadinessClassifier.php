<?php

namespace App\Services\Tenancy;

class InventoryTenantReadinessClassifier
{
    public function classify(array $tenant): array
    {
        $tenantKey = (string) ($tenant['tenant_key'] ?? '');
        $schemaStatus = (string) ($tenant['schema_status'] ?? 'unknown');
        $integrityStatus = (string) ($tenant['integrity_status'] ?? 'not_run');
        $accessStatus = (string) ($tenant['access_status'] ?? 'unknown');
        $inventoryEnabled = (bool) ($tenant['inventory_enabled'] ?? false);
        $isQa = str_starts_with($tenantKey, 'tenant_99');

        if (! ($tenant['db_exists'] ?? false)) {
            return $this->result('provisioning_needed', 'Tenant database is missing; keep disabled until provisioned.');
        }

        if ($schemaStatus !== 'pass') {
            $missingTables = (int) ($tenant['missing_tables_count'] ?? 0);

            return $this->result(
                $missingTables > 0 && $missingTables < 22 ? 'schema_repair_needed' : 'provisioning_needed',
                'SolaStock schema does not pass; do not show as usable.'
            );
        }

        if ($accessStatus === 'no_safe_access_path') {
            return $this->result('no_safe_access_path', 'Schema exists but no Central org/user access path is available.');
        }

        if ($integrityStatus === 'fail') {
            if (! $inventoryEnabled) {
                return $this->result('disabled_stale_integrity_failed', 'Disabled tenant has stale SolaStock data with integrity failures.');
            }

            return $this->result('blocked_by_data_integrity', 'Enabled/candidate tenant has integrity failures.');
        }

        if (! $inventoryEnabled) {
            return $this->result('disabled_not_ready', 'SolaStock is disabled for this tenant.');
        }

        if ($isQa) {
            return $this->result('qa_ok', 'QA tenant passes schema, integrity, and access checks.');
        }

        return $this->result('production_ok', 'Tenant passes schema, integrity, and access checks.');
    }

    private function result(string $status, string $action): array
    {
        return [
            'final_status' => $status,
            'recommended_action' => $action,
        ];
    }
}
