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
                ? $this->result('entitled_not_provisioned', 'SolaStock is enabled but its tenant database is not provisioned.')
                : $this->result('not_entitled', 'Tenant is not entitled to SolaStock and no inventory database is provisioned.');
        }

        if (! $inventoryEnabled) {
            return $this->result('not_entitled', 'Tenant is not currently entitled to SolaStock.');
        }

        if ($schemaStatus !== 'pass') {
            $missingTables = (int) ($tenant['missing_tables_count'] ?? 0);

            return $this->result('migrations_incomplete', 'Tenant is reachable but required SolaStock migrations are incomplete.');
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
