<?php

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * Resolves the active tenant for a request and gates the safe DEMO tenant.
 *
 * Tenant sources, in order:
 *   1. real SSO session ('organization_id') — production path (SSO seam)
 *   2. X-Organization-Id header / authenticated user's org — production path
 *   3. session('inventory_demo_tenant') — the operator-selected safe demo tenant
 *
 * The demo tenant is ALWAYS validated against config: it must be enabled, must be
 * the configured demo org/db, and must never be a forbidden (Finance/Projects/
 * production) database. Fail closed.
 */
class TenantResolver
{
    public function __construct(private TenantManager $tenants) {}

    /** @return array{org_id:int,database:?string,mode:string}  mode: live|demo|none */
    public function resolve(\Illuminate\Http\Request $request): array
    {
        // 1 & 2 — real tenant (SSO / header / user).
        $liveOrg = (int) (
            ($request->hasSession() ? $request->session()->get('organization_id') : null)
            ?? $request->header('X-Organization-Id')
            ?? $request->user()?->organization_id
            ?? 0
        );
        if ($liveOrg > 0) {
            return ['org_id' => $liveOrg, 'database' => null, 'mode' => 'live'];
        }

        // 3 — selected demo tenant (only if enabled + valid).
        if ($request->hasSession() && $request->session()->get('inventory_demo_tenant')) {
            $demo = $this->demoTenant();
            if ($demo) {
                return ['org_id' => $demo['organization_id'], 'database' => $demo['database'], 'mode' => 'demo'];
            }
        }

        return ['org_id' => 0, 'database' => null, 'mode' => 'none'];
    }

    /** Validated demo-tenant descriptor, or null if disabled/invalid. */
    public function demoTenant(): ?array
    {
        $cfg = (array) config('inventory.demo_tenant', []);
        if (! ($cfg['enabled'] ?? false)) {
            return null;
        }

        $db = (string) ($cfg['database'] ?? '');
        $org = (int) ($cfg['organization_id'] ?? 0);

        $this->assertSafeDemoDatabase($db);
        if ($org <= 0) {
            return null;
        }

        return ['organization_id' => $org, 'database' => $db, 'label' => $cfg['label'] ?? 'Demo tenant'];
    }

    public function demoEnabled(): bool
    {
        return $this->demoTenant() !== null;
    }

    /**
     * Operator-facing readiness probe for the demo tenant. Never throws — returns
     * a structured status so the UI can show a precise setup error instead of a
     * silent sample fallback.
     *
     * @return array{available:bool, reason:?string, db_exists:?bool, migrated:?bool, database:?string, organization_id:?int}
     */
    public function demoReadiness(): array
    {
        $cfg = (array) config('inventory.demo_tenant', []);
        if (! ($cfg['enabled'] ?? false)) {
            return ['available' => false, 'reason' => 'demo_disabled', 'db_exists' => null, 'migrated' => null, 'database' => null, 'organization_id' => null];
        }

        $db = (string) ($cfg['database'] ?? '');
        $org = (int) ($cfg['organization_id'] ?? 0);

        try {
            $this->assertSafeDemoDatabase($db);
        } catch (RuntimeException $e) {
            return ['available' => false, 'reason' => 'forbidden_database', 'db_exists' => null, 'migrated' => null, 'database' => $db, 'organization_id' => $org];
        }
        if ($org <= 0) {
            return ['available' => false, 'reason' => 'invalid_org', 'db_exists' => null, 'migrated' => null, 'database' => $db, 'organization_id' => $org];
        }

        // Probe the database + migration marker. Catch ALL DB/access errors so a
        // locked-down shell never turns into a 500 — it reports "db_unreachable".
        try {
            $exists = collect(\DB::connection('mysql')->select(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
            ))->isNotEmpty();
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'db_unreachable', 'db_exists' => null, 'migrated' => null, 'database' => $db, 'organization_id' => $org];
        }
        if (! $exists) {
            return ['available' => false, 'reason' => 'db_missing', 'db_exists' => false, 'migrated' => false, 'database' => $db, 'organization_id' => $org];
        }

        $migrated = false;
        try {
            $tables = \DB::connection('mysql')->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, 'stock_ledger']
            );
            $migrated = count($tables) > 0;
        } catch (\Throwable $e) {
            $migrated = false;
        }
        if (! $migrated) {
            return ['available' => false, 'reason' => 'migrations_missing', 'db_exists' => true, 'migrated' => false, 'database' => $db, 'organization_id' => $org];
        }

        return ['available' => true, 'reason' => null, 'db_exists' => true, 'migrated' => true, 'database' => $db, 'organization_id' => $org];
    }

    /** Activate the resolved tenant on the TenantManager. */
    public function activate(array $resolved): void
    {
        if ($resolved['mode'] === 'demo') {
            $this->tenants->useTenant($resolved['org_id'], $resolved['database']);
        } elseif ($resolved['mode'] === 'live') {
            $this->tenants->useTenant($resolved['org_id']);
        }
    }

    /** Never allow a forbidden (Finance/Projects/production) DB as demo. */
    private function assertSafeDemoDatabase(string $db): void
    {
        $forbidden = (array) config('inventory.forbidden_demo_databases', []);
        if ($db === '' || in_array($db, $forbidden, true)) {
            throw new RuntimeException("Refusing to use '{$db}' as the demo tenant database.");
        }
    }
}
