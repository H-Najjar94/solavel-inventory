<?php
namespace App\Services\InventoryWorkspace;

use App\Services\Access\InventoryPermissionService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Integration\IntegrationSafetyHold;
use App\Models\Tenant\IntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

final class WorkspaceContext
{
    public function read(Request $request, int $organizationId, bool $mapped, bool $activated): array
    {
        $permissions = app(InventoryPermissionService::class);
        $commercial = app(InventoryCommercialEntitlementService::class);
        $setting = IntegrationSetting::query()->where('organization_id', $organizationId)->where('integration', 'solabooks')->first();
        $ready = $mapped && in_array($setting?->mode, ['active', 'paused', 'connected_readonly'], true);
        $writable = $ready && $activated && $setting?->mode === 'active' && app(IntegrationSafetyHold::class)->deliveryEnabledFor($organizationId);
        $actions = [];
        foreach (WorkspaceActions::ALLOWED as $action) {
            $route = app(Router::class)->getRoutes()->getByName('api.v1.'.$action);
            if (! $route) continue;
            $gates = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'perm:')));
            $reason = $gates === [] ? 'workspace_permission_contract_missing' : null;
            foreach ($gates as $gate) {
                $permission = substr($gate, 5);
                if (! $permissions->can($request->user(), $permission)) { $reason = 'workspace_permission_required'; break; }
                $decision = $commercial->checkPermission($permission);
                if (! $decision['allowed']) { $reason = $decision['reason_code']; break; }
            }
            $feature = ((array) config('inventory_entitlements.route_features', []))[$route->getName()] ?? null;
            if (! $reason && $feature) { $decision = $commercial->checkFeature($feature); if (! $decision['allowed']) $reason = $decision['reason_code']; }
            $write = ! in_array('GET', $route->methods(), true);
            if (! $reason && ! $mapped) $reason = 'workspace_mapping_not_ready';
            if (! $reason && ! $ready) $reason = 'workspace_connection_not_ready';
            if (! $reason && $write && ! $writable) $reason = 'workspace_connection_read_only';
            $actions[$action] = ['allowed' => $reason === null, 'reason' => $reason];
        }
        return ['organization_id' => $organizationId, 'mapped' => $mapped, 'mode' => $setting?->mode ?? 'disconnected',
            'ready' => $ready, 'can_open_stock' => $permissions->can($request->user(), 'inventory.view_dashboard') || $permissions->can($request->user(), 'inventory.view_items') || $permissions->can($request->user(), 'inventory.view_stock'),
            'writable' => $writable, 'actions' => $actions,
            'can_setup' => $permissions->can($request->user(), 'inventory.integration.setup'),
            'can_review_accounting' => $permissions->can($request->user(), 'inventory.integration.accounting_review'),
            'unsupported' => ['partial_linked_returns', 'automatic_catalog_creation', 'automatic_reorder_purchase_orders']];
    }
}
