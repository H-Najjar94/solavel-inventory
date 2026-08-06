<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;

class ConnectionAccountingReviewerService
{
    public function status(int $organizationId, ?object $user): array
    {
        $userId = $this->centralUserId($user);
        if ($organizationId <= 0 || $userId <= 0) {
            return $this->unavailableState();
        }

        try {
            $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
            $membership = $central->table('user_organizations')->where('organization_id', $organizationId)
                ->where('user_id', $userId)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->first();
            $finance = $central->table('projects')->where('slug', 'finance')->where('is_active', true)->first(['id']);
            $orgHasFinance = $finance && $central->table('organization_projects')->where('organization_id', $organizationId)
                ->where('project_id', $finance->id)->where('is_active', true)->exists();
            $userHasFinance = $orgHasFinance && $central->table('user_projects')->where('organization_id', $organizationId)
                ->where('user_id', $userId)->where('project_id', $finance->id)->where('is_active', true)->exists();
            $assigned = $central->table('app_permission_grants')->where('organization_id', $organizationId)
                ->where('app_key', 'finance')->where('user_id', $userId)->where('role_key', 'accountant')
                ->where('permission_key', 'role.assignment')->where('effect', 'allow')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            $selfAssignmentDenied = $central->table('app_permission_grants')->where('organization_id', $organizationId)
                ->where('app_key', 'finance')->where('permission_key', 'role.self_assignment')->where('effect', 'deny')
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            $isOwner = in_array((string) ($membership->role ?? ''), ['client_owner', 'owner'], true);
            $canManage = $isOwner || in_array((string) ($membership->role ?? ''), ['client_manager', 'manager'], true);
            $management = "/portal/orgs/{$organizationId}/application-roles?app=finance&role=accountant&return_to=".rawurlencode('/inventory/integrations/solabooks');

        } catch (\Throwable) {
            return $this->unavailableState();
        }

        return [
            'current_user' => [
                'has_finance_access' => (bool) $userHasFinance,
                'has_accountant_role' => (bool) $assigned,
                'is_owner' => $isOwner,
                'can_manage_roles' => $canManage,
                'can_self_assign' => $isOwner && $userHasFinance && ! $selfAssignmentDenied && ! $assigned,
            ],
            'finance_entitled' => (bool) $orgHasFinance,
            'separation_of_duties' => $selfAssignmentDenied,
            'management_url' => $management,
            'self_assignment_url' => $management.'&self=1',
        ];
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

    private function unavailableState(): array
    {
        return [
            'current_user' => [
                'has_finance_access' => false,
                'has_accountant_role' => false,
                'is_owner' => false,
                'can_manage_roles' => false,
                'can_self_assign' => false,
            ],
            'finance_entitled' => false,
            'separation_of_duties' => false,
            'management_url' => null,
            'self_assignment_url' => null,
            'unavailable' => true,
        ];
    }
}
