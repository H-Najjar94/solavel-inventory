<?php

namespace App\Services\Tenancy;

use App\Services\Access\InventoryPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the full SolaStock tenant STATE for a request, the Solavel way.
 *
 * Mirrors how Finance/Projects/HR resolve the active client + tenant database:
 * the central app authenticates the user and seeds the session (client_id /
 * selected_central_org_id) via the SSO handoff. SolaStock then shares the SAME
 * per-client database `tenant_{clientId}` and owns ONLY its own stock/inventory
 * tables there (migration marker migrated_at_inv) — it never touches Finance's.
 *
 * States (one of):
 *   no_organization      — authenticated but no client/org in context
 *   no_access            — org present but user lacks any inventory permission
 *   tenant_missing       — shared tenant DB does not exist
 *   tenant_unmigrated    — DB exists but SolaStock tables not migrated yet
 *   needs_inventory_tenant — alias surfaced to the UI when setup is required
 *   live_ready           — real org + migrated SolaStock tables → real data
 *   demo_preview         — operator-selected safe demo tenant
 *   (none)               — no live org and no demo → read-only sample preview
 */
class LiveTenantResolver
{
    public function __construct(
        private TenantResolver $demoResolver,
        private TenantManager $tenants,
        private InventoryPermissionService $permissions,
    ) {}

    /** Client id (central organization/client) from the SSO-seeded session. */
    /**
     * The X-Organization-Id header is a LOCAL/TEST-only dev hook. In production
     * it is IGNORED — an unauthenticated request must never be able to select a
     * tenant/org via a request header (that was a cross-tenant IDOR vector).
     */
    private function devHeaderOrgId(Request $request): int
    {
        if (! app()->environment('local', 'testing')) {
            return 0;
        }

        return (int) ($request->header('X-Organization-Id') ?? 0);
    }

    public function clientId(Request $request): int
    {
        if (! $request->hasSession()) {
            return $this->devHeaderOrgId($request);
        }
        $s = $request->session();

        // The tenant DB is keyed by CLIENT id (tenant_{clientId}), NOT org id.
        // The handoff sets client_id directly — prefer it. Fall back to the
        // authenticated user's client_id, then to mapping a selected central org
        // → its owning client (cross-client orgs resolve to the owner's tenant,
        // exactly like Finance). Header is a last-resort dev hook.
        $clientId = (int) ($s->get('client_id') ?? Auth::user()?->client_id ?? 0);
        if ($clientId > 0) {
            return $clientId;
        }

        $orgId = (int) (
            $s->get('selected_central_org_id')
            ?? $s->get('organization_id')
            ?? ($s->get('workspace_handoff')['organization_id'] ?? null)
            ?? ($this->devHeaderOrgId($request) ?: null)
            ?? 0
        );
        if ($orgId > 0) {
            $owner = $this->ownerClientForOrg($orgId);

            return $owner > 0 ? $owner : $orgId;
        }

        return 0;
    }

    /**
     * The ROW-SCOPE organization id — what OrganizationScope filters on. This is
     * the selected central org (the actual organization_id stamped on SolaStock
     * rows), which may DIFFER from the client id that keys the tenant database
     * (e.g. client 8 owns organization 10 → DB tenant_000008, rows org_id 10).
     * Falls back to the client id when no distinct org is in context.
     */
    /**
     * Resolve the ACTIVE organization the way the user explicitly selected it —
     * from the SSO/session context only. Hard rules (per the per-org-isolation
     * contract):
     *   - the selected org wins (selected_central_org_id / workspace_handoff);
     *   - the resolved org MUST belong to the current client (tenant DB), so an
     *     org from another client can never become the active scope;
     *   - NEVER fall back to $user->organization_id (the user's global default —
     *     it can belong to a different client and silently override the choice);
     *   - NEVER use the clientId as an org id (different id space — that collision
     *     was the "wrong org name / wrong data" bug);
     *   - if no valid org is in context, fall back ONLY to the user's own org
     *     under THIS client; otherwise return 0 → no_organization (fail closed).
     */
    public function organizationId(Request $request): int
    {
        $clientId = $this->clientId($request);

        $org = 0;
        if ($request->hasSession()) {
            $s = $request->session();
            $org = (int) (
                $s->get('selected_central_org_id')
                ?? $s->get('organization_id')
                ?? ($s->get('workspace_handoff')['organization_id'] ?? null)
                ?? 0
            );
        }
        if ($org <= 0) {
            $org = $this->devHeaderOrgId($request);
        }

        // The selected org is only honoured if it genuinely belongs to this
        // client's tenant — otherwise we'd scope queries to an org whose rows
        // live in a different tenant DB (cross-client leak / empty results).
        if ($org > 0 && $this->orgBelongsToClient($org, $clientId)) {
            return $org;
        }

        // No valid selection → resolve the signed-in user's OWN org under this
        // client (a real org), never the global default org and never the client id.
        return $this->userOrganizationForClient(Auth::user(), $clientId);
    }

    /** True if the central org belongs to the given client (same tenant DB). */
    private function orgBelongsToClient(int $orgId, int $clientId): bool
    {
        if ($orgId <= 0 || $clientId <= 0) {
            return false;
        }
        try {
            return (int) (DB::connection('mysql')->table('organizations')
                ->where('id', $orgId)->value('client_id') ?? 0) === $clientId;
        } catch (\Throwable $e) {
            // If central is briefly unreadable, trust the selection rather than
            // silently dropping the user's chosen org.
            return true;
        }
    }

    /**
     * The signed-in user's organization under THIS client (their membership),
     * preferring their central home org if it belongs to the client. Returns 0
     * when the user has no org for this client (→ no_organization, fail closed).
     */
    private function userOrganizationForClient(?object $user, int $clientId): int
    {
        if (! $user || $clientId <= 0) {
            return 0;
        }
        try {
            $userId = (int) ($user->id ?? 0);
            // Prefer the user's home/default org IF it is under this client.
            $home = (int) ($user->organization_id ?? 0);
            if ($home > 0 && $this->orgBelongsToClient($home, $clientId)) {
                return $home;
            }
            // Otherwise the user's first membership org under this client.
            return (int) (DB::connection('mysql')->table('user_organizations as uo')
                ->join('organizations as o', 'o.id', '=', 'uo.organization_id')
                ->where('uo.user_id', $userId)
                ->where('o.client_id', $clientId)
                ->orderBy('o.id')
                ->value('uo.organization_id') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Map a central organization id → its owning client id (or 0 if unknown). */
    private function ownerClientForOrg(int $orgId): int
    {
        try {
            return (int) (DB::connection('mysql')->table('organizations')
                ->where('id', $orgId)->value('client_id') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Is the SolaStock (inventory) app ENABLED for this central organization?
     * Reads central organization_projects → projects(slug=inventory), is_active.
     * Fail-safe: if the central tables can't be read, returns true so a transient
     * central-DB hiccup never locks out an already-provisioned tenant.
     */
    public function inventoryEnabledForOrg(?int $orgId): bool
    {
        if (! $orgId) {
            return false;
        }
        try {
            return DB::connection('mysql')->table('organization_projects as op')
                ->join('projects as p', 'p.id', '=', 'op.project_id')
                ->where('op.organization_id', $orgId)
                ->where('op.is_active', 1)
                ->where('p.slug', 'inventory')
                ->exists();
        } catch (\Throwable $e) {
            return true; // don't hard-fail on a central-DB read error
        }
    }

    /**
     * Enable the SolaStock (inventory) app for a central organization — writes
     * the organization_projects row (and user_projects for the current user),
     * the same enable-an-app pattern central onboarding uses. Best-effort; never
     * touches Finance/Projects rows.
     */
    public function enableInventoryForOrg(int $orgId): void
    {
        if ($orgId <= 0) {
            return;
        }
        try {
            $projectId = (int) (DB::connection('mysql')->table('projects')->where('slug', 'inventory')->value('id') ?? 0);
            if ($projectId <= 0) {
                return;
            }
            $now = now();
            DB::connection('mysql')->table('organization_projects')->updateOrInsert(
                ['organization_id' => $orgId, 'project_id' => $projectId],
                ['is_active' => 1, 'updated_at' => $now],
            );
            // Stamp created_at if the row was just inserted (updateOrInsert leaves it null).
            DB::connection('mysql')->table('organization_projects')
                ->where('organization_id', $orgId)->where('project_id', $projectId)
                ->whereNull('created_at')->update(['created_at' => $now]);

            $userId = (int) (Auth::id() ?? 0);
            if ($userId > 0) {
                DB::connection('mysql')->table('user_projects')->updateOrInsert(
                    ['user_id' => $userId, 'organization_id' => $orgId, 'project_id' => $projectId],
                    ['is_active' => 1, 'updated_at' => $now],
                );
            }
        } catch (\Throwable $e) {
            // best-effort; provisioning still proceeds
        }
    }

    /** Central organization display name (for the top-bar), or null. */
    public function organizationName(?int $orgId): ?string
    {
        if (! $orgId) {
            return null;
        }
        try {
            $row = DB::connection('mysql')->table('organizations')->where('id', $orgId)->first(['display_name', 'legal_name', 'slug']);

            return $row ? ($row->display_name ?: $row->legal_name ?: $row->slug) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The shared per-client tenant DB name (tenant_{clientId padded}). */
    public function tenantDatabase(int $clientId): string
    {
        return $this->tenants->resolveDatabaseName($clientId);
    }

    /**
     * Compute the full tenant state. Never throws; DB probes are wrapped so a
     * locked-down shell degrades to a clear reason, never a 500 or silent
     * sample fallback for a real logged-in org.
     *
     * @return array{
     *   state:string, mode:string, badge:string, organization_id:?int,
     *   database:?string, authenticated:bool, can_access:bool, can_provision:bool,
     *   reason:?string
     * }
     */
    public function state(Request $request): array
    {
        $authenticated = Auth::check();
        $clientId = $this->clientId($request);
        $orgId = $this->organizationId($request);

        // ── Live path: a real central org is in context ──
        if ($clientId > 0) {
            $db = $this->tenantDatabase($clientId);

            // A client/tenant resolved but NO active organization → the user must
            // pick an org in central. We must not set a zero org context (it would
            // throw) and must not guess (the clientId is NOT an org id).
            if ($orgId <= 0) {
                return $this->result('no_organization', 'live', 'No organization', null, $db, $authenticated, false, 'no_active_org', $clientId);
            }

            // The permission service is fail-closed unless an org context is set.
            // Set it to the ROW-SCOPE org (not the client id) before checking
            // access — this is what OrganizationScope filters on.
            app(\App\Tenancy\OrganizationContext::class)->set($orgId);

            // App access: the user must hold at least one inventory permission.
            $canAccess = $this->permissions->can(Auth::user(), 'inventory.view_dashboard')
                || $this->permissions->can(Auth::user(), 'inventory.view_items')
                || $this->permissions->can(Auth::user(), 'inventory.view_stock');
            if ($authenticated && ! $canAccess) {
                return $this->result('no_access', 'live', 'No SolaStock access', $orgId, $db, $authenticated, false, null, $clientId);
            }

            // App-enabled gate (the Solavel way): SolaStock is only "live" for an
            // org that has the `inventory` project ENABLED in central
            // organization_projects — same as how Finance/Projects gate access.
            // If it isn't enabled, the org must go through onboarding/activation
            // first, regardless of session/permissions.
            if (! $this->inventoryEnabledForOrg($orgId)) {
                return $this->result('needs_activation', 'live', 'Setup required', $orgId, $db, $authenticated, $canAccess, 'app_not_enabled', $clientId);
            }

            $probe = $this->probeTenant($db);
            if ($probe === 'unreachable') {
                return $this->result('tenant_unreachable', 'live', 'Setup required', $orgId, $db, $authenticated, $canAccess, 'db_unreachable', $clientId);
            }
            if ($probe === 'missing') {
                return $this->result('tenant_missing', 'live', 'Setup required', $orgId, $db, $authenticated, $canAccess, 'tenant_missing', $clientId);
            }
            if ($probe === 'unmigrated') {
                return $this->result('tenant_unmigrated', 'live', 'Setup required', $orgId, $db, $authenticated, $canAccess, 'migrations_missing', $clientId);
            }

            // Ready: real org + migrated SolaStock tables.
            return $this->result('live_ready', 'live', 'Live tenant', $orgId, $db, $authenticated, $canAccess, null, $clientId);
        }

        // ── No live org ──
        if ($authenticated) {
            // Authenticated but the central context carries no org/client.
            // Still allow demo preview to take precedence if explicitly selected.
            $demo = $this->demoSelected($request);
            if ($demo) {
                return $demo;
            }

            return $this->result('no_organization', 'none', 'No organization', null, null, true, false);
        }

        // ── Not authenticated: demo (if selected) or sample preview ──
        $demo = $this->demoSelected($request);
        if ($demo) {
            return $demo;
        }

        return $this->result('sample_preview', 'none', 'Sample preview', null, null, false, false);
    }

    /** Demo descriptor IF the operator selected it AND it is valid; else null. */
    private function demoSelected(Request $request): ?array
    {
        if (! ($request->hasSession() && $request->session()->get('inventory_demo_tenant'))) {
            return null;
        }
        $readiness = $this->demoResolver->demoReadiness();
        $demo = $this->demoResolver->demoTenant();
        if (! $demo) {
            return null;
        }

        return $this->result(
            $readiness['available'] ? 'demo_preview' : 'demo_setup_required',
            'demo',
            $demo['label'] ?? 'Demo tenant',
            $demo['organization_id'],
            $demo['database'],
            Auth::check(),
            true,
            $readiness['available'] ? null : $readiness['reason'],
        );
    }

    /** @return 'ready'|'missing'|'unmigrated'|'unreachable' */
    private function probeTenant(string $db): string
    {
        try {
            $exists = collect(DB::connection('mysql')->select(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
            ))->isNotEmpty();
        } catch (\Throwable $e) {
            return 'unreachable';
        }
        if (! $exists) {
            return 'missing';
        }
        try {
            $tables = DB::connection('mysql')->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, 'stock_ledger']
            );

            return count($tables) > 0 ? 'ready' : 'unmigrated';
        } catch (\Throwable $e) {
            return 'unmigrated';
        }
    }

    private function result(string $state, string $mode, string $badge, ?int $orgId, ?string $db, bool $auth, bool $canAccess, ?string $reason = null, ?int $clientId = null): array
    {
        return [
            'state' => $state,
            'mode' => $mode,
            'badge' => $badge,
            'organization_id' => $orgId,          // ROW-SCOPE org (OrganizationScope filter)
            'client_id' => $clientId ?? $orgId,   // tenant-DB key (tenant_{clientId})
            'database' => $db,
            'authenticated' => $auth,
            'can_access' => $canAccess,
            // An authorized user may activate/provision when the app isn't enabled
            // yet or the tenant tables aren't installed.
            'can_provision' => $canAccess && in_array($state, ['needs_activation', 'tenant_missing', 'tenant_unmigrated', 'tenant_unreachable'], true)
                && $this->permissions->can(Auth::user(), 'inventory.manage_settings'),
            'reason' => $reason,
        ];
    }
}
