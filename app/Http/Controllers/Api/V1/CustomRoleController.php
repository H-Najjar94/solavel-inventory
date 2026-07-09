<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryCustomRole;
use App\Models\Tenant\InventoryUserRoleAssignment;
use App\Services\Access\InventoryPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomRoleController extends ApiController
{
    public function index(InventoryPermissionService $permissions): JsonResponse
    {
        return $this->success([
            'permissions' => collect(config('inventory_permissions.permissions', []))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'roles' => InventoryCustomRole::query()->withCount('assignments')->orderBy('name')->get(),
            'assignments' => InventoryUserRoleAssignment::query()
                ->with('role:id,key,name,permissions,is_active')
                ->orderBy('user_id')
                ->get(),
            'builtin_roles' => collect(config('inventory_permissions.roles', []))
                ->map(fn ($perms, $key) => [
                    'key' => $key,
                    'permissions' => $perms === '*' ? $permissions->all() : array_values((array) $perms),
                ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedRole($request);
        $key = Str::slug($data['key'] ?? $data['name'], '_');

        $role = InventoryCustomRole::query()->create([
            'key' => $key,
            'name' => $data['name'],
            'permissions' => array_values(array_unique($data['permissions'])),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($role->fresh(), 201);
    }

    public function update(Request $request, InventoryCustomRole $role): JsonResponse
    {
        $data = $this->validatedRole($request, $role->id);
        $role->fill([
            'key' => Str::slug($data['key'] ?? $role->key, '_'),
            'name' => $data['name'],
            'permissions' => array_values(array_unique($data['permissions'])),
            'is_active' => $data['is_active'] ?? $role->is_active,
        ])->save();

        return $this->success($role->fresh());
    }

    public function assign(Request $request): JsonResponse
    {
        $orgId = app(\App\Tenancy\OrganizationContext::class)->idOrFail();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role_id' => ['required', 'integer', Rule::exists('inventory_custom_roles', 'id')
                ->where('organization_id', $orgId)
                ->where('is_active', true)],
        ]);

        $assignment = InventoryUserRoleAssignment::query()->updateOrCreate(
            ['user_id' => (int) $data['user_id']],
            ['role_id' => (int) $data['role_id'], 'assigned_by' => $request->user()?->id],
        );

        return $this->success($assignment->fresh('role:id,key,name,permissions,is_active'), 201);
    }

    public function unassign(int $userId): JsonResponse
    {
        InventoryUserRoleAssignment::query()->where('user_id', $userId)->delete();

        return $this->success(['deleted' => true]);
    }

    private function validatedRole(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('inventory_custom_roles', 'key')
                    ->where('organization_id', app(\App\Tenancy\OrganizationContext::class)->idOrFail())
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in(array_keys(config('inventory_permissions.permissions', [])))],
            'is_active' => ['boolean'],
        ]);
    }
}
