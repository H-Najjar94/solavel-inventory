<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Tenancy\LiveTenantResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tenant status + demo selection. NOT tenant-gated (this is how status is read
 * and how the demo tenant gets selected). The LIVE tenant comes from the central
 * Solavel SSO session (client_id / selected_central_org_id) and always takes
 * precedence over the demo tenant.
 */
class TenantController extends ApiController
{
    public function __construct(
        private TenantResolver $resolver,
        private LiveTenantResolver $live,
    ) {}

    /** Human-readable explanation for an unavailable demo, by reason code. */
    private function reasonMessage(?string $reason): ?string
    {
        return match ($reason) {
            'demo_disabled' => 'Demo tenant is disabled. Set INVENTORY_DEMO_TENANT_ENABLED=true.',
            'db_missing' => 'Demo database does not exist yet. Run scripts/setup-demo-tenant.sh.',
            'migrations_missing' => 'Demo database exists but is not migrated. Run scripts/setup-demo-tenant.sh.',
            'db_unreachable' => 'Cannot reach the database from the app. Check DB credentials / MySQL access.',
            'forbidden_database', 'invalid_org' => 'Demo tenant is misconfigured (forbidden database or invalid org).',
            default => null,
        };
    }

    /** Map a tenant state to the UI data-mode + badge. */
    private function badgeFor(string $state): array
    {
        // data_state drives the frontend fallback gate. ONLY 'sample' permits mock
        // fallback — no_access/no_organization are decisive (the shell renders a
        // state screen instead of the page), so they are NOT 'sample'.
        return match ($state) {
            'live_ready' => ['Live tenant', 'real'],
            'demo_preview' => [config('inventory.demo_tenant.label', 'Demo tenant'), 'demo'],
            'needs_activation', 'tenant_missing', 'tenant_unmigrated', 'tenant_unreachable', 'demo_setup_required' => ['Setup required', 'setup'],
            'no_organization' => ['No organization', 'no_organization'],
            'no_access' => ['No access', 'no_access'],
            'sample_preview' => ['Sample preview', 'sample'],
            default => ['Sample preview', 'sample'],
        };
    }

    private function stateMessage(string $state): ?string
    {
        return match ($state) {
            'no_organization' => 'You are signed in but no Solavel organization is active. Open SolaStock from the Solavel app launcher.',
            'no_access' => 'Your account does not have SolaStock access for this organization. Ask an administrator to grant inventory permissions.',
            'needs_activation' => 'SolaStock is not enabled for this organization yet. Activate it to get started.',
            'tenant_missing' => 'This organization has no SolaStock data yet. An administrator can provision it from the setup screen.',
            'tenant_unmigrated' => 'SolaStock tables are not installed for this organization yet. Run the setup to migrate them.',
            'tenant_unreachable' => 'Cannot reach the database from the app. Check DB access.',
            default => null,
        };
    }

    /** Current tenant status for the UI badge + setup state (live takes precedence). */
    public function status(Request $request): JsonResponse
    {
        $s = $this->live->state($request);
        $readiness = $this->resolver->demoReadiness();
        [$badge, $dataState] = $this->badgeFor($s['state']);

        // Principal (the SSO-seeded user) for the top-bar account menu.
        $principal = $request->hasSession() ? $request->session()->get('principal') : null;

        return $this->success([
            'state' => $s['state'],           // canonical state machine value
            'mode' => $s['mode'],             // live | demo | none
            'data_state' => $dataState,       // real | demo | sample | setup
            'badge' => $badge,
            'organization_id' => $s['organization_id'],
            'organization_name' => $s['mode'] === 'demo'
                ? config('inventory.demo_tenant.label', 'Demo tenant')
                : $this->live->organizationName($s['organization_id']),
            'user' => $principal ? ['name' => $principal['name'] ?? null, 'email' => $principal['email'] ?? null] : null,
            'database' => $s['database'],
            'authenticated' => $s['authenticated'],
            'can_access' => $s['can_access'],
            'can_provision' => $s['can_provision'],
            'state_message' => $this->stateMessage($s['state']),
            'needs_setup' => in_array($s['state'], ['needs_activation', 'tenant_missing', 'tenant_unmigrated', 'tenant_unreachable', 'demo_setup_required'], true),
            // Demo descriptor (secondary preview option).
            'demo_available' => $readiness['available'],
            'demo_reason' => $readiness['reason'],
            'demo_reason_message' => $this->reasonMessage($readiness['reason']),
            'demo_db' => $readiness['database'],
            'demo_label' => config('inventory.demo_tenant.label', 'Demo tenant'),
        ]);
    }

    /** Select the safe demo tenant (operator/dev). Refuses only when misconfigured. */
    public function selectDemo(Request $request): JsonResponse
    {
        $readiness = $this->resolver->demoReadiness();
        if (in_array($readiness['reason'], ['demo_disabled', 'forbidden_database', 'invalid_org'], true)) {
            return $this->error($readiness['reason'], $this->reasonMessage($readiness['reason']) ?? 'Demo tenant unavailable.', 403);
        }
        if (! $request->hasSession()) {
            return $this->error('no_session', 'A session is required to select a tenant.', 400);
        }

        // Allow selection even when the DB/migrations are not ready yet — the UI
        // then shows a precise setup error (never a silent sample fallback).
        $request->session()->put('inventory_demo_tenant', true);

        return $this->success([
            'mode' => 'demo',
            'badge' => config('inventory.demo_tenant.label', 'Demo tenant'),
            'organization_id' => (int) config('inventory.demo_tenant.organization_id'),
            'ready' => $readiness['available'],
            'reason' => $readiness['reason'],
            'reason_message' => $this->reasonMessage($readiness['reason']),
        ]);
    }

    /** Clear the selected demo tenant. */
    public function clear(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            $request->session()->forget('inventory_demo_tenant');
        }

        return $this->success(['mode' => 'none', 'badge' => 'No tenant selected']);
    }

    /**
     * List the signed-in user's accessible organizations (central registry), for
     * the org switcher. Read-only; NOT tenant-gated (switching happens before the
     * tenant is resolved). Each entry flags whether SolaStock is enabled for it.
     */
    public function organizations(Request $request): JsonResponse
    {
        $user = Auth::user();
        $currentOrg = (int) ($request->session()->get('selected_central_org_id') ?? 0);
        if (! $user) {
            return $this->success(['organizations' => [], 'current' => null]);
        }

        try {
            $rows = DB::connection('mysql')->table('user_organizations as uo')
                ->join('organizations as o', 'o.id', '=', 'uo.organization_id')
                ->where('uo.user_id', (int) $user->id)
                ->where(function ($q) {
                    $q->whereNull('uo.status')->orWhere('uo.status', 'active');
                })
                ->select('o.id', 'o.display_name', 'o.legal_name', 'o.slug', 'o.client_id')
                ->orderBy('o.display_name')
                ->get();

            $invProjectId = (int) (DB::connection('mysql')->table('projects')->where('slug', 'inventory')->value('id') ?? 0);
            $enabled = $invProjectId > 0
                ? DB::connection('mysql')->table('organization_projects')
                    ->where('project_id', $invProjectId)->where('is_active', true)
                    ->whereIn('organization_id', $rows->pluck('id'))
                    ->pluck('organization_id')->map(fn ($v) => (int) $v)->all()
                : [];

            $orgs = $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->display_name ?: ($r->legal_name ?: ('Organization #' . $r->id)),
                'client_id' => (int) $r->client_id,
                'inventory_enabled' => in_array((int) $r->id, $enabled, true),
                'current' => (int) $r->id === $currentOrg,
            ])->values();
        } catch (\Throwable $e) {
            return $this->success(['organizations' => [], 'current' => $currentOrg ?: null]);
        }

        return $this->success(['organizations' => $orgs, 'current' => $currentOrg ?: null]);
    }

    /**
     * Switch the active organization (and its owning client tenant) in the
     * session. The user MUST be a member of the target org — validated against
     * the central registry — so a session can never be pointed at an org the
     * user doesn't belong to. The tenant DB is per-client, so we also pin the
     * client_id of the chosen org.
     */
    public function selectOrganization(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $request->hasSession()) {
            return $this->error('no_session', 'A signed-in session is required to switch organizations.', 401);
        }

        $orgId = (int) $request->input('organization_id', 0);
        if ($orgId <= 0) {
            return $this->error('invalid', 'organization_id is required.', 422);
        }

        try {
            $org = DB::connection('mysql')->table('user_organizations as uo')
                ->join('organizations as o', 'o.id', '=', 'uo.organization_id')
                ->where('uo.user_id', (int) $user->id)
                ->where('uo.organization_id', $orgId)
                ->where(function ($q) {
                    $q->whereNull('uo.status')->orWhere('uo.status', 'active');
                })
                ->select('o.id', 'o.client_id')
                ->first();
        } catch (\Throwable $e) {
            return $this->error('lookup_failed', 'Could not verify organization access.', 500);
        }

        if (! $org) {
            return $this->error('forbidden', 'You do not have access to that organization.', 403);
        }

        // Switch the SSO/session context: the selected org wins everywhere, and
        // the client_id follows the org (the tenant DB is keyed by client).
        $request->session()->put('selected_central_org_id', (int) $org->id);
        $request->session()->put('client_id', (int) $org->client_id);
        // Drop any demo selection so the live org takes effect.
        $request->session()->forget('inventory_demo_tenant');

        return $this->success(['organization_id' => (int) $org->id, 'client_id' => (int) $org->client_id]);
    }

    /**
     * First-run provisioning of SolaStock tables for the LIVE organization.
     *
     * Safety: requires a live org + an authorized admin (manage_settings);
     * refuses forbidden databases; migrates ONLY SolaStock's own tenant
     * migration path under marker migrated_at_inv. NEVER imports or writes
     * Finance/Projects tables. If the shared DB doesn't exist or this process
     * lacks privileges, it returns the exact command for a server admin to run.
     */
    public function provision(Request $request, \App\Services\Tenancy\SecureTenantProvisioner $provisioner): JsonResponse
    {
        $s = $this->live->state($request);

        // Provisioning INITIALIZES the tenant tables for an org that is ALREADY
        // enabled. It must NEVER enable the app itself — activation is owned by
        // the central onboarding wizard (pick org → choose plan → enable). So a
        // not-yet-enabled org (needs_activation) is rejected here and sent to
        // onboarding; only enabled-but-unprovisioned states may initialize.
        if ($s['state'] === 'needs_activation') {
            return $this->error('not_enabled', 'SolaStock must be set up through onboarding before it can be initialized.', 409);
        }
        if (! in_array($s['state'], ['tenant_missing', 'tenant_unmigrated', 'tenant_unreachable', 'live_ready'], true) || ! $s['organization_id']) {
            return $this->error('not_provisionable', 'No live organization to initialize.', 409);
        }
        if (! $s['can_provision'] && $s['state'] !== 'live_ready') {
            return $this->error('forbidden', 'You are not allowed to initialize SolaStock for this organization.', 403);
        }

        $db = (string) $s['database'];
        foreach ((array) config('inventory.forbidden_demo_databases', []) as $bad) {
            if ($db === $bad) {
                return $this->error('forbidden_database', "Refusing to provision '{$db}'.", 422);
            }
        }

        try {
            // DB key is the client id, not the org id.
            $result = $provisioner->provisionInventory((int) ($s['client_id'] ?? $s['organization_id']), $db);
        } catch (\Throwable $e) {
            // Most likely: this process cannot CREATE DATABASE / migrate (no privs).
            return $this->success([
                'provisioned' => false,
                'reason' => 'insufficient_privileges',
                'message' => 'Provisioning could not run from the app. A server admin must run the command below.',
                'admin_command' => $this->adminProvisionCommand($db, (int) $s['organization_id']),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success(array_merge(['provisioned' => true], $result));
    }

    private function adminProvisionCommand(string $db, int $orgId): string
    {
        return implode("\n", [
            "sudo mysql -e \"CREATE DATABASE IF NOT EXISTS \\`{$db}\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"",
            "cd ".base_path(),
            "TENANT_DB_DATABASE={$db} php artisan migrate --database=tenant --path=database/migrations/tenant --force",
        ]);
    }
}
