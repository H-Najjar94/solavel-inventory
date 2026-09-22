<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Models\Landlord\Organization;
use App\Services\Access\MemberManagement;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MemberManagementController
{
    public function __invoke(Request $request, TenantManager $tenants, MemberManagement $management)
    {
        $data = $request->validate(['client_id' => 'required|integer|min:1', 'organization_id' => 'required|integer|min:1',
            'actor_id' => 'required|integer|min:1', 'target_ids' => 'present|array|max:100', 'target_ids.*' => 'integer|min:1']);
        $org = Organization::whereKey($data['organization_id'])->where('client_id', $data['client_id'])->where('is_active', true)->firstOrFail();
        $tenants->switchToDatabase($tenants->resolveDatabaseName($data['client_id']));
        app(OrganizationContext::class)->set((int) $org->id);
        $actor = $management->member((int) $org->id, $data['actor_id']);
        $targets = [];
        foreach ($data['target_ids'] as $id) {
            try {
                $management->authorize($actor, (int) $org->id, $management->member((int) $org->id, $id));
                $targets[(string) $id] = ['allowed' => true];
            } catch (ModelNotFoundException|HttpException $e) {
                $targets[(string) $id] = ['allowed' => false];
            }
        }

        return response()->json(['organization_id' => (int) $org->id, 'actor_id' => $data['actor_id'], 'app_key' => 'inventory', 'targets' => $targets])
            ->header('Cache-Control', 'no-store');
    }
}
