<?php

namespace App\Http\Controllers\Api;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\ReceivingRequest;
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
use App\Services\Purchasing\FinanceReceivingService;
use App\Services\Purchasing\PostedPurchaseSettlementService;
use App\Services\Purchasing\ReceivingRequestService;
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
            'finance_organization_id' => 'required|integer|min:1', 'actor_id' => 'required|integer|min:0', 'authority_kind' => 'sometimes|string|max:64',
            'action' => 'required|string|max:100', 'parameters' => 'sometimes|array', 'data' => 'sometimes|array',
            'idempotency_key' => 'sometimes|string|min:16|max:128', 'revision' => 'sometimes|string|size:64',
        ]);
        $central = DB::connection((string) config('tenancy.central_connection', 'mysql'));
        $org = $central->table('organizations')->where('id', $input['organization_id'])
            ->where('client_id', $input['client_id'])->where('is_active', true)->whereNull('deleted_at')->first();
        abort_unless($org && $central->table('clients')->where('id', $input['client_id'])
            ->where('is_active', true)->whereNull('deleted_at')->exists(), 403, 'workspace_organization_unavailable');
        if ((int) $input['actor_id'] === 0) {
            if (in_array($input['action'], FinanceDocumentLifecycleAuthority::FINANCIAL_ORIGIN_SETTLEMENT_ACTIONS, true)) {
                abort_unless(($input['authority_kind'] ?? null) === 'posted_financial_origin_settlement'
                    && $input['action'] !== 'financial-origin.settlement.reverse', 403);
                abort_unless(class_exists(\App\Services\FinancialOrigins\HeldOriginReceiptCostService::class), 409, 'workspace_schema_not_ready');
                $hadState = $request->attributes->has('tenant_state');
                $oldState = $request->attributes->get('tenant_state');
                $request->attributes->set('tenant_state', ['client_id' => (int) $org->client_id, 'organization_id' => (int) $org->id]);
                try {
                    return response()->json(['success' => true, 'data' => app(\App\Services\FinancialOrigins\HeldOriginReceiptCostService::class)->dispatch($input, $org)]);
                } finally {
                    if ($hadState) $request->attributes->set('tenant_state', $oldState);
                    else $request->attributes->remove('tenant_state');
                }
            }
            if ($input['action'] === 'financial-origin.party.ensure') {
                return response()->json(['success' => true, 'data' => app(\App\Services\Integration\ContinuousPartySync::class)->dispatchFinancialOrigin($input, $org)]);
            }
            if (in_array($input['action'], ['purchasing.party.ensure', 'purchasing.party.status', 'purchasing.party.choices', 'purchasing.party.resolve', 'sales.party.ensure', 'sales.party.status', 'sales.party.choices', 'sales.party.resolve'], true)) {
                return response()->json(['success'=>true,'data'=>app(\App\Services\Integration\ContinuousPartySync::class)->dispatch($input,$org)]);
            }
            abort_unless(str_starts_with($input['action'], 'purchasing.settlement.'), 403);
            $result = app(PostedPurchaseSettlementService::class)->dispatch($input, $org);

            return response()->json(['success' => true, 'data' => $result]);
        }
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
            if (in_array($input['action'], ['purchasing.credit-receipt-restore.prepare','purchasing.credit-receipt-restore.apply',
                'purchasing.credit-receipt-restore.status','purchasing.credit-receipt-restore.release'],true)) {
                abort_unless((int)$input['actor_id']>0 && ($input['authority_kind']??null)==='posted_supplier_credit_receipt_restore',403);
                abort_unless($mapping && $mapping->status==='verified' && $mapping->activation_state==='active'
                    && $setting && $setting->mode==='active',409,'workspace_connection_not_ready');
                return response()->json(['success'=>true,'data'=>app(\App\Services\PurchasingCredits\SupplierCreditReceiptRestoreWorkspaceService::class)->dispatch($input,$mapping)]);
            }
            if (in_array($input['action'], ['purchasing.credit-value.prepare','purchasing.credit-value.apply','purchasing.credit-value.status',
                'purchasing.credit-value.release','purchasing.credit-value.reverse'],true)) {
                abort_unless((int)$input['actor_id']>0 && ($input['authority_kind']??null)==='posted_supplier_credit_value',403);
                abort_unless($mapping && $mapping->status==='verified' && $mapping->activation_state==='active'
                    && $setting && $setting->mode==='active',409,'workspace_connection_not_ready');
                return response()->json(['success'=>true,'data'=>app(\App\Services\PurchasingCredits\SupplierCreditValueWorkspaceService::class)->dispatch($input,$mapping)]);
            }
            if ($input['action'] === 'financial-origin.capabilities') {
                abort_unless((int) $input['actor_id'] > 0, 403);
                $probe = (array) ($input['data'] ?? []);
                abort_unless(array_diff(array_keys($probe), ['source_document_type']) === [], 422);
                validator($probe, ['source_document_type' => 'required|in:expense,sales_receipt'])->validate();
                // Canonical Finance membership/app authority is checked above. No Stock assignment or physical permission is granted.
                return response()->json(['success' => true, 'data' => app(\App\Services\FinancialOrigins\FinancialOriginCapabilities::class)
                    ->inspect((int) $input['finance_organization_id'])]);
            }
            if ($input['action'] === 'sales.fulfillment.capabilities') {
                abort_unless($mapping && $mapping->status === 'verified' && $mapping->activation_state === 'active'
                    && $setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
                validator((array) ($input['data'] ?? []), ['source_invoice_id' => 'required|integer|min:1'])->validate();
                // Both app assignments and canonical membership were checked above.
                // This read creates no request, approval, reservation, or shipment.
                return response()->json(['success' => true, 'data' => app(\App\Services\Sales\FinanceDispatchService::class)->capabilities()]);
            }
            if (in_array($input['action'], FinanceDocumentLifecycleAuthority::FINANCIAL_ORIGIN_SETTLEMENT_ACTIONS, true)) {
                abort_unless(($input['authority_kind'] ?? null) === 'posted_financial_origin_settlement', 403);
                abort_unless($mapping && $mapping->status === 'verified' && $mapping->activation_state === 'active'
                    && $setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
                abort_unless(class_exists(\App\Services\FinancialOrigins\HeldOriginReceiptCostService::class), 409, 'workspace_schema_not_ready');
                $result = app(\App\Services\FinancialOrigins\HeldOriginReceiptCostService::class)->dispatch($input, $org);
                return response()->json(['success' => true, 'data' => $result]);
            }
            // Typed native documents remain separate from Bill/Invoice identities.
            // Only financial demand waives Stock assignment; physical actions below do not.
            if (in_array($input['action'], FinanceDocumentLifecycleAuthority::FINANCIAL_ORIGIN_REQUEST_ACTIONS, true)
                || in_array($input['action'], ['financial-origin.dispatch.options', 'financial-origin.dispatch.approve',
                    'financial-origin.dispatch.prepare', 'financial-origin.dispatch.execute',
                    'financial-origin.dispatch.status', 'financial-origin.dispatch.abandon'], true)) {
                abort_unless($mapping && $mapping->status === 'verified' && $mapping->activation_state === 'active'
                    && $setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
                abort_unless(Schema::connection('tenant')->hasTable('stock_financial_origin_requests')
                    && class_exists(\App\Services\FinancialOrigins\OriginRequestService::class), 409, 'workspace_schema_not_ready');
                $data = (array) ($input['data'] ?? []);
                validator($data, ['source_document_type'=>'required|in:sales_receipt,expense',
                    'source_document_id'=>'required|integer|min:1','source_journal_id'=>'required|integer|min:1',
                    'request_uuid'=>'required|uuid'])->validate();
                if (in_array($input['action'], FinanceDocumentLifecycleAuthority::FINANCIAL_ORIGIN_REQUEST_ACTIONS, true)) {
                    $service = app(\App\Services\FinancialOrigins\OriginRequestService::class);
                    $result = match ($input['action']) {
                        'financial-origin.request.upsert'=>$service->upsert($data, (int)$actor->id),
                        'financial-origin.request.cancel'=>$service->cancel($data, (int)$actor->id),
                        'financial-origin.request.status'=>$service->sourceStatus($data, (int)$actor->id),
                    };
                } else {
                    validator($data, ['request_revision'=>'required|string|size:64'])->validate();
                    $action = substr($input['action'], strlen('financial-origin.dispatch.'));
                    if (in_array($action, ['prepare','execute','status','abandon'], true)) {
                        validator($data, ['operation_uuid'=>'required|uuid'])->validate();
                    }
                    if ($action === 'approve') {
                        validator($data, ['warehouse_id'=>'required|integer|min:1'])->validate();
                        $result = app(\App\Services\FinancialOrigins\OriginRequestService::class)->approve($data, (int)$actor->id);
                    } else {
                        $result = app(\App\Services\FinancialOrigins\OriginDispatchService::class)->{$action}($data, (int)$actor->id);
                    }
                }
                return response()->json(['success'=>true,'data'=>$result]);
            }
            // Financial demand creation is a closed Finance capability. Physical
            // dispatch separately requires current Stock access and native permissions.
            if (in_array($input['action'], ['sales.request.upsert','sales.request.cancel','sales.request.status','sales.request.reduce-demand',
                'sales.fulfillment.options','sales.fulfillment.approve','sales.fulfillment.prepare',
                'sales.fulfillment.execute','sales.fulfillment.status','sales.fulfillment.abandon'], true)) {
                abort_unless($mapping && $mapping->status === 'verified' && $mapping->activation_state === 'active'
                    && $setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
                abort_unless(Schema::connection('tenant')->hasTable('sales_fulfillment_requests'), 409, 'workspace_schema_not_ready');
                $data = (array) ($input['data'] ?? []);
                validator($data, ['source_invoice_id'=>'required|integer|min:1','request_uuid'=>'required|uuid'])->validate();
                if ($input['action'] === 'sales.request.reduce-demand') {
                    // The native credit command validates its closed DTO and fresh
                    // credit_notes.post proof; this scope grants no warehouse operation.
                    $result = app(\App\Services\Sales\CreditDemandService::class)->dispatch($data, (int) $actor->id);
                } elseif (str_starts_with($input['action'], 'sales.request.')) {
                    $service=app(\App\Services\Sales\FulfillmentRequestService::class);
                    $result=match ($input['action']) {
                        'sales.request.upsert'=>$service->upsert($data,(int)$actor->id),
                        'sales.request.cancel'=>$service->cancel($data,(int)$actor->id),
                        'sales.request.status'=>$service->sourceStatus($data,(int)$actor->id),
                    };
                } else {
                    validator($data,['invoice_revision'=>'required|string|size:64'])->validate();
                    $action=substr($input['action'],strlen('sales.fulfillment.'));
                    if (in_array($action,['prepare','execute','status','abandon'],true)) {
                        validator($data,['operation_uuid'=>'required|uuid'])->validate();
                    }
                    if ($action==='approve') validator($data,['warehouse_id'=>'required|integer|min:1'])->validate();
                    $result=app(\App\Services\Sales\FinanceDispatchService::class)->{$action}($data);
                }
                return response()->json(['success'=>true,'data'=>$result]);
            }
            if (str_starts_with($input['action'], 'purchasing.request.') || str_starts_with($input['action'], 'purchasing.bill.') || str_starts_with($input['action'], 'purchasing.receiving.')) {
                $billId = (int) data_get($input, 'data.source_bill_id');
                abort_unless($billId > 0, 422);
                $permission = $input['action'] === 'purchasing.bill.cost-adjustment.prepare' ? 'post' : (in_array($input['action'], ['purchasing.request.status', 'purchasing.bill.receipt', 'purchasing.bill.context', 'purchasing.receiving.options', 'purchasing.receiving.prepare', 'purchasing.receiving.execute', 'purchasing.receiving.status', 'purchasing.receiving.approve', 'purchasing.receiving.abandon'], true) ? 'view' : 'edit_draft');
                $postedRequest = in_array($input['action'], ['purchasing.request.upsert', 'purchasing.request.cancel'], true) && data_get($input, 'data.source_status') === 'posted';
                if ($postedRequest) {
                    $permission = 'post';
                }
                $closurePermission = data_get($input, 'data.close_permission');
                $closing = $input['action'] === 'purchasing.request.cancel' && in_array($closurePermission, ['unpost', 'void'], true);
                if ($closing) {
                    $permission = $closurePermission;
                }
                $authority = app(SolaBooksOutboxDeliveryService::class)->authorizePurchasing((int) $actor->id, $billId, $permission, $input['action']==='purchasing.request.cancel' ? ((array)$input['data']+['command'=>'cancel']) : []);
                if ($closing) {
                    abort_unless(($authority['closure_permission'] ?? null) === $closurePermission
                        && (int) data_get($input, 'data.closing_bill_journal_id') > 0
                        && (int) ($authority['closing_bill_journal_id'] ?? 0) === (int) data_get($input, 'data.closing_bill_journal_id')
                        && (int) ($authority['bill_journal_id'] ?? 0) === (int) data_get($input, 'data.closing_bill_journal_id'), 403);
                }
                if ($postedRequest && $input['action'] === 'purchasing.request.cancel') {
                    abort_unless(($authority['status'] ?? null) === 'posted' && (int) ($authority['bill_journal_id'] ?? 0) > 0 && (int) $authority['bill_journal_id'] === (int) data_get($input, 'data.posted_bill_journal_id') && data_get($input, 'data.billing_policy') === 'billed-unreceived-v1', 403);
                }
                if ($input['action'] === 'purchasing.request.upsert') {
                    if ((int) data_get($input, 'data.receiving_generation', 0) > 0) {
                        abort_unless($postedRequest
                            && ($authority['receiving_request_uuid'] ?? null) === data_get($input, 'data.request_uuid')
                            && (int) ($authority['receiving_request_generation'] ?? 0) === (int) data_get($input, 'data.receiving_generation')
                            && ($authority['previous_request_uuid'] ?? null) === data_get($input, 'data.previous_request_uuid'), 403);
                    } else {
                        abort_if(data_get($input, 'data.previous_request_uuid') !== null, 422);
                    }
                    abort_unless(($authority['status'] ?? null) === ($postedRequest ? 'posted' : 'draft') && ! empty($authority['request_revision']) && hash_equals((string) $authority['request_revision'], (string) data_get($input, 'data.source_revision')), 409, __('inventory.purchasing.refresh_required'));
                    if ($postedRequest) {
                        abort_unless((int) ($authority['bill_journal_id'] ?? 0) > 0 && (int) $authority['bill_journal_id'] === (int) data_get($input, 'data.posted_bill_journal_id') && data_get($input, 'data.billing_policy') === 'billed-unreceived-v1', 403);
                    }
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
            if (str_starts_with($input['action'], 'purchasing.receiving.')) {
                abort_unless($mapping && $mapping->status === 'verified' && $mapping->activation_state === 'active'
                    && $setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
                abort_unless(hash_equals((string) ($request->attributes->get('purchasing_authority')['source_revision'] ?? ''), (string) data_get($input, 'data.bill_revision')), 409, __('inventory.purchasing.refresh_required'));
                $data = (array) ($input['data'] ?? []);
                $rules = ['source_bill_id' => 'required|integer|min:1', 'request_uuid' => 'required|uuid',
                    'bill_revision' => 'required|string|size:64', 'request_revision' => 'sometimes|string|size:64'];
                $action = substr($input['action'], strlen('purchasing.receiving.'));
                abort_unless(in_array($action, ['options', 'prepare', 'execute', 'status', 'approve', 'abandon'], true), 422);
                if (in_array($action, ['prepare', 'execute', 'status', 'abandon'], true)) {
                    $rules['operation_uuid'] = 'required|uuid';
                }
                if (in_array($action, ['prepare', 'execute'], true)) {
                    $rules += ['warehouse_id' => 'required|integer|min:1', 'receipt_date' => 'required|date',
                        'lines' => 'required|array|min:1', 'lines.*.source_bill_line_id' => 'required|integer|min:1|distinct',
                        'lines.*.request_line_id' => 'required|integer|min:1|distinct',
                        'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.unit_id' => 'required|integer|min:1',
                        'lines.*.unit_cost' => 'nullable|numeric|min:0', 'lines.*.lot_code' => 'nullable|string|max:100',
                        'lines.*.expiry_date' => 'nullable|date', 'lines.*.serials' => 'nullable|array',
                        'lines.*.serials.*' => 'string|max:100', 'lines.*.bin_id' => 'nullable|integer|min:1', 'lines.*.variant_id' => 'nullable|integer|min:1'];
                }
                if ($action === 'approve') {
                    $rules['warehouse_id'] = 'required|integer|min:1';
                }
                $data = validator($data, $rules)->validate();
                if ($action === 'approve') {
                    abort_unless(app(InventoryPermissionService::class)->can($actor, 'inventory.approve_purchase_orders'), 403);
                    $source = ReceivingRequest::query()->where('request_uuid', $data['request_uuid'])
                        ->where('source_bill_id', $data['source_bill_id'])->firstOrFail();
                    abort_unless(hash_equals($source->source_revision, (string) ($request->attributes->get('purchasing_authority')['request_revision'] ?? '')), 409);
                    $result = app(ReceivingRequestService::class)->approve($source, $data['warehouse_id']);
                } else {
                    $result = app(FinanceReceivingService::class)->{$action}($data);
                }

                return response()->json(['success' => true, 'data' => $result]);
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
