<?php

namespace App\Http\Controllers\Api;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\ConnectionManagementPolicy;
use App\Services\Integration\ConnectionSummary;
use App\Services\Integration\ConnectionWizardService;
use App\Services\Integration\DefaultStockConnection;
use App\Services\Integration\FinanceOnboardingReadiness;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority;
use App\Services\InventoryWorkspace\WorkspaceContext;
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
        // Membership is the user_organizations row checked below, not the member's
        // home client. An invited member keeps the client of the account they
        // registered with, so matching it to the organization's client reported
        // every invited SolaCount member as a non-member.
        $actor = User::query()->where('id', $input['actor_id'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->whereNull('deleted_at')
            ->first(['id', 'name', 'client_id', 'status']);
        abort_unless($actor && $central->table('user_organizations')->where('user_id', $actor->id)
            ->where('organization_id', $org->id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->exists(),
            403, 'workspace_membership_required');
        // The organization must hold both products for any integration call. The acting
        // member always needs SolaCount; SolaStock assignment is waived only for the
        // closed follow-through scope of an already reviewed financial document.
        $lifecycle = FinanceDocumentLifecycleAuthority::covers($input['action']);
        foreach (['finance', 'inventory'] as $slug) {
            $project = $central->table('projects')->where('slug', $slug)->where('is_active', true)->value('id');
            abort_unless($project && $central->table('organization_projects')->where('organization_id', $org->id)
                ->where('project_id', $project)->where('is_active', true)->exists(), 403, 'workspace_application_assignment_required');
            if ($lifecycle && $slug === 'inventory') {
                continue;
            }
            // Central owns assignment, owner access and explicit revocations. A
            // historical user_projects row is not the current access decision.
            $access = app(CentralAppAccess::class)
                ->decision((int) $actor->id, (int) $org->id, $slug);
            abort_if(($access['reason'] ?? null) === 'temporarily_unavailable', 503, 'workspace_access_temporarily_unavailable');
            abort_unless(($access['allowed'] ?? false) === true, 403, 'workspace_application_assignment_required');
        }
        // Resolve the database on the server. This never provisions or migrates.
        $database = $tenants->resolveDatabaseName((int) $org->client_id);
        $tenants->useTenant((int) $org->id, $database);
        foreach (['integration_organization_mappings', 'inventory_audit_logs', 'inventory_user_warehouses'] as $table) {
            abort_unless(Schema::connection('tenant')->hasTable($table), 409, 'workspace_schema_not_ready');
        }
        abort_unless(DB::connection('tenant')->table('organizations')->where('id', $input['finance_organization_id'])->where('central_org_id', $org->id)->exists(), 403, 'workspace_finance_mapping_invalid');
        $mapping = IntegrationOrganizationMapping::query()->where('central_client_id', $org->client_id)
            ->where('central_organization_id', $org->id)->where('solastock_organization_id', $org->id)
            ->where('finance_organization_id', $input['finance_organization_id'])
            ->where('tenant_database_identity', $database)->where('contract_version', 'solastock-journal.v2')
            ->whereIn('status', ['verified', 'verified_hold'])->first();

        try {
            app(FinanceOnboardingReadiness::class)->assertComplete((int) $org->id);
            app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping ?? new IntegrationOrganizationMapping(['central_client_id' => $org->client_id, 'central_organization_id' => $org->id]));
        } catch (\RuntimeException $exception) {
            abort(403, 'workspace_integration_not_entitled');
        }
        $setting = IntegrationSetting::query()->where('organization_id', $org->id)->where('integration', 'solabooks')
            ->where('solabooks_organization_id', $input['finance_organization_id'])->first();

        $previous = Auth::user();
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('tenant_state', ['client_id' => (int) $org->client_id, 'organization_id' => (int) $org->id, 'database' => $database, 'state' => 'live_ready']);
        try {
            if (str_starts_with($input['action'], 'purchasing.request.') || str_starts_with($input['action'], 'purchasing.bill.')) {
                $billId = (int) data_get($input, 'data.source_bill_id');
                abort_unless($billId > 0, 422);
                $permission = $input['action'] === 'purchasing.bill.cost-adjustment.prepare' ? 'post' : (in_array($input['action'], ['purchasing.request.status', 'purchasing.bill.receipt', 'purchasing.bill.context'], true) ? 'view' : 'edit_draft');
                $authority = app(SolaBooksOutboxDeliveryService::class)->authorizePurchasing((int) $actor->id, $billId, $permission);
                if ($input['action'] === 'purchasing.request.upsert') {
                    abort_unless(($authority['status'] ?? null) === 'draft' && ! empty($authority['request_revision']) && hash_equals((string) $authority['request_revision'], (string) data_get($input, 'data.source_revision')), 409, __('inventory.purchasing.refresh_required'));
                    $facts = (array) ($authority['receiving_payload'] ?? []);
                    abort_unless($facts !== [], 503);
                    $provided = array_intersect_key((array) ($input['data'] ?? []), $facts);
                    abort_unless(hash_equals(SolaStockJournalContract::payloadHash($facts), SolaStockJournalContract::payloadHash($provided)), 409, __('inventory.purchasing.source_mismatch'));
                }
                $request->attributes->set('purchasing_authority', $authority);
                if (str_starts_with($input['action'], 'purchasing.bill.')) {
                    $d = (array) ($input['data'] ?? []);
                    if (isset($d['destination_document_id'])) {
                        abort_unless((int) $d['destination_document_id'] === $billId && ($d['destination_document_type'] ?? null) === 'supplier_bill', 403);
                    }
                    if (isset($d['destination_fingerprint'])) {
                        abort_unless(hash_equals((string) $authority['source_revision'], (string) $d['destination_fingerprint']), 409, __('inventory.purchasing.refresh_required'));
                    }
                    $allowed = array_map('intval', (array) ($authority['receipt_ids'] ?? []));
                    if ($input['action'] === 'purchasing.bill.receipt') {
                        abort_unless(in_array((int) data_get($input, 'parameters.goods_receipt'), $allowed, true), 403);
                    }
                    foreach ((array) ($d['allocations'] ?? []) as $line) {
                        abort_unless(($line['source_document_type'] ?? null) === 'goods_receipt' && in_array((int) ($line['source_document_id'] ?? 0), $allowed, true), 403);
                    }
                    if ($input['action'] === 'purchasing.bill.context') {
                        $context = app(WorkspaceContext::class)->read($request, (int) $org->id, $mapping !== null, $mapping?->status === 'verified' && $mapping?->activation_state === 'active');
                        abort_unless($context['ready'], 409, 'workspace_connection_not_ready');

                        return response()->json(['success' => true, 'data' => ['ready' => true, 'writable' => $context['writable'] && ($authority['status'] ?? null) === 'draft', 'can_open_stock' => false, 'actions' => ['finance-sources.receipt' => ['allowed' => true], 'finance-allocations.reserve' => ['allowed' => true], 'finance-allocations.cost-adjustment.prepare' => ['allowed' => true]]]]);
                    }
                }

            }
            if ($input['action'] === 'workspace.initialize') {
                abort_unless(app(InventoryPermissionService::class)->can($actor, 'inventory.integration.setup'), 403, 'workspace_permission_required');
                $policy = app(ConnectionManagementPolicy::class)->status((int) $org->id, $actor);
                $summary = fn () => app(ConnectionSummary::class)->forOrganization((int) $org->id);
                if ($policy['separation_of_duties'] ?? false) {
                    return response()->json(['success' => true, 'data' => ['status' => 'manual_review', 'reason' => 'separate_review_required', 'summary' => $summary()]]);
                }
                try {
                    $result = app(DefaultStockConnection::class)->initialize(
                        (int) $org->client_id, (int) $org->id, (int) $input['finance_organization_id'], (int) $actor->id);

                    return response()->json(['success' => true, 'data' => $result + ['summary' => $summary()]]);
                } catch (\RuntimeException $exception) {
                    return response()->json(['success' => false, 'message' => $exception->getMessage(), 'summary' => $summary()], 409);
                }
            }
            if ($input['action'] === 'workspace.context') {
                $context = app(WorkspaceContext::class)->read($request, (int) $org->id, $mapping !== null, $mapping?->status === 'verified' && $mapping?->activation_state === 'active');
                // The same connection answer SolaStock's own page shows, for SolaCount's Connection Status.
                $context['connection_summary'] = app(ConnectionSummary::class)->forOrganization((int) $org->id);

                return response()->json(['success' => true, 'data' => $context]);
            }
            if ($input['action'] === 'workspace.connection') {
                abort_unless(app(InventoryPermissionService::class)->can($actor, 'inventory.integration.view'), 403, 'workspace_permission_required');

                return response()->json(['success' => true, 'data' => app(ConnectionWizardService::class)->discover((int) $org->id)]);
            }
            $readiness = app(WorkspaceContext::class)->read($request, (int) $org->id, $mapping !== null, $mapping?->status === 'verified' && $mapping?->activation_state === 'active');
            abort_unless($readiness['ready'], 409, 'workspace_connection_not_ready');
            abort_unless($mapping, 409, 'workspace_mapping_not_ready');
            abort_unless($setting && in_array($setting->mode, ['active', 'paused', 'connected_readonly'], true), 409, 'workspace_connection_not_ready');

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
