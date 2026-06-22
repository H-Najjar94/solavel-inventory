<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\ItemBrand;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use App\Services\Access\InventoryPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPA bootstrap payload: the current user's inventory permissions, settings, and
 * lookup lists used to render permission-aware navigation and form selects.
 */
class MetaController extends ApiController
{
    public function index(Request $request, InventoryPermissionService $permissions): JsonResponse
    {
        return $this->success([
            'permissions' => $permissions->permissionsFor($request->user()),
            'tenant_mode' => $request->attributes->get('tenant_mode', 'live'), // live|demo
            'settings' => InventorySetting::query()->first(),
            'lookups' => [
                'categories' => ItemCategory::query()->where('is_active', true)->get(['id', 'name', 'parent_id']),
                'brands' => ItemBrand::query()->where('is_active', true)->get(['id', 'name']),
                'units' => Unit::query()->where('is_active', true)->get(['id', 'code', 'name', 'symbol']),
            ],
            'primary_color' => '#e09921',
        ]);
    }
}
