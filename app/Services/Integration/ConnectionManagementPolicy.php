<?php

namespace App\Services\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical, organization-scoped authority for managing a SolaBooks connection.
 *
 * Product access and connection-management authority are deliberately separate:
 * an active owner who can use both products may manage the connection directly;
 * every other user needs an explicit organization-scoped grant. No job-title or
 * self-assigned Finance role is created or inferred here.
 */
class ConnectionManagementPolicy
{
    public const SETUP_PERMISSION = 'inventory.integration.setup';

    public const MANAGEMENT_PERMISSION = 'inventory.integration.connection_manage';

    public const ACCOUNTING_REVIEW_PERMISSION = 'inventory.integration.accounting_review';

    public const SEGREGATION_POLICY = 'inventory.integration.segregation_of_duties';

    public function status(int $organizationId, ?object $user): array
    {
        $userId = $this->centralUserId($user);
        if ($organizationId <= 0 || $userId <= 0) {
            return $this->denied('identity_unavailable');
        }

        try {
            $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
            $centralName = (string) config('tenancy.central_connection', 'mysql');
            $schema = Schema::connection($centralName);
            $organizationQuery = $central->table('organizations')->where('id', $organizationId)
                ->where('is_active', true);
            if ($schema->hasColumn('organizations', 'deleted_at')) {
                $organizationQuery->whereNull('deleted_at');
            }
            $organization = $organizationQuery->first(['id', 'client_id']);
            if (! $organization) {
                return $this->denied('organization_unavailable');
            }

            $clientQuery = $central->table('clients')->where('id', $organization->client_id)->where('is_active', true);
            if ($schema->hasColumn('clients', 'deleted_at')) {
                $clientQuery->whereNull('deleted_at');
            }
            $clientActive = $clientQuery->exists();
            $userQuery = $central->table('users')->where('id', $userId)
                ->where('client_id', $organization->client_id)
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'));
            if ($schema->hasColumn('users', 'deleted_at')) {
                $userQuery->whereNull('deleted_at');
            }
            $centralUser = $userQuery->first(['id']);
            $membership = $central->table('user_organizations')
                ->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))
                ->first(['role']);

            if (! $clientActive || ! $centralUser || ! $membership) {
                return $this->denied('organization_membership_required');
            }

            $access = $this->productAccess($central, $organizationId, $userId);
            $role = (string) ($membership->role ?? '');
            $isOwner = in_array($role, ['client_owner', 'owner'], true);
            $isAdmin = in_array($role, ['client_manager', 'manager', 'admin'], true);
            $managementGranted = $this->grant($central, $organizationId, $userId, self::MANAGEMENT_PERMISSION);
            $managementDenied = $this->grant($central, $organizationId, null, self::MANAGEMENT_PERMISSION, 'deny')
                || $this->grant($central, $organizationId, $userId, self::MANAGEMENT_PERMISSION, 'deny');
            $accountingGranted = $this->grant($central, $organizationId, $userId, self::ACCOUNTING_REVIEW_PERMISSION);
            $segregation = $this->grant($central, $organizationId, null, self::SEGREGATION_POLICY)
                || $this->grant($central, $organizationId, $userId, self::SEGREGATION_POLICY);

            $both = $access['inventory'] && $access['finance'];
            $canManage = $both && ! $managementDenied && ($isOwner || $managementGranted);
            $canReviewAccounting = $canManage && (! $segregation || (! $isOwner && $accountingGranted));

            return [
                'organization_id' => $organizationId,
                'client_id' => (int) $organization->client_id,
                'current_user' => [
                    'central_user_id' => $userId,
                    'is_owner' => $isOwner,
                    'is_admin' => $isAdmin,
                    'has_inventory_access' => $access['inventory'],
                    'has_finance_access' => $access['finance'],
                    'has_both_product_access' => $both,
                    'has_explicit_management_permission' => $managementGranted,
                ],
                'can_manage_connection' => $canManage,
                'can_review_accounting' => $canReviewAccounting,
                'can_activate' => $canManage,
                'separation_of_duties' => $segregation,
                'reason' => $this->reason($access, $canManage, $canReviewAccounting, $segregation),
            ];
        } catch (\Throwable) {
            return $this->denied('policy_unavailable');
        }
    }

    private function productAccess(Connection $central, int $organizationId, int $userId): array
    {
        $result = ['inventory' => false, 'finance' => false];
        $projectsQuery = $central->table('projects')->whereIn('slug', array_keys($result))->where('is_active', true);
        if (Schema::connection((string) config('tenancy.central_connection', 'mysql'))->hasColumn('projects', 'deleted_at')) {
            $projectsQuery->whereNull('deleted_at');
        }
        $projects = $projectsQuery->get(['id', 'slug']);

        foreach ($projects as $project) {
            $organizationAccess = $central->table('organization_projects')
                ->where('organization_id', $organizationId)->where('project_id', $project->id)
                ->where('is_active', true)->exists();
            $userAccess = $organizationAccess && $central->table('user_projects')
                ->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where('project_id', $project->id)->where('is_active', true)->exists();
            $result[(string) $project->slug] = $userAccess;
        }

        return $result;
    }

    private function grant(
        Connection $central,
        int $organizationId,
        ?int $userId,
        string $permission,
        string $effect = 'allow'
    ): bool {
        return $central->table('app_permission_grants')
            ->where('organization_id', $organizationId)->where('app_key', 'inventory')
            ->where('permission_key', $permission)->where('effect', $effect)
            ->when($userId === null, fn ($query) => $query->whereNull('user_id'),
                fn ($query) => $query->where('user_id', $userId))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    private function reason(array $access, bool $canManage, bool $canReview, bool $segregation): string
    {
        if (! $access['finance']) {
            return 'finance_access_required';
        }
        if (! $access['inventory']) {
            return 'inventory_access_required';
        }
        if (! $canManage) {
            return 'connection_management_permission_required';
        }
        if ($segregation && ! $canReview) {
            return 'separate_reviewer_required';
        }

        return 'allowed';
    }

    private function centralUserId(?object $user): int
    {
        $explicit = (int) ($user->central_user_id ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        try {
            if ($user && method_exists($user, 'getConnectionName')
                && $user->getConnectionName() === (string) config('tenancy.central_connection', 'mysql')) {
                return (int) ($user->id ?? 0);
            }
        } catch (\Throwable) {
            // Unresolvable identities are never permission-bearing.
        }

        return 0;
    }

    private function denied(string $reason): array
    {
        return [
            'current_user' => [
                'is_owner' => false,
                'is_admin' => false,
                'has_inventory_access' => false,
                'has_finance_access' => false,
                'has_both_product_access' => false,
                'has_explicit_management_permission' => false,
            ],
            'can_manage_connection' => false,
            'can_review_accounting' => false,
            'can_activate' => false,
            'separation_of_duties' => false,
            'reason' => $reason,
            'unavailable' => true,
        ];
    }
}
