<?php

namespace App\Services\Access;

use App\Models\Tenant\InventoryCustomRole;
use App\Models\Tenant\InventoryUserRoleAssignment;
use App\Services\Integration\ConnectionManagementPolicy;
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

    public function __construct(
        private OrganizationContext $context,
        private ?ConnectionManagementPolicy $connectionManagement = null,
    ) {}

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
        $orgId = (int) $this->context->id();

        $role = $this->resolveRole($user);
        if ($role === null) {
            return false; // no membership / no role → deny (least privilege)
        }

        $granted = $this->permissionsForRole($role);
        $granted = $this->legacyOperationalAliases($granted);
        $ceiling = $this->centralPermissions($user);
        if ($ceiling !== ['*']) {
            $granted = array_values(array_intersect($granted === ['*'] ? $this->all() : $granted, $ceiling));
        }

        if (in_array($permission, [
            ConnectionManagementPolicy::SETUP_PERMISSION,
            ConnectionManagementPolicy::MANAGEMENT_PERMISSION,
            ConnectionManagementPolicy::ACCOUNTING_REVIEW_PERMISSION,
        ], true)) {
            $connection = $this->connectionPolicy()->status($orgId, $user);

            if ($ceiling !== ['*'] && ! in_array($permission, $ceiling, true)) {
                return false;
            }

            return $permission === ConnectionManagementPolicy::ACCOUNTING_REVIEW_PERMISSION
                ? (bool) ($connection['can_review_accounting'] ?? false)
                : (bool) ($connection['can_manage_connection'] ?? false);
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
        $granted = $this->legacyOperationalAliases($granted);
        $ceiling = $this->centralPermissions($user);
        if ($ceiling !== ['*']) {
            $granted = array_values(array_intersect($granted === ['*'] ? $this->all() : $granted, $ceiling));
        }

        $connectionPermissions = [
            ConnectionManagementPolicy::SETUP_PERMISSION,
            ConnectionManagementPolicy::MANAGEMENT_PERMISSION,
            ConnectionManagementPolicy::ACCOUNTING_REVIEW_PERMISSION,
        ];
        $granted = $granted === ['*'] ? $this->all() : $granted;
        $granted = array_values(array_diff($granted, $connectionPermissions));
        $connection = $this->context->has()
            ? $this->connectionPolicy()->status((int) $this->context->id(), $user)
            : [];
        if ($connection['can_manage_connection'] ?? false) {
            $granted[] = ConnectionManagementPolicy::SETUP_PERMISSION;
            $granted[] = ConnectionManagementPolicy::MANAGEMENT_PERMISSION;
        }
        if ($connection['can_review_accounting'] ?? false) {
            $granted[] = ConnectionManagementPolicy::ACCOUNTING_REVIEW_PERMISSION;
        }

        return array_values(array_unique($ceiling === ['*'] ? $granted : array_intersect($granted, $ceiling)));
    }

    private function centralPermissions(?object $user): array
    {
        $orgId = $this->context->has() ? (int) $this->context->id() : 0;
        if ($this->isDemoOrg($orgId) && app()->environment('local', 'testing') && config('inventory.demo_tenant.enabled')) {
            return ['*'];
        }
        $decision = app(CentralAppAccess::class)->decision($this->centralUserId($user), $orgId, 'inventory');
        if (! ($decision['allowed'] ?? false)) {
            return [];
        }
        if ($decision['owner'] ?? false) {
            return array_values(array_filter($this->all(), fn ($permission) => ! CentralPermissionConstraints::denied($decision, $permission)));
        }
        $permissions = [];
        foreach ($decision['roles'] ?? [] as $role) {
            $localRole = array_key_exists($role, config('inventory_operational_roles', [])) ? $role : match ($role) {
                'stock_manager' => 'inventory_manager',
                // Central emits membership roles only for legacy EXPLICIT app assignments.
                // Preserve those records pending provenance review; new invitations require app roles.
                'client_manager', 'client_member' => 'inventory_manager',
                'client_viewer' => 'inventory_viewer',
                'client_accountant' => 'inventory_accountant',
                'warehouse_user' => 'inventory_viewer',
                default => null,
            };
            if ($localRole) {
                $permissions = array_merge($permissions, $this->permissionsForRole($localRole));
            }
        }

        $permissions = $this->legacyOperationalAliases($permissions);
        if (! array_intersect($decision['roles'] ?? [], array_keys(config('inventory_operational_roles', [])))) {
            if (CentralPermissionConstraints::denied($decision, 'inventory.manage_adjustments')) {
                // Legacy adjustment denials also covered purchase-order writes
                // when those routes shared the adjustment gate. Preserve that
                // deliberate restriction after separating the permissions.
                $permissions = array_diff($permissions, [
                    'inventory.receive_goods', 'inventory.transfer_stock',
                    'inventory.manage_purchase_orders', 'inventory.approve_purchase_orders',
                ]);
            }
            if (CentralPermissionConstraints::denied($decision, 'inventory.manage_warehouses')) {
                $permissions = array_diff($permissions, ['inventory.manage_warehouse_structure']);
            }
        }

        return array_values(array_filter(array_unique($permissions), fn ($permission) => ! CentralPermissionConstraints::denied($decision, $permission)));
    }

    private function connectionPolicy(): ConnectionManagementPolicy
    {
        return $this->connectionManagement ??= app(ConnectionManagementPolicy::class);
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

        $decision = app(CentralAppAccess::class)->decision($centralUserId, $orgId, 'inventory');
        if (! ($decision['allowed'] ?? false)) {
            return null;
        }
        if ($decision['owner'] ?? false) {
            return 'inventory_admin';
        }
        // Organization membership rank is not an application permission ceiling.
        foreach ($decision['roles'] ?? [] as $role) {
            if (array_key_exists($role, config('inventory_operational_roles', []))) {
                return $role;
            }
            if ($role === 'stock_manager') {
                return 'inventory_manager';
            }
            if ($role === 'warehouse_user') {
                return 'inventory_viewer';
            }
        }
        if (empty($decision['roles'])) {
            return null;
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
        if (str_starts_with($role, 'custom:')) {
            return InventoryCustomRole::whereKey((int) substr($role, 7))->where('is_active', true)->first()?->permissions ?? [];
        }
        if (array_key_exists($role, config('inventory_operational_roles', []))) {
            try {
                $stored = DB::connection(config('tenancy.tenant_connection', 'tenant'))->table('inventory_operational_role_sets')->where('role_key', $role)->value('permissions');

                return array_values(array_intersect(config("inventory_operational_roles.$role.permissions", []), json_decode($stored ?? '[]', true) ?: []));
            } catch (\Throwable) {
                return [];
            }
        }
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

    private function legacyOperationalAliases(array $permissions): array
    {
        if ($permissions === ['*']) {
            return $permissions;
        }
        // Preserve existing grants while separating NEW operations from adjustment authority.
        if (in_array('inventory.manage_adjustments', $permissions, true)) {
            $permissions = array_merge($permissions, ['inventory.receive_goods', 'inventory.transfer_stock']);
        }
        if (in_array('inventory.manage_warehouses', $permissions, true)) {
            $permissions[] = 'inventory.manage_warehouse_structure';
        }

        return array_values(array_unique($permissions));
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

            return $assignment ? 'custom:'.$assignment->role_id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
