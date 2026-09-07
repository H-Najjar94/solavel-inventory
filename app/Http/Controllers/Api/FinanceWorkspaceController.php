<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\InventoryWorkspace\WorkspaceDispatcher;
use App\Services\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceWorkspaceController
{
    public function __invoke(Request $request, TenantManager $tenants, WorkspaceDispatcher $workspace)
    {
        $input = $request->validate([
            'client_id' => 'required|integer|min:1', 'organization_id' => 'required|integer|min:1',
            'finance_organization_id' => 'required|integer|min:1', 'actor_id' => 'required|integer|min:1',
            'action' => 'required|string|max:100', 'parameters' => 'sometimes|array', 'data' => 'sometimes|array',
            'idempotency_key' => 'sometimes|string|min:16|max:128', 'revision' => 'sometimes|string|size:64',
        ]);
        $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
        $org = $central->table('organizations')->where('id', $input['organization_id'])
            ->where('client_id', $input['client_id'])->where('is_active', true)->whereNull('deleted_at')->first();
        abort_unless($org && $central->table('clients')->where('id', $input['client_id'])
            ->where('is_active', true)->whereNull('deleted_at')->exists(), 403, 'workspace_organization_unavailable');
        $actor = User::query()->where('id', $input['actor_id'])->where('client_id', $input['client_id'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->whereNull('deleted_at')
            ->first(['id', 'name', 'client_id', 'status']);
        abort_unless($actor && $central->table('user_organizations')->where('user_id', $actor->id)
            ->where('organization_id', $org->id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->exists(),
            403, 'workspace_membership_required');
        foreach (['finance', 'inventory'] as $slug) {
            $project = $central->table('projects')->where('slug', $slug)->where('is_active', true)->value('id');
            abort_unless($project && $central->table('organization_projects')->where('organization_id', $org->id)
                ->where('project_id', $project)->where('is_active', true)->exists()
                && $central->table('user_projects')->where('organization_id', $org->id)->where('user_id', $actor->id)
                    ->where('project_id', $project)->where('is_active', true)->exists(), 403, 'workspace_application_assignment_required');
        }
        // Resolve the database on the server. This never provisions or migrates.
        $database = $tenants->resolveDatabaseName((int) $org->client_id);
        $tenants->useTenant((int) $org->id, $database);
        foreach (['integration_organization_mappings', 'inventory_audit_logs', 'inventory_user_warehouses'] as $table) {
            abort_unless(Schema::connection('tenant')->hasTable($table), 409, 'workspace_schema_not_ready');
        }
        $mapping = IntegrationOrganizationMapping::query()->where('central_client_id', $org->client_id)
            ->where('central_organization_id', $org->id)->where('solastock_organization_id', $org->id)
            ->where('finance_organization_id', $input['finance_organization_id'])
            ->where('tenant_database_identity', $database)->where('contract_version', 'solastock-journal.v2')
            ->whereIn('status', ['verified', 'verified_hold'])->first();
        abort_unless($mapping, 409, 'workspace_mapping_not_ready');
        try {
            app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        } catch (\RuntimeException $exception) {
            abort(403, 'workspace_integration_not_entitled');
        }
        $setting = IntegrationSetting::query()->where('organization_id', $org->id)->where('integration', 'solabooks')
            ->where('solabooks_organization_id', $mapping->finance_organization_id)->first();
        abort_unless($setting && in_array($setting->mode, ['active', 'paused', 'connected_readonly'], true), 409, 'workspace_connection_not_ready');
        $previous = Auth::user();
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('tenant_state', ['client_id' => (int) $org->client_id, 'organization_id' => (int) $org->id, 'database' => $database, 'state' => 'live_ready']);
        try {
            return $workspace->dispatch($request, $input, $mapping, $setting);
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
