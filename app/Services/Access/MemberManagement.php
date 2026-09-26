<?php

namespace App\Services\Access;

use App\Models\Tenant\InventoryUserWarehouse;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

class MemberManagement
{
    // Stock uses the Central user registry itself. Resolve through that pinned
    // model and its membership table, never a tenant-local numeric ID fallback.
    public function member(int $centralOrg, int $centralUser): User
    {
        $user = User::findOrFail($centralUser);
        abort_unless(DB::connection($user->getConnectionName())->table('user_organizations')
            ->where('organization_id', $centralOrg)->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('status', 'active')->orWhereNull('status'))->exists(), 404);

        return $user;
    }

    public function authorize(User $actor, int $centralOrg, User $target): void
    {
        abort_unless(app(OrganizationContext::class)->idOrFail() === $centralOrg, 403);
        abort_if($actor->id === $target->id, 403);
        abort_if(app(CentralAppAccess::class)->decision((int) $target->id, $centralOrg, 'inventory')['owner'] ?? false, 403);
        $allowed = app(WarehouseAccessService::class)->allowedIds((int) $actor->id);
        if ($allowed !== null) {
            $targetIds = InventoryUserWarehouse::where('user_id', $target->id)->pluck('warehouse_id')->all();
            abort_if(array_diff($targetIds, $allowed), 403);
        }
        $this->member($centralOrg, (int) $actor->id);
        $this->member($centralOrg, (int) $target->id);
        if (request()->filled('central_org')) {
            abort_unless(request()->integer('central_org') === $centralOrg, 403);
        }
        if (request()->filled('central_member')) {
            abort_unless(request()->integer('central_member') === (int) $target->id, 403);
        }
        abort_unless(app(InventoryPermissionService::class)->can($actor, 'inventory.manage_settings'), 403);
        abort_unless(app(CentralAppAccess::class)->decision((int) $target->id, $centralOrg, 'inventory')['allowed'] ?? false, 403);
    }
}
