<?php

namespace App\Http\Controllers;

use App\Models\Tenant\InventoryUserRoleAssignment;
use App\Models\Tenant\InventoryUserWarehouse;
use App\Services\Access\MemberManagement;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;

class MemberManagementController
{
    public function __invoke(Request $request, int $centralOrg, int $centralMember, MemberManagement $management)
    {
        $target = $management->member($centralOrg, $centralMember);
        $management->authorize($request->user(), $centralOrg, $target);

        return redirect()->route('inventory.settings', ['central_org' => $centralOrg, 'central_member' => $centralMember]);
    }

    public function settings(Request $request, MemberManagement $management)
    {
        $memberManagement = null;
        if ($request->hasAny(['central_org', 'central_member'])) {
            $data = $request->validate(['central_org' => 'required|integer|min:1', 'central_member' => 'required|integer|min:1']);
            $target = $management->member((int) $data['central_org'], (int) $data['central_member']);
            $management->authorize($request->user(), (int) $data['central_org'], $target);
            $memberManagement = ['id' => $target->id, 'name' => $target->name,
                'organization_id' => app(OrganizationContext::class)->idOrFail(),
                'warehouse_ids' => InventoryUserWarehouse::where('user_id', $target->id)->pluck('warehouse_id'),
                'role_id' => InventoryUserRoleAssignment::where('user_id', $target->id)->value('role_id')];
        }

        return view('solastock-app', compact('memberManagement'));
    }
}
