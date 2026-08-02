<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\InventorySetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only integration status + health for the settings page, sync dashboard,
 * and event viewer summary.
 */
class IntegrationStatusService
{
    public const MODES = ['disconnected', 'connected_readonly', 'connected_pending_mapping', 'active', 'paused', 'error'];

    public const REQUIRED_ACCOUNT_MAPPINGS = [
        'inventory_asset', 'cogs', 'adjustment_gain', 'adjustment_loss', 'grni',
        'landed_cost_clearing', 'transfer_clearing', 'opening_offset',
        'sales_returns', 'purchase_returns',
        'accounts_receivable', 'sales_revenue',
    ];

    public function status(int $orgId): array
    {
        $safety = app(IntegrationSafetyHold::class);
        $settings = IntegrationSetting::query()->firstOrNew([
            'organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION,
        ]);
        $organizationMapping = Schema::connection('tenant')->hasTable('integration_organization_mappings')
            ? IntegrationOrganizationMapping::query()
                ->where('solastock_organization_id', $orgId)
                ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
                ->where('contract_version', 'solastock-journal.v2')
                ->whereIn('status', ['verified_hold', 'verified'])
                ->whereIn('activation_state', ['maintenance_hold', 'active'])
                ->first()
            : null;
        $sharedFinanceOrgId = $organizationMapping?->finance_organization_id;
        $workspaceConnected = $organizationMapping !== null;
        $configuredMode = $settings->mode ?? 'disconnected';
        // SolaStock and SolaBooks already share the client's tenant database and
        // organization registry. Without an outbox API credential that is a real
        // read-only workspace connection, not a disconnected product.
        $mode = ! $workspaceConnected && $settings->exists
            ? 'connected_pending_mapping'
            : ($configuredMode === 'disconnected' && $workspaceConnected ? 'connected_readonly' : $configuredMode);

        $events = IntegrationOutboxEvent::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION);
        $pending = (clone $events)->where('status', 'pending')->count();
        $failed = (clone $events)->where('status', 'failed')->count();
        $sent = (clone $events)->where('status', 'sent')->count();
        $lastSuccessfulDeliveryAt = $settings->meta['last_signed_delivery_at']
            ?? (clone $events)->where('status', 'sent')->max('sent_at');
        $ignored = (clone $events)->where('status', 'ignored')->get(['event_type', 'payload'])
            ->filter(fn (IntegrationOutboxEvent $event) => IntegrationEvents::isAccountingEventForReconciliation(
                (string) $event->event_type,
                (array) $event->payload
            ))->count();
        $incompleteMapping = (clone $events)->where('mapping_status', 'incomplete')->whereIn('status', ['pending', 'failed'])->count();
        $transportCounts = (clone $events)
            ->selectRaw('status, COUNT(*) total')
            ->groupBy('status')->pluck('total', 'status');
        $oldestActionable = (clone $events)
            ->whereIn('status', ['ready', 'processing', 'retry_scheduled'])
            ->min('occurred_at');
        $expiredLeases = (clone $events)
            ->where('status', 'processing')->where('lease_expires_at', '<', now())->count();
        $workerHeartbeat = Schema::connection('tenant')->hasTable('integration_transport_worker_heartbeats')
            ? DB::connection('tenant')->table('integration_transport_worker_heartbeats')
                ->orderByDesc('last_seen_at')->first()
            : null;
        $workerRunning = (bool) $workerHeartbeat
            && $workerHeartbeat->state === 'running'
            && Carbon::parse($workerHeartbeat->last_seen_at)->gte(now()->subMinutes(2));

        $mapped = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('status', ['mapped', 'verified'])->pluck('mapping_type')->all();
        $mappingCompleteness = round(count(array_intersect(self::REQUIRED_ACCOUNT_MAPPINGS, $mapped)) / count(self::REQUIRED_ACCOUNT_MAPPINGS) * 100);
        $taxCodes = collect((array) (InventorySetting::query()->first()?->taxes ?? []))
            ->where('active', true)->pluck('code')->filter()->unique()->values();
        $mappedTaxCodes = IntegrationTaxMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->where('status', 'mapped')
            ->pluck('tax_code')->unique();
        $taxMappingCompleteness = $taxCodes->isEmpty()
            ? 100
            : round($taxCodes->intersect($mappedTaxCodes)->count() / $taxCodes->count() * 100);
        $masterCoverage = $organizationMapping && Schema::connection('tenant')->hasTable('integration_master_data_mappings')
            ? DB::connection('tenant')->table('integration_master_data_mappings')
                ->where('organization_mapping_uuid', $organizationMapping->mapping_uuid)
                ->selectRaw("SUM(CASE WHEN status IN ('mapped','verified') THEN 1 ELSE 0 END) mapped")
                ->selectRaw("SUM(CASE WHEN status IN ('missing_finance','missing_solastock') THEN 1 ELSE 0 END) missing")
                ->selectRaw("SUM(CASE WHEN status = 'conflict' THEN 1 ELSE 0 END) conflicting")
                ->selectRaw("SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) archived")
                ->selectRaw("SUM(CASE WHEN status = 'review_required' THEN 1 ELSE 0 END) review_required")
                ->first()
            : null;
        $discoveryCoverage = null;
        if ($organizationMapping && Schema::connection('tenant')->hasTable('integration_mapping_discovery_runs')) {
            $lastRun = DB::connection('tenant')->table('integration_mapping_discovery_runs')
                ->where('organization_mapping_uuid', $organizationMapping->mapping_uuid)
                ->orderByDesc('started_at')
                ->value('run_uuid');
            if ($lastRun) {
                $discoveryCoverage = DB::connection('tenant')->table('integration_mapping_discovery_results')
                    ->where('run_uuid', $lastRun)
                    ->selectRaw("SUM(CASE WHEN classification LIKE 'missing_%' THEN 1 ELSE 0 END) missing")
                    ->selectRaw("SUM(CASE WHEN classification IN ('ambiguous_match','conflicting_mapping','conflicting_candidates') THEN 1 ELSE 0 END) conflicting")
                    ->selectRaw("SUM(CASE WHEN classification = 'archived_match' THEN 1 ELSE 0 END) archived")
                    ->selectRaw("SUM(CASE WHEN classification IN ('cross_organization_risk','incompatible_schema','unit_conversion_incompatible','account_incompatible','tax_incompatible','review_required') THEN 1 ELSE 0 END) review_required")
                    ->first();
            }
        }
        $workflowCoverage = $organizationMapping
            && Schema::connection('tenant')->hasTable('integration_document_lifecycle_mappings')
            ? DB::connection('tenant')->table('integration_document_lifecycle_mappings')
                ->where('organization_mapping_uuid', $organizationMapping->mapping_uuid)
                ->selectRaw('COUNT(*) total')
                ->selectRaw("SUM(CASE WHEN matching_state = 'matched' THEN 1 ELSE 0 END) matched")
                ->selectRaw("SUM(CASE WHEN matching_state IN ('unmatched','partially_matched') THEN 1 ELSE 0 END) pending")
                ->selectRaw("SUM(CASE WHEN matching_state = 'conflict' OR conflict_code IS NOT NULL THEN 1 ELSE 0 END) conflicting")
                ->selectRaw("SUM(CASE WHEN lifecycle_status = 'review_required' OR error_state IS NOT NULL THEN 1 ELSE 0 END) review_required")
                ->selectRaw("SUM(CASE WHEN source_document_type IN ('purchase_order','goods_receipt','supplier_return','landed_cost') THEN 1 ELSE 0 END) purchasing")
                ->selectRaw("SUM(CASE WHEN source_document_type IN ('sales_order','reservation','pick_list','pack','shipment','sales_return') THEN 1 ELSE 0 END) sales")
                ->first()
            : null;
        $authoritativeState = 'unavailable';
        $lastAuthoritativeRead = null;
        try {
            if (Schema::connection('tenant')->hasTable('stock_balances')) {
                $hasBalances = DB::connection('tenant')->table('stock_balances')
                    ->where('organization_id', $orgId)->exists();
                $lastAuthoritativeRead = now()->toIso8601String();
                $authoritativeState = $hasBalances ? 'available' : 'empty';
            }
        } catch (\Throwable) {
            $authoritativeState = 'unavailable';
        }

        $deliveryEnabled = $safety->deliveryEnabledFor($orgId);
        $workerEnabled = $safety->workerEnabledFor($orgId);
        $health = match (true) {
            ! $deliveryEnabled => 'maintenance_hold',
            $mode === 'disconnected' => 'disconnected',
            $failed > 0 => 'error',
            $incompleteMapping > 0 => 'needs_mapping',
            default => 'healthy',
        };
        $wizardRun = $organizationMapping && Schema::connection('tenant')->hasTable('integration_connection_wizard_runs')
            ? DB::connection('tenant')->table('integration_connection_wizard_runs')
                ->where('organization_mapping_uuid', $organizationMapping->mapping_uuid)
                ->orderByDesc('created_at')->first()
            : null;
        $connectionState = match (true) {
            ! $organizationMapping && ! $settings->exists => 'not_subscribed',
            ! $organizationMapping => 'subscription_available',
            ! $wizardRun && $mappingCompleteness < 100 => 'setup_required',
            ! $wizardRun => 'subscription_available',
            $wizardRun->invalidated_at !== null => 'snapshot_cutoff_validation',
            $wizardRun->state === 'review_required' => 'review_required',
            $wizardRun->state === 'ready_for_approval' => 'ready_for_approval',
            $wizardRun->state === 'approved_maintenance_hold' => 'maintenance_hold',
            $wizardRun->state === 'active' && ($incompleteMapping > 0) => 'connected_with_blocked_records',
            $wizardRun->state === 'active' => 'connected',
            $wizardRun->state === 'paused' => 'paused_disconnected',
            default => (string) $wizardRun->state,
        };

        return [
            'integration' => IntegrationEvents::INTEGRATION,
            'mode' => $mode,
            'workspace_connected' => $workspaceConnected,
            'solabooks_organization_id' => $settings->solabooks_organization_id ?: $sharedFinanceOrgId,
            'last_sync_at' => $settings->last_sync_at,
            'last_error' => $settings->last_error,
            'require_mapping_before_post' => (bool) ($settings->require_mapping_before_post ?? false),
            'health' => $health,
            'connection_state' => $connectionState,
            'connection_wizard' => $wizardRun ? [
                'run_uuid' => $wizardRun->run_uuid,
                'state' => $wizardRun->state,
                'cutoff_at' => $wizardRun->cutoff_at,
                'snapshot_id' => $wizardRun->snapshot_id,
                'snapshot_hash' => $wizardRun->snapshot_hash,
                'approval_payload_hash' => $wizardRun->approval_payload_hash,
                'invalidated_at' => $wizardRun->invalidated_at,
                'activated_at' => $wizardRun->activated_at,
            ] : null,
            'events' => compact('pending', 'failed', 'sent', 'ignored'),
            'documents_awaiting_sync' => $pending,
            'mapping_incomplete_events' => $incompleteMapping,
            'mapping_completeness_pct' => $mappingCompleteness,
            'tax_mapping_completeness_pct' => $taxMappingCompleteness,
            'last_event_generated_at' => IntegrationOutboxEvent::query()->where('organization_id', $orgId)->max('occurred_at'),
            'connection_implemented' => $workspaceConnected,
            'organization_mapping' => $organizationMapping ? [
                'mapping_uuid' => $organizationMapping->mapping_uuid,
                'status' => $organizationMapping->status,
                'activation_state' => $organizationMapping->activation_state,
                'contract_version' => $organizationMapping->contract_version,
                'v2_key_scope_status' => $organizationMapping->v2_key_scope_status,
                'verified_at' => $organizationMapping->verified_at,
            ] : null,
            'inventory_authority' => [
                'inventory_source' => 'solastock',
                'accounting_source' => 'solabooks',
                'state' => $authoritativeState,
                'last_successful_read_at' => $lastAuthoritativeRead,
                'mapped' => (int) ($masterCoverage->mapped ?? 0),
                'missing' => max((int) ($masterCoverage->missing ?? 0), (int) ($discoveryCoverage->missing ?? 0)),
                'conflicting' => max((int) ($masterCoverage->conflicting ?? 0), (int) ($discoveryCoverage->conflicting ?? 0)),
                'archived' => max((int) ($masterCoverage->archived ?? 0), (int) ($discoveryCoverage->archived ?? 0)),
                'review_required' => max((int) ($masterCoverage->review_required ?? 0), (int) ($discoveryCoverage->review_required ?? 0)),
            ],
            'workflow_lifecycle' => [
                'schema_version' => 'phase3.v1',
                'total' => (int) ($workflowCoverage->total ?? 0),
                'matched' => (int) ($workflowCoverage->matched ?? 0),
                'pending' => (int) ($workflowCoverage->pending ?? 0),
                'conflicting' => (int) ($workflowCoverage->conflicting ?? 0),
                'review_required' => (int) ($workflowCoverage->review_required ?? 0),
                'purchasing' => (int) ($workflowCoverage->purchasing ?? 0),
                'sales' => (int) ($workflowCoverage->sales ?? 0),
                'execution_enabled' => false,
                'delivery_enabled' => $deliveryEnabled,
            ],
            'delivery_configured' => (bool) (($settings->apiKey() || config('services.solabooks.api_key'))
                && ($settings->meta['client_id'] ?? config('services.solabooks.client_id'))
                && ($settings->solabooks_organization_id || config('services.solabooks.organization_id'))),
            'delivery_enabled' => $deliveryEnabled,
            'delivery_disabled_reason' => $deliveryEnabled ? null : $safety->reason(),
            'delivery_disabled_message' => $deliveryEnabled ? null : $safety->message(),
            'last_successful_delivery_at' => $lastSuccessfulDeliveryAt,
            'transport' => [
                'queue' => (string) config('integration_transport.worker.queue'),
                'worker_enabled' => $workerEnabled,
                'worker_running' => $workerRunning,
                'worker_last_seen_at' => $workerHeartbeat?->last_seen_at,
                'worker_served_commit' => $workerHeartbeat?->served_commit,
                'receiver_enabled' => $deliveryEnabled,
                'schedule_enabled' => (bool) config('integration_transport.reconciliation_schedule_enabled', false),
                'counts' => collect([
                    'pending', 'review_required', 'blocked_mapping', 'blocked_contract',
                    'ready', 'processing', 'retry_scheduled', 'sent', 'failed',
                    'dead_letter', 'ignored', 'superseded', 'reversed',
                ])->mapWithKeys(fn (string $state): array => [
                    $state => (int) ($transportCounts[$state] ?? 0),
                ])->all(),
                'oldest_actionable_at' => $oldestActionable,
                'expired_leases' => $expiredLeases,
                'last_reconciliation_at' => null,
                'reconciliation_state' => 'not_scheduled',
            ],
            'legacy_finance_inventory_writes_blocked' => (bool) config('integration_safety.legacy_finance_inventory_writes_blocked', false)
                && ($workspaceConnected || in_array($mode, ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'], true)),
            'signing' => [
                'configured' => (bool) ($settings->signingSecret() && ($settings->meta['signing_key_id'] ?? null)),
                'key_id' => $settings->meta['signing_key_id'] ?? null,
                'protocol_version' => $settings->meta['signing_protocol_version'] ?? null,
                'rotated_at' => $settings->meta['signing_key_rotated_at'] ?? null,
                'last_successful_delivery_at' => $lastSuccessfulDeliveryAt,
            ],
        ];
    }

    protected function sharedFinanceOrganizationId(int $orgId): ?int
    {
        try {
            if (! Schema::connection('tenant')->hasTable('organizations')
                || ! Schema::connection('tenant')->hasColumn('organizations', 'central_org_id')) {
                return null;
            }

            $id = DB::connection('tenant')->table('organizations')
                ->where('central_org_id', $orgId)
                ->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
