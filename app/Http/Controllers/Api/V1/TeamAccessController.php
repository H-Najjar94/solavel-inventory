<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryCustomRole;
use App\Models\Tenant\InventoryUserRoleAssignment;
use App\Models\Tenant\InventoryUserWarehouse;
use App\Models\Tenant\Warehouse;
use App\Models\User;
use App\Services\Access\AppAuthority;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\MemberManagement;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read model for the Team access page: the active organization's members who
 * Central admits to SolaStock, with their SolaStock warehouse scope and custom
 * role. Editing reuses the existing warehouse-assignment and custom-role
 * endpoints; `editable` is exactly MemberManagement::authorize for this actor.
 */
class TeamAccessController extends ApiController
{
    private const MAX_MEMBERS = 200;

    public function index(Request $request, OrganizationContext $context, CentralAppAccess $central,
        MemberManagement $management, InventoryPermissionService $permissions): JsonResponse
    {
        $orgId = $context->idOrFail();
        $actor = $request->user();
        abort_unless(Schema::connection(config('tenancy.tenant_connection', 'tenant'))->hasTable('inventory_user_warehouses'), 503, __('inventory.settings.warehouse_migration_pending'));

        $registry = (new User)->getConnectionName();
        $roster = DB::connection($registry)->table('user_organizations as uo')
            ->join('users as u', 'u.id', '=', 'uo.user_id')
            ->where('uo.organization_id', $orgId)
            ->where(fn ($q) => $q->where('uo.status', 'active')->orWhereNull('uo.status'))
            ->when(Schema::connection($registry)->hasColumn('users', 'deleted_at'), fn ($q) => $q->whereNull('u.deleted_at'))
            ->orderBy('u.name')->orderBy('u.id')
            ->limit(self::MAX_MEMBERS + 1)
            ->get(['u.id', 'u.name', 'u.email'])
            ->unique('id')->values();
        $truncated = $roster->count() > self::MAX_MEMBERS;
        $roster = $roster->take(self::MAX_MEMBERS);

        // Warehouse names follow the actor's own warehouse scope (the model's
        // global scope), exactly like the Settings page's warehouse list.
        $visible = Warehouse::query()->select('id', 'code', 'name')->orderBy('name')->get();
        $byId = $visible->keyBy('id');
        $ids = $roster->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignments = InventoryUserWarehouse::query()->whereIn('user_id', $ids)->get(['user_id', 'warehouse_id'])->groupBy('user_id');
        $customRoles = InventoryUserRoleAssignment::query()->with('role:id,name,is_active')->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $members = [];
        foreach ($roster as $row) {
            $id = (int) $row->id;
            $decision = $central->decision($id, $orgId, 'inventory');
            if (! ($decision['allowed'] ?? false)) {
                continue; // not admitted to SolaStock by Central
            }
            $isSelf = $id === (int) $actor->id;
            $editable = false;
            if (! $isSelf) {
                try {
                    $management->authorize($actor, $orgId, $management->member($orgId, $id));
                    $editable = true;
                } catch (ModelNotFoundException|HttpException) {
                    $editable = false;
                }
            }
            $warehouseIds = collect($assignments->get($id, []))->pluck('warehouse_id')->map(fn ($w) => (int) $w)->unique()->values();
            $named = $warehouseIds->filter(fn ($w) => $byId->has($w))->values();
            $assignment = $customRoles->get($id);
            $members[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'email' => (string) ($row->email ?? ''),
                'roles' => array_values(array_map('strval', (array) ($decision['roles'] ?? []))),
                'is_owner' => (bool) ($decision['owner'] ?? false),
                'full_access' => AppAuthority::full($decision),
                'is_self' => $isSelf,
                'editable' => $editable,
                'warehouse_ids' => $named->all(),
                'warehouses' => $named->map(fn ($w) => ['id' => $w, 'code' => $byId[$w]->code, 'name' => $byId[$w]->name])->all(),
                'hidden_warehouse_count' => $warehouseIds->count() - $named->count(),
                'custom_role' => $assignment ? ['id' => (int) $assignment->role_id, 'name' => $assignment->role?->name, 'is_active' => (bool) ($assignment->role?->is_active ?? false)] : null,
            ];
        }

        $held = $permissions->permissionsFor($actor);

        return $this->success([
            'actor_id' => (int) $actor->id,
            'members' => $members,
            'truncated' => $truncated,
            'warehouses' => $visible->map(fn ($w) => ['id' => (int) $w->id, 'code' => $w->code, 'name' => $w->name])->values(),
            'custom_roles' => InventoryCustomRole::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'permissions'])
                ->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name,
                    'assignable' => ! array_diff((array) $role->permissions, $held)])->values(),
        ]);
    }
}
