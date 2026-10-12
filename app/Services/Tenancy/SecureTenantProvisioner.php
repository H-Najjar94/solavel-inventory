<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only runtime verification of Central's approved static SPSB setup.
 * Database creation, schema import and grants belong to the Central worker.
 * No runtime setting may fall back to historical migrations.
 */
class SecureTenantProvisioner
{
    private function isForbidden(string $db): bool
    {
        return $db === '' || in_array($db, (array) config('inventory.forbidden_demo_databases', []), true);
    }

    public function isInventoryReady(string $db): bool
    {
        try {
            if ($this->isForbidden($db) || DB::connection('tenant')->getDatabaseName() !== $db) {
                return false;
            }

            return (bool) app(TenantSchemaAuditService::class)->audit('tenant')['ok'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Verify an existing SPSB schema; never create a database or apply DDL. */
    public function provisionInventory(int $clientId, string $db): array
    {
        if ($clientId <= 0) {
            throw new RuntimeException('provisionInventory requires a positive client id.');
        }
        if ($this->isForbidden($db)) {
            throw new RuntimeException("Refusing to provision forbidden database '{$db}'.");
        }
        if (DB::connection('tenant')->getDatabaseName() !== $db) {
            throw new RuntimeException('SolaStock setup must verify the active tenant database.');
        }
        $audit = app(TenantSchemaAuditService::class)->audit('tenant');
        if (! ($audit['ok'] ?? false)) {
            throw new RuntimeException('SolaStock schema is not ready; complete the approved SPSB setup in Central. Historical migrations are forbidden during workspace setup.');
        }

        return ['database' => $db, 'created' => false, 'migrated' => false, 'connection' => 'tenant', 'schema_ready' => true, 'setup_mode' => 'central_spsb'];
    }
}
