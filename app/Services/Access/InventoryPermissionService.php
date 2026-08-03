<?php

namespace App\Services\Access;

use App\Models\Tenant\InventoryCustomRole;
use App\Models\Tenant\InventoryUserRoleAssignment;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves whether the current user has an inventory permission for the active
 * organization.
 *
 * The user's role is resolved from their CENTRAL organization membership
 * (user_organizations.role for user + active org). There is NO admin fallback:
 * a user with no membership / no resolvable role gets NO permissions (fail
 * closed, least privilege). Only an org owner maps to full inventory admin.
 */
class InventoryPermissionService
{
    /** Per-request memo: [orgId][userId] => inventory role|null. */
    private array $roleCache = [];

    public function __construct(private OrganizationContext $context) {}

    /** All known permissions (from config). */
    public function all(): array
    {
        return array_keys((array) config('inventory_permissions.permissions', []));
    }

    /**
     * Whether the given user can perform $permission in the active org.
     * Fails closed: no tenant context OR no resolved role → false.
     */
    public function can(?object $user, string $permission): bool
    {
        if (! $this->context->has()) {
            return false; // no tenant context → deny (fail closed)
        }

        $role = $this->resolveRole($user);
        if ($role === null) {
            return false; // no membership / no role → deny (least privilege)
        }

        $granted = $this->permissionsForRole($role);

        // Accounting approval is deliberately segregated from organization
        // ownership. The owner approves operational/master-data choices; an
        // accountant (or a custom role explicitly granted this permission)
        // reviews the financial roles and correction preview.
        if ($permission === 'inventory.integration.accounting_review' && $role === 'inventory_admin') {
            return false;
        }

        return $granted === ['*'] || in_array($permission, $granted, true);
    }

    /** @return string[] permissions the user currently holds */
    public function permissionsFor(?object $user): array
    {
        $role = $this->resolveRole($user);
        if ($role === null) {
            return [];
        }
        $granted = $this->permissionsForRole($role);

        if ($granted === ['*']) {
            $permissions = $this->all();
            if ($role === 'inventory_admin') {
                $permissions = array_values(array_diff($permissions, ['inventory.integration.accounting_review']));
            }

            return $permissions;
        }

        return $granted;
    }

    /**
     * The user's inventory role for the active org — resolved from CENTRAL
     * org membership. Returns null (= deny) when the user has no active
     * membership for the org. NEVER defaults to admin.
     */
    private function resolveRole(?object $user): ?string
    {
        $userId = (int) ($user->id ?? 0);
        $orgId = $this->context->has() ? (int) $this->context->id() : 0;
        if ($userId <= 0 || $orgId <= 0) {
            return null;
        }

        if (array_key_exists($userId, $this->roleCache[$orgId] ?? [])) {
            return $this->roleCache[$orgId][$userId];
        }

        $customRole = $this->customRole($userId);
        if ($customRole !== null) {
            return $this->roleCache[$orgId][$userId] = $customRole;
        }

        // The operator demo sandbox (never enabled in production) is fully
        // navigable so the demo data is usable — it has no central membership.
        if ($this->isDemoOrg($orgId) && (bool) config('inventory.demo_tenant.enabled', false)) {
            return $this->roleCache[$orgId][$userId] = 'inventory_admin';
        }

        // Tenant-local user IDs are not Central identities. Organization
        // membership must be resolved with the immutable Central user ID or
        // fail closed; otherwise an unrelated local numeric ID can be denied
        // (or, worse, inherit another Central user's role).
        $centralUserId = $this->centralUserId($user);
        if ($centralUserId <= 0) {
            return $this->roleCache[$orgId][$userId] = null;
        }

        return $this->roleCache[$orgId][$userId] = $this->mapCentralRole($this->fetchCentralRole($centralUserId, $orgId));
    }

    /** Resolve the immutable Central identity without treating arbitrary local IDs as Central IDs. */
    private function centralUserId(?object $user): int
    {
        $explicit = (int) ($user->central_user_id ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        // SolaStock's production Authenticatable is itself stored in the Central
        // registry. Its primary key therefore already is the immutable Central
        // identity. Only allow this fallback when the model proves that exact
        // connection; tenant-local models without central_user_id still fail closed.
        try {
            if ($user && method_exists($user, 'getConnectionName')
                && $user->getConnectionName() === (string) config('tenancy.central_connection', 'mysql')) {
                return (int) ($user->id ?? 0);
            }
        } catch (\Throwable) {
            // An unresolvable identity is never permission-bearing.
        }

        return 0;
    }

    /**
     * Fetch the user's CENTRAL membership role for the org (user_organizations).
     * Returns null when there's no active membership OR the central registry is
     * unreadable — both fail closed to "no access". Extracted so it can be
     * overridden in tests without a live central DB.
     */
    protected function fetchCentralRole(int $userId, int $orgId): ?string
    {
        $central = (string) config('tenancy.central_connection', 'mysql');
        try {
            $role = DB::connection($central)
                ->table('user_organizations')
                ->where('user_id', $userId)
                ->where('organization_id', $orgId)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'active');
                })
                ->value('role');
        } catch (\Throwable $e) {
            return null; // central unreadable → fail closed
        }

        return $role ? (string) $role : null;
    }

    /**
     * Map a central membership role → an inventory role.
     *   client_owner  → inventory_admin   (full incl. settings/provisioning)
     *   client_manager/client_member → inventory_manager (operate inventory, no admin/provision)
     *   any other role → inventory_viewer (least privilege: read-only)
     *   no membership  → null              (deny — no access)
     */
    private function mapCentralRole(?string $centralRole): ?string
    {
        return match ($centralRole) {
            'client_owner' => 'inventory_admin',
            'accountant', 'finance_accountant' => 'inventory_accountant',
            'client_manager', 'client_member' => 'inventory_manager',
            null, '' => null,
            default => 'inventory_viewer',
        };
    }

    private function isDemoOrg(int $orgId): bool
    {
        return $orgId > 0 && $orgId === (int) config('inventory.demo_tenant.organization_id', 0);
    }

    private function permissionsForRole(string $role): array
    {
        $roles = (array) config('inventory_permissions.roles', []);
        $set = $roles[$role] ?? [];

        if ($set === []) {
            try {
                $custom = InventoryCustomRole::query()
                    ->where('key', $role)
                    ->where('is_active', true)
                    ->first();
                $set = $custom?->permissions ?? [];
            } catch (\Throwable) {
                $set = [];
            }
        }

        return $set === '*' ? ['*'] : (array) $set;
    }

    private function customRole(int $userId): ?string
    {
        try {
            if (! Schema::connection(config('tenancy.tenant_connection', 'tenant'))->hasTable('inventory_user_role_assignments')) {
                return null;
            }

            $assignment = InventoryUserRoleAssignment::query()
                ->with('role:id,key,is_active')
                ->where('user_id', $userId)
                ->first();

            return $assignment?->role?->is_active ? $assignment->role->key : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
