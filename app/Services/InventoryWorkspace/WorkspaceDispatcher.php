<?php

namespace App\Services\InventoryWorkspace;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\WarehouseAccessService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Integration\IntegrationSafetyHold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;

/** Reuses native Stock controllers, validation and services after independent signed-actor authorization. */
final class WorkspaceDispatcher
{
    public function dispatch(Request $outer, array $input, IntegrationOrganizationMapping $mapping, IntegrationSetting $setting): JsonResponse
    {
        $action = $input['action'];
        abort_unless(in_array($action, WorkspaceActions::ALLOWED, true), 404, 'workspace_action_unknown');
        $router = app(Router::class);
        $native = $router->getRoutes()->getByName('api.v1.'.(['warehouses.show' => 'workspace.warehouse', 'lots.show' => 'workspace.lot', 'serials.show' => 'workspace.serial'][$action] ?? $action));
        abort_unless($native, 503, 'workspace_action_unavailable');
        $route = clone $native;
        $write = ! in_array('GET', $route->methods(), true);
        $permissions = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));
        abort_if($permissions === [], 503, 'workspace_permission_contract_missing');
        foreach ($permissions as $middleware) {
            $permission = substr($middleware, 5);
            abort_unless(app(InventoryPermissionService::class)->can($outer->user(), $permission), 403, 'workspace_permission_required');
            $decision = app(InventoryCommercialEntitlementService::class)->checkPermission($permission);
            abort_unless($decision['allowed'], 403, $decision['reason_code']);
        }
        // Config keys contain dots; direct array indexing is intentional.
        $feature = ((array) config('inventory_entitlements.route_features', []))[$route->getName()] ?? null;
        if ($feature) {
            $decision = app(InventoryCommercialEntitlementService::class)->checkFeature($feature);
            abort_unless($decision['allowed'], 403, $decision['reason_code']);
        }
        if ($write) {
            abort_unless($mapping->status === 'verified' && $mapping->activation_state === 'active' && $setting->mode === 'active', 409, 'workspace_connection_read_only');
            abort_unless(app(IntegrationSafetyHold::class)->deliveryEnabledFor((int) $mapping->solastock_organization_id), 423, 'integration_safety_hold');
            abort_unless(isset($input['idempotency_key']), 422, 'workspace_idempotency_key_required');
        }
        $parameters = $input['parameters'] ?? [];
        abort_unless(array_diff(array_keys($parameters), $route->parameterNames()) === []
            && array_diff($route->parameterNames(), array_keys($parameters)) === [], 422, 'workspace_parameters_invalid');
        $uri = '/'.$route->uri();
        foreach ($parameters as $name => $id) {
            abort_unless(ctype_digit((string) $id) && (int) $id > 0, 422, 'workspace_parameters_invalid');
            $uri = str_replace('{'.$name.'}', (string) $id, $uri);
        }
        $data = $input['data'] ?? [];
        abort_if(array_intersect(array_keys($data), ['organization_id', 'client_id', 'actor_id', 'user_id', 'created_by', 'updated_by']) !== [], 422, 'workspace_identity_is_server_owned');
        if (isset($data['per_page'])) {
            $data['per_page'] = max(1, min(100, (int) $data['per_page']));
        }
        $request = Request::create($uri, $route->methods()[0], $data);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver($outer->getUserResolver());
        $request->attributes->set('verified_workspace_action', $action);
        $request->attributes->set('tenant_state', $outer->attributes->get('tenant_state'));
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $requestHash = \App\Services\Integration\SolaStockJournalContract::payloadHash($input);
        $key = hash('sha256', (string) ($input['idempotency_key'] ?? ''));
        app()->instance('request', $request);
        try {
            return DB::connection('tenant')->transaction(function () use ($router, $route, $request, $input, $mapping, $setting, $action, $write, $key, $requestHash): JsonResponse {
                // The immutable mapping is an existing durable serialization point.
                // Receipt and all owner-side mutations commit together; no schema addition.
                if ($write) {
                    $lockedMapping = IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
                    abort_unless($lockedMapping->status === 'verified' && $lockedMapping->activation_state === 'active' && $setting->fresh()?->mode === 'active', 409, 'workspace_connection_read_only');
                    $receipt = InventoryAuditLog::query()->where('entity_type', 'finance_workspace_command')
                        ->where('entity_id', $mapping->id)->where('actor_user_id', $request->user()->id)
                        ->where('document_ref', $key)->first();
                    if ($receipt) {
                        abort_unless(hash_equals((string) ($receipt->before['request_hash'] ?? ''), $requestHash), 409, 'workspace_idempotency_conflict');
                        return response()->json($receipt->after['body'], (int) $receipt->after['status'])->header('X-Workspace-Replayed', 'true');
                    }
                }
                $router->substituteBindings($route);
                $router->substituteImplicitBindings($route);
                $models = [];
                if ($action === 'settings.reorder.store') {
                    abort_if(\App\Models\Tenant\WarehouseReorderRule::query()->where('item_id', $request->input('item_id'))->where('warehouse_id', $request->input('warehouse_id'))->exists(), 409, 'workspace_existing_rule_requires_revision');
                }
                foreach ($route->parameters() as $name => $value) {
                    if ($value instanceof Model) {
                        $model = $value->newQuery()->whereKey($value->getKey())->when($write, fn ($q) => $q->lockForUpdate())->firstOrFail();
                        abort_unless((int) $model->organization_id === (int) $mapping->solastock_organization_id, 404);
                        $route->setParameter($name, $model);
                        $models[] = $model;
                    }
                }
                // Native integer parameters for location updates need the same revision scope.
                foreach (['zone' => \App\Models\Tenant\WarehouseZone::class, 'bin' => \App\Models\Tenant\WarehouseBin::class] as $name => $class) {
                    if ($route->hasParameter($name) && ! $route->parameter($name) instanceof Model) {
                        $models[] = $class::query()->whereKey($route->parameter($name))->when($write, fn ($q) => $q->lockForUpdate())->firstOrFail();
                    }
                }
                if ($write && $models !== []) {
                    abort_unless(isset($input['revision']) && hash_equals($this->revision($models), $input['revision']), 409, 'workspace_revision_conflict');
                }
                $this->warehouses($request->all(), $models);
                $response = $route->run();
                abort_unless($response instanceof JsonResponse, 503, 'workspace_response_contract_invalid');
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response); // roll back any partial controller work
                }
                $body = $response->getData(true);
                if ($models !== []) {
                    $body['workspace_revision'] = $this->revision(array_map(fn ($m) => $m->fresh(), $models));
                }
                if ($action === 'warehouses.show') {
                    // Native location updates use integer bindings. Publish their
                    // exact revisions from bounded owner-side queries, not a hash
                    // of serialized presentation fields.
                    foreach (['zone' => \App\Models\Tenant\WarehouseZone::class, 'bin' => \App\Models\Tenant\WarehouseBin::class] as $name => $class) {
                        foreach ($class::query()->where('warehouse_id', $models[0]->id)->get() as $location) {
                            $body['workspace_revisions'][$name.':'.$location->id] = $this->revision([$location]);
                        }
                    }
                }
                if ($write) {
                    InventoryAuditLog::query()->create([
                        'organization_id' => $mapping->solastock_organization_id,
                        'actor_user_id' => $request->user()->id, 'action' => $action,
                        'entity_type' => 'finance_workspace_command', 'entity_id' => $mapping->id,
                        'document_ref' => $key, 'before' => ['request_hash' => $requestHash],
                        'after' => ['body' => $body, 'status' => $response->getStatusCode()], 'created_at' => now(),
                    ]);
                }
                return response()->json($body, $response->getStatusCode());
            }, 3);
        } finally {
            app()->instance('request', $outer);
        }
    }

    private function revision(array $models): string
    {
        $records = [];
        foreach ($models as $model) {
            $record = $model->getAttributes();
            ksort($record);
            if (method_exists($model, 'lines')) {
                $record['lines'] = $model->lines()->orderBy('id')->get()->map(fn ($line) => $line->getAttributes())->all();
            }
            $records[] = $record;
        }
        return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
    }

    private function warehouses(array $data, array $models): void
    {
        $access = app(WarehouseAccessService::class);
        foreach ($models as $model) {
            $this->warehouses($model->getAttributes(), []);
            if ($model instanceof \App\Models\Tenant\Warehouse) {
                $access->assertAllowed((int) $model->id);
            }
        }
        foreach (['warehouse_id', 'from_warehouse_id', 'to_warehouse_id'] as $field) {
            if (isset($data[$field])) {
                \App\Models\Tenant\Warehouse::query()->findOrFail((int) $data[$field]);
                $access->assertAllowed((int) $data[$field]);
            }
        }
    }
}
