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

        $databaseStatus = (string) ($tenant['database_status'] ?? (($tenant['db_exists'] ?? false) ? 'reachable' : 'missing'));

        if ($databaseStatus === 'unreachable') {
            return $this->result('runtime_unreachable', 'Tenant runtime connection failed; investigate credentials or database availability.');
        }

        if ($databaseStatus === 'missing') {
            return $inventoryEnabled
                ? $this->result('entitled_not_provisioned', __('inventory.tenancy.enabled_unprovisioned'))
                : $this->result('not_entitled', __('inventory.tenancy.not_entitled_unprovisioned'));
        }

        if (! $inventoryEnabled) {
            return $this->result('not_entitled', __('inventory.tenancy.not_entitled'));
        }

        if ($schemaStatus !== 'pass') {
            $missingTables = (int) ($tenant['missing_tables_count'] ?? 0);

            return $this->result('migrations_incomplete', __('inventory.tenancy.migrations_incomplete'));
        }

        if ($accessStatus === 'no_safe_access_path') {
            return $this->result('no_safe_access_path', 'Schema exists but no Central org/user access path is available.');
        }

        if ($integrityStatus === 'fail') {
            if (! $inventoryEnabled) {
                return $this->result('disabled_stale_integrity_failed', __('inventory.tenancy.disabled_integrity_failed'));
            }

            return $this->result('blocked_by_data_integrity', 'Enabled/candidate tenant has integrity failures.');
        }

        if ($isQa) {
            return $this->result('qa_ok', __('inventory.tenancy.qa_ok'));
        }

        return $this->result('production_ok', __('inventory.tenancy.production_ok'));
    }

    private function result(string $status, string $action): array
    {
        return [
            'final_status' => $status,
            'recommended_action' => $action,
        ];
    }
}
