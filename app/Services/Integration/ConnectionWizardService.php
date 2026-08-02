<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Services\Catalog\SolaBooksItemCatalogBridge;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ConnectionWizardService
{
    public const VERSION = 'solabooks-solastock.connection-wizard.v1';

    public const DECISIONS = [
        'bind_existing',
        'create_solastock_record',
        'keep_solastock_authority',
        'physical_count_required',
        'retain_blocked',
        'exclude_initial_connection',
        'select_authoritative_record',
        'resolve_account_category',
    ];

    public function __construct(
        private readonly Phase2MappingDiscoveryService $discovery,
        private readonly IntegrationSafetyHold $safety,
        private readonly SolaBooksItemCatalogBridge $catalogBridge,
    ) {}

    /** Read-only. No discovery run, counter, nonce, event, or audit is written. */
    public function discover(int $organizationId): array
    {
        $mapping = $this->mappingOrNull($organizationId);
        if (! $mapping) {
            return $this->preMappingReadiness($organizationId);
        }
        $report = $this->discovery->discover($mapping->mapping_uuid);
        $comparison = collect($report['results'])->map(
            fn (array $candidate): array => $this->comparisonRow($mapping, $candidate)
        )->all();
        $totals = $this->totals($comparison);
        $accounting = $this->accountingSetup($mapping);
        $masterData = $this->masterDataSetup($mapping);
        $core = [
            'version' => self::VERSION,
            'organization_mapping_uuid' => $mapping->mapping_uuid,
            'identity' => $this->identity($mapping),
            'discovery_manifest_hash' => $report['manifest_hash'],
            'discovery_before_image_hash' => $report['before_image_hash'],
            'comparison' => $comparison,
            'totals' => $totals,
            'accounting' => $accounting,
            'master_data' => $masterData,
        ];

        return $core + [
            'read_only' => true,
            'generated_at' => now()->utc()->toIso8601String(),
            'snapshot_hash' => $this->hash($core),
            'connection_state' => $this->state($comparison, $accounting, $masterData),
        ];
    }

    public function start(int $organizationId, int $actorUserId): array
    {
        // Readiness is available before mapping, but a mutable approval run is
        // not. This prevents a subscription row or guessed local identity from
        // becoming a connection identity.
        $this->mapping($organizationId);
        $preview = $this->discover($organizationId);
        $cutoff = now()->utc();
        $runUuid = (string) Str::uuid();
        $snapshotId = 'CONNECTION-'.$organizationId.'-'.$cutoff->format('Ymd\THis\Z').'-'.strtoupper(substr($runUuid, 0, 8));
        $state = $preview['connection_state'] === 'ready_for_approval' ? 'ready_for_approval' : 'review_required';

        DB::connection('tenant')->transaction(function () use ($preview, $cutoff, $runUuid, $snapshotId, $state, $actorUserId): void {
            DB::connection('tenant')->table('integration_connection_wizard_runs')->insert([
                'run_uuid' => $runUuid,
                'organization_mapping_uuid' => $preview['organization_mapping_uuid'],
                'central_client_id' => $preview['identity']['central_client_id'],
                'central_organization_id' => $preview['identity']['central_organization_id'],
                'finance_organization_id' => $preview['identity']['finance_organization_id'],
                'solastock_organization_id' => $preview['identity']['solastock_organization_id'],
                'state' => $state,
                'cutoff_at' => $cutoff,
                'snapshot_id' => $snapshotId,
                'snapshot_hash' => $preview['snapshot_hash'],
                'discovery_manifest_hash' => $preview['discovery_manifest_hash'],
                'discovery_before_image_hash' => $preview['discovery_before_image_hash'],
                'authority_choices' => json_encode(['inventory' => 'solastock', 'accounting' => 'solabooks']),
                'workflow_allowlist' => json_encode(config('integration_connection_wizard.allowed_workflows', [])),
                'comparison_totals' => json_encode($preview['totals']),
                'snapshot_payload' => $this->canonicalJson([
                    'comparison' => $preview['comparison'],
                    'totals' => $preview['totals'],
                    'accounting' => $preview['accounting'],
                    'master_data' => $preview['master_data'],
                ]),
                'created_by_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'wizard_started', null, [
                'snapshot_id' => $snapshotId,
                'snapshot_hash' => $preview['snapshot_hash'],
                'state' => $state,
            ], $actorUserId);
        });

        return $this->show($organizationId, $runUuid);
    }

    public function show(int $organizationId, string $runUuid): array
    {
        $mapping = $this->mapping($organizationId);
        $run = $this->run($mapping, $runUuid);
        $decisions = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $runUuid)->orderBy('candidate_fingerprint')->get()->map(fn ($row) => [
                'decision_uuid' => $row->decision_uuid,
                'candidate_fingerprint' => $row->candidate_fingerprint,
                'entity_type' => $row->entity_type,
                'action' => $row->action,
                'solastock_record_ids' => json_decode($row->solastock_record_ids ?: '[]', true),
                'solabooks_record_ids' => json_decode($row->solabooks_record_ids ?: '[]', true),
                'safe_details' => json_decode($row->safe_details ?: '{}', true),
                'status' => $row->status,
                'updated_at' => $row->updated_at,
            ])->all();

        return [
            'version' => self::VERSION,
            'run_uuid' => $run->run_uuid,
            'state' => $run->state,
            'cutoff_at' => $run->cutoff_at,
            'snapshot_id' => $run->snapshot_id,
            'snapshot_hash' => $run->snapshot_hash,
            'approval_payload_hash' => $run->approval_payload_hash,
            'identity' => $this->identity($mapping),
            'decisions' => $decisions,
            'approved_at' => $run->approved_at,
            'invalidated_at' => $run->invalidated_at,
            'invalidation_reason' => $run->invalidation_reason,
            'activated_at' => $run->activated_at,
            'activation_available' => $this->activationGateReady($organizationId),
        ];
    }

    public function decide(
        int $organizationId,
        string $runUuid,
        string $fingerprint,
        string $action,
        array $stockIds,
        array $booksIds,
        array $safeDetails,
        int $actorUserId,
    ): array {
        if (! in_array($action, self::DECISIONS, true)) {
            $this->fail('unsupported_mapping_decision');
        }
        $mapping = $this->mapping($organizationId);

        DB::connection('tenant')->transaction(function () use ($mapping, $runUuid, $fingerprint, $action, $stockIds, $booksIds, $safeDetails, $actorUserId): void {
            $run = $this->run($mapping, $runUuid, true);
            if (! in_array($run->state, ['review_required', 'ready_for_approval'], true) || $run->invalidated_at) {
                $this->fail('wizard_run_not_editable');
            }
            $preview = $this->discover((int) $mapping->solastock_organization_id);
            $candidate = collect($preview['comparison'])->firstWhere('fingerprint', $fingerprint);
            if (! $candidate) {
                $this->fail('candidate_before_image_changed');
            }
            $beforeHash = $this->hash($candidate);
            $current = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('candidate_fingerprint', $fingerprint)->lockForUpdate()->first();
            $decisionUuid = $current?->decision_uuid ?? (string) Str::uuid();
            $before = $current ? (array) $current : null;
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->updateOrInsert(
                ['run_uuid' => $runUuid, 'candidate_fingerprint' => $fingerprint],
                [
                    'decision_uuid' => $decisionUuid,
                    'entity_type' => $candidate['entity_type'],
                    'action' => $action,
                    'solastock_record_ids' => json_encode(array_values(array_map('strval', $stockIds))),
                    'solabooks_record_ids' => json_encode(array_values(array_map('strval', $booksIds))),
                    'candidate_before_hash' => $beforeHash,
                    'safe_details' => json_encode($this->safeDecisionDetails($safeDetails)),
                    'status' => 'selected',
                    'actor_user_id' => $actorUserId,
                    'created_at' => $current?->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );
            $after = compact('fingerprint', 'action', 'stockIds', 'booksIds', 'beforeHash');
            $this->audit($runUuid, $decisionUuid, $current ? 'decision_revised' : 'decision_selected', $before, $after, $actorUserId);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)
                ->update(['state' => 'review_required', 'approval_payload_hash' => null, 'approved_by_user_id' => null, 'approved_at' => null, 'updated_at' => now()]);
        });

        return $this->finalPreview($organizationId, $runUuid);
    }

    public function reverseDecision(int $organizationId, string $runUuid, string $decisionUuid, int $actorUserId): array
    {
        $mapping = $this->mapping($organizationId);
        DB::connection('tenant')->transaction(function () use ($mapping, $runUuid, $decisionUuid, $actorUserId): void {
            $this->run($mapping, $runUuid, true);
            $decision = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('decision_uuid', $decisionUuid)->lockForUpdate()->first();
            if (! $decision || $decision->status === 'reversed') {
                $this->fail('decision_not_reversible');
            }
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->where('id', $decision->id)
                ->update(['status' => 'reversed', 'actor_user_id' => $actorUserId, 'updated_at' => now()]);
            $this->audit($runUuid, $decisionUuid, 'decision_reversed', (array) $decision, ['status' => 'reversed'], $actorUserId);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)
                ->update(['state' => 'review_required', 'approval_payload_hash' => null, 'approved_by_user_id' => null, 'approved_at' => null, 'updated_at' => now()]);
        });

        return $this->finalPreview($organizationId, $runUuid);
    }

    public function finalPreview(int $organizationId, string $runUuid): array
    {
        $mapping = $this->mapping($organizationId);
        $run = $this->run($mapping, $runUuid);
        $preview = $this->discover($organizationId);
        if (! hash_equals($run->discovery_manifest_hash, $preview['discovery_manifest_hash'])
            || ! hash_equals($run->discovery_before_image_hash, $preview['discovery_before_image_hash'])
            || ! hash_equals($run->snapshot_hash, $preview['snapshot_hash'])) {
            $this->fail('snapshot_or_before_image_changed');
        }

        $decisions = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $runUuid)->where('status', 'selected')->get()->keyBy('candidate_fingerprint');
        $blocking = collect($preview['comparison'])->filter(function (array $candidate) use ($decisions): bool {
            if ($candidate['blocking_reason'] === null) {
                return false;
            }
            $decision = $decisions->get($candidate['fingerprint']);
            return ! $decision || in_array($decision->action, ['physical_count_required', 'retain_blocked'], true);
        })->values();
        $approvalCore = [
            'version' => self::VERSION,
            'run_uuid' => $runUuid,
            'identity' => $this->identity($mapping),
            'cutoff_at' => (string) $run->cutoff_at,
            'snapshot_id' => $run->snapshot_id,
            'snapshot_hash' => $run->snapshot_hash,
            'decisions' => $decisions->sortKeys()->map(fn ($row) => [
                'fingerprint' => $row->candidate_fingerprint,
                'action' => $row->action,
                'stock_ids' => json_decode($row->solastock_record_ids ?: '[]', true),
                'books_ids' => json_decode($row->solabooks_record_ids ?: '[]', true),
                'before_hash' => $row->candidate_before_hash,
            ])->values()->all(),
            'workflows' => json_decode($run->workflow_allowlist ?: '[]', true),
            'authority' => ['inventory' => 'solastock', 'accounting' => 'solabooks'],
            'legacy_finance_inventory_becomes_read_only' => true,
            'proposed_accounting_effect' => $preview['totals']['proposed_accounting_effect'],
            'blocked_fingerprints' => $blocking->pluck('fingerprint')->all(),
        ];
        $approvalHash = $this->hash($approvalCore);
        $ready = $blocking->isEmpty() && $preview['accounting']['complete'];
        $ready = $ready && $preview['master_data']['complete'];
        return $approvalCore + [
            'approval_payload_hash' => $approvalHash,
            'state' => $ready ? 'ready_for_approval' : 'review_required',
            'comparison' => $preview['comparison'],
            'totals' => $preview['totals'],
            'accounting' => $preview['accounting'],
            'master_data' => $preview['master_data'],
            'blocking' => $blocking->all(),
            'rollback_behavior' => 'Before activation, reverse decisions and regenerate the snapshot. After activation, pause delivery; operational and accounting reversals remain separate and preserve evidence.',
        ];
    }

    public function approve(int $organizationId, string $runUuid, string $approvalHash, string $confirmation, int $actorUserId): array
    {
        $preview = $this->finalPreview($organizationId, $runUuid);
        if ($preview['state'] !== 'ready_for_approval'
            || ! hash_equals($preview['approval_payload_hash'], $approvalHash)
            || ! hash_equals((string) config('integration_connection_wizard.confirmation_phrase'), $confirmation)) {
            $this->fail('approval_confirmation_mismatch');
        }
        $mapping = $this->mapping($organizationId);
        DB::connection('tenant')->transaction(function () use ($mapping, $runUuid, $approvalHash, $actorUserId, $organizationId): void {
            $run = $this->run($mapping, $runUuid, true);
            $current = $this->finalPreview($organizationId, $runUuid);
            if ($current['state'] !== 'ready_for_approval'
                || ! hash_equals((string) $current['approval_payload_hash'], $approvalHash)
                || ! hash_equals((string) $run->snapshot_hash, (string) $current['snapshot_hash'])) {
                $this->fail('approval_payload_changed');
            }
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)->update([
                'state' => 'approved_maintenance_hold',
                'approval_payload_hash' => $approvalHash,
                'approved_by_user_id' => $actorUserId,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'wizard_approved', null, ['approval_payload_hash' => $approvalHash], $actorUserId);
        });

        return $this->show($organizationId, $runUuid);
    }

    public function activate(
        int $organizationId,
        string $runUuid,
        string $approvalHash,
        string $activationApprovalId,
        string $confirmation,
        int $actorUserId,
    ): array {
        if (! $this->activationGateReady($organizationId)
            || ! hash_equals((string) config('integration_connection_wizard.activation_approval_id'), $activationApprovalId)
            || ! hash_equals((string) config('integration_connection_wizard.confirmation_phrase'), $confirmation)) {
            $this->fail('organization_scoped_activation_gate_closed');
        }

        $mapping = $this->mapping($organizationId);
        DB::connection('tenant')->transaction(function () use ($mapping, $organizationId, $runUuid, $approvalHash, $actorUserId): void {
            $run = $this->run($mapping, $runUuid, true);
            if ($run->state === 'connected' && hash_equals((string) $run->approval_payload_hash, $approvalHash)) {
                return;
            }
            if ($run->state !== 'approved_maintenance_hold'
                || ! hash_equals((string) $run->approval_payload_hash, $approvalHash)) {
                $this->fail('approved_immutable_preview_required');
            }
            $current = $this->finalPreview($organizationId, $runUuid);
            if ($current['state'] !== 'ready_for_approval'
                || ! hash_equals((string) $current['approval_payload_hash'], $approvalHash)) {
                $this->fail('activation_snapshot_changed');
            }
            if (! in_array($mapping->v2_key_scope_status, ['provisioned_held', 'active'], true)
                || ! $mapping->current_v2_signing_key_id) {
                $this->fail('v2_signing_scope_not_ready');
            }

            $this->applyApprovedDecisions($mapping, $runUuid, $actorUserId);

            $setting = IntegrationSetting::query()->where('organization_id', $organizationId)
                ->where('integration', 'solabooks')->lockForUpdate()->firstOrFail();
            $meta = (array) $setting->meta;
            $meta['transport_enabled'] = true;
            $meta['transport_enabled_workflows'] = array_values(json_decode($run->workflow_allowlist ?: '[]', true));
            $setting->update(['mode' => 'active', 'meta' => $meta, 'updated_at' => now()]);
            $mapping->update(['status' => 'verified', 'activation_state' => 'active']);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)->update([
                'state' => 'connected', 'activated_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'organization_connection_activated', null, [
                'organization_id' => $organizationId, 'approval_payload_hash' => $approvalHash,
            ], $actorUserId);
        });

        return $this->show($organizationId, $runUuid);
    }

    private function applyApprovedDecisions(
        IntegrationOrganizationMapping $organizationMapping,
        string $runUuid,
        int $actorUserId,
    ): void {
        $decisions = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $runUuid)->where('status', 'selected')->orderBy('id')->lockForUpdate()->get();

        foreach ($decisions as $decision) {
            if (! in_array($decision->action, [
                'bind_existing', 'create_solastock_record', 'keep_solastock_authority',
                'select_authoritative_record', 'resolve_account_category',
            ], true)) {
                continue;
            }

            $stockIds = array_values(json_decode($decision->solastock_record_ids ?: '[]', true));
            $booksIds = array_values(json_decode($decision->solabooks_record_ids ?: '[]', true));
            $createsStock = $decision->action === 'create_solastock_record';
            $createsBooks = $decision->action === 'keep_solastock_authority';
            if (($createsStock && (count($booksIds) !== 1 || $stockIds !== []))
                || ($createsBooks && (count($stockIds) !== 1 || $booksIds !== []))
                || (! $createsStock && ! $createsBooks && (count($booksIds) !== 1 || count($stockIds) !== 1))) {
                $this->fail('mapping_decision_requires_exact_records');
            }

            $booksId = $createsBooks
                ? $this->createFinanceCatalogRecordFromStock($organizationMapping, $decision, (string) $stockIds[0])
                : (string) $booksIds[0];
            $stockId = $createsStock
                ? $this->createStockRecordFromFinance($organizationMapping, $decision, $booksId)
                : (string) $stockIds[0];

            $this->assertMappedRecordOwnership($organizationMapping, $decision->entity_type, $stockId, $booksId);
            $existing = IntegrationMasterDataMapping::query()
                ->where('organization_mapping_uuid', $organizationMapping->mapping_uuid)
                ->where('entity_type', $decision->entity_type)
                ->where(function ($query) use ($stockId, $booksId): void {
                    $query->where('solastock_record_id', $stockId)->orWhere('solabooks_record_id', $booksId);
                })->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $same = $existing->count() === 1
                    && (string) $existing->first()->solastock_record_id === $stockId
                    && (string) $existing->first()->solabooks_record_id === $booksId;
                if (! $same) {
                    $this->fail('mapping_decision_conflicts_with_stable_identity');
                }
                continue;
            }

            $mapping = IntegrationMasterDataMapping::query()->create([
                'mapping_uuid' => $this->stableUuid("wizard|{$decision->decision_uuid}"),
                'organization_mapping_uuid' => $organizationMapping->mapping_uuid,
                'central_client_id' => $organizationMapping->central_client_id,
                'central_organization_id' => $organizationMapping->central_organization_id,
                'finance_organization_id' => $organizationMapping->finance_organization_id,
                'solastock_organization_id' => $organizationMapping->solastock_organization_id,
                'entity_type' => $decision->entity_type,
                'solastock_record_id' => $stockId,
                'solabooks_record_id' => $booksId,
                'status' => 'verified',
                'contract_source_version' => self::VERSION,
                'discovery_method' => 'authorized_connection_wizard',
                'last_verified_at' => now(),
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->audit($runUuid, $decision->decision_uuid, 'approved_mapping_materialized', null, [
                'mapping_uuid' => $mapping->mapping_uuid,
                'entity_type' => $decision->entity_type,
                'solastock_record_id' => $stockId,
                'solabooks_record_id' => $booksId,
            ], $actorUserId);
        }
    }

    private function createStockRecordFromFinance(
        IntegrationOrganizationMapping $mapping,
        object $decision,
        string $booksId,
    ): string {
        if ($decision->entity_type !== 'item') {
            $this->fail('create_solastock_record_requires_supported_entity');
        }
        $books = DB::connection('tenant')->table('inventory_items')
            ->where('organization_id', $mapping->finance_organization_id)->where('id', $booksId)->lockForUpdate()->first();
        if (! $books || ! (bool) ($books->is_active ?? true) || $books->deleted_at !== null) {
            $this->fail('finance_source_record_inactive_or_missing');
        }
        if (trim((string) $books->sku) === '') {
            $this->fail('finance_source_sku_required_for_creation');
        }
        if (Item::query()->where('organization_id', $mapping->solastock_organization_id)->where('sku', $books->sku)->exists()) {
            $this->fail('solastock_sku_conflict_requires_review');
        }

        $categoryId = $this->mappedStockIdentity($mapping, 'category', $books->category_id);
        $unitId = $this->mappedStockIdentity($mapping, 'unit', $books->unit_id);
        $item = Item::query()->create([
            'organization_id' => $mapping->solastock_organization_id,
            'sku' => $books->sku,
            'name' => $books->name,
            'item_type' => 'inventory',
            'tracking_type' => $books->tracking_type ?: 'none',
            'costing_method' => $books->valuation_method ?: 'average',
            'category_id' => $categoryId,
            'base_unit_id' => $unitId,
            'purchase_price' => '0.0000',
            'sales_price' => '0.0000',
            'is_active' => true,
        ]);

        return (string) $item->id;
    }

    private function createFinanceCatalogRecordFromStock(
        IntegrationOrganizationMapping $mapping,
        object $decision,
        string $stockId,
    ): string {
        if ($decision->entity_type !== 'item') {
            $this->fail('keep_solastock_authority_requires_supported_entity');
        }
        $item = Item::query()->where('organization_id', $mapping->solastock_organization_id)
            ->where('id', $stockId)->lockForUpdate()->first();
        if (! $item || ! $item->is_active || $item->deleted_at !== null || trim((string) $item->sku) === '') {
            $this->fail('solastock_source_record_inactive_or_missing');
        }
        if (! $this->catalogBridge->sync($item)) {
            $this->fail('finance_catalog_projection_failed');
        }
        $booksIds = DB::connection('tenant')->table('inventory_items')
            ->where('organization_id', $mapping->finance_organization_id)->where('sku', $item->sku)
            ->whereNull('deleted_at')->lockForUpdate()->pluck('id');
        if ($booksIds->count() !== 1) {
            $this->fail('finance_catalog_projection_ambiguous');
        }

        $booksId = (string) $booksIds->first();
        $categoryId = $this->mappedFinanceIdentity($mapping, 'category', $item->category_id);
        $unitId = $this->mappedFinanceIdentity($mapping, 'unit', $item->base_unit_id);
        $accounts = DB::connection('tenant')->table('integration_account_mappings')
            ->where('organization_id', $mapping->solastock_organization_id)
            ->where('integration', 'solabooks')->where('status', 'verified')
            ->whereIn('mapping_type', ['inventory_asset', 'cogs', 'sales_revenue'])
            ->pluck('solabooks_account_id', 'mapping_type');
        if (! $categoryId || ! $unitId || $accounts->count() !== 3) {
            $this->fail('finance_catalog_projection_accounting_incomplete');
        }
        $columns = Schema::connection('tenant')->getColumnListing('inventory_items');
        DB::connection('tenant')->table('inventory_items')->where('organization_id', $mapping->finance_organization_id)
            ->where('id', $booksId)->update(array_intersect_key([
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'valuation_method' => $item->costing_method,
                'inventory_asset_account_id' => (int) $accounts['inventory_asset'],
                'cogs_account_id' => (int) $accounts['cogs'],
                'income_account_id' => (int) $accounts['sales_revenue'],
                'default_sales_account_id' => (int) $accounts['sales_revenue'],
            ], array_flip($columns)));

        return $booksId;
    }

    private function mappedFinanceIdentity(
        IntegrationOrganizationMapping $mapping,
        string $entityType,
        mixed $stockId,
    ): ?int {
        if ($stockId === null) {
            return null;
        }

        $booksId = IntegrationMasterDataMapping::query()
            ->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', $entityType)
            ->where('solastock_record_id', (string) $stockId)
            ->where('status', 'verified')->value('solabooks_record_id');

        return $booksId === null ? null : (int) $booksId;
    }

    private function mappedStockIdentity(IntegrationOrganizationMapping $mapping, string $entityType, mixed $booksId): ?int
    {
        if ($booksId === null) {
            return null;
        }
        $stockId = IntegrationMasterDataMapping::query()
            ->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('entity_type', $entityType)
            ->where('solabooks_record_id', (string) $booksId)->where('status', 'verified')->value('solastock_record_id');
        if ($stockId === null) {
            $this->fail("{$entityType}_mapping_required_for_item_creation");
        }

        return (int) $stockId;
    }

    private function assertMappedRecordOwnership(
        IntegrationOrganizationMapping $mapping,
        string $entityType,
        string $stockId,
        string $booksId,
    ): void {
        $definitions = [
            'item' => ['items', 'inventory_items'],
            'customer' => ['inventory_customers', 'customers'],
            'supplier' => ['inventory_suppliers', 'suppliers'],
            'category' => ['item_categories', 'inventory_categories'],
            'unit' => ['units', 'inventory_units'],
            'warehouse' => ['warehouses', 'inventory_locations'],
        ];
        $tables = $definitions[$entityType] ?? null;
        if (! $tables
            || ! DB::connection('tenant')->table($tables[0])->where('organization_id', $mapping->solastock_organization_id)->where('id', $stockId)->exists()
            || ! DB::connection('tenant')->table($tables[1])->where('organization_id', $mapping->finance_organization_id)->where('id', $booksId)->exists()) {
            $this->fail('mapping_record_scope_mismatch');
        }
    }

    private function stableUuid(string $identity): string
    {
        $hex = hash('sha256', $identity);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    public function pause(int $organizationId, string $runUuid, string $activationApprovalId, int $actorUserId): array
    {
        if (! $this->activationGateReady($organizationId)
            || ! hash_equals((string) config('integration_connection_wizard.activation_approval_id'), $activationApprovalId)) {
            $this->fail('organization_scoped_activation_gate_closed');
        }
        $mapping = $this->mapping($organizationId);
        DB::connection('tenant')->transaction(function () use ($mapping, $organizationId, $runUuid, $actorUserId): void {
            $run = $this->run($mapping, $runUuid, true);
            if ($run->state === 'paused') {
                return;
            }
            if ($run->state !== 'connected') {
                $this->fail('connected_run_required');
            }
            $setting = IntegrationSetting::query()->where('organization_id', $organizationId)
                ->where('integration', 'solabooks')->lockForUpdate()->firstOrFail();
            $meta = (array) $setting->meta;
            $meta['transport_enabled'] = false;
            $setting->update(['mode' => 'paused', 'meta' => $meta, 'updated_at' => now()]);
            $mapping->update(['activation_state' => 'maintenance_hold']);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)->update([
                'state' => 'paused', 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'organization_connection_paused', null, ['organization_id' => $organizationId], $actorUserId);
        });

        return $this->show($organizationId, $runUuid);
    }

    private function activationGateReady(int $organizationId): bool
    {
        if (! (bool) config('integration_connection_wizard.activation_enabled')
            || ! in_array($organizationId, config('integration_connection_wizard.activation_organization_allowlist', []), true)
            || (string) config('integration_connection_wizard.activation_approval_id') === '') {
            return false;
        }
        if (app()->environment('production') && ! (bool) config('integration_connection_wizard.production_phase6b_enabled')) {
            return false;
        }

        return $this->safety->deliveryEnabledFor($organizationId)
            && (bool) config('integration_connection_wizard.receiver_confirmed_enabled')
            && ! (bool) config('integration_safety.legacy_journal_contract_enabled')
            && ! (bool) config('integration_safety.historical_repair_enabled')
            && ! (bool) config('integration_safety.pending_event_replay_enabled');
    }

    private function comparisonRow(IntegrationOrganizationMapping $mapping, array $candidate): array
    {
        $row = [
            'fingerprint' => $candidate['fingerprint'],
            'entity_type' => $candidate['entity_type'],
            'classification' => $candidate['classification'],
            'mapping_confidence' => $candidate['classification'] === 'exact_match' ? 'deterministic' : 'review_required',
            'evidence' => $candidate['safe_details'],
            'solastock_record_ids' => array_values(array_map('strval', $candidate['solastock_record_ids'])),
            'solabooks_record_ids' => array_values(array_map('strval', $candidate['solabooks_record_ids'])),
            'solastock' => null,
            'solabooks' => null,
            'quantity_difference' => '0.0000',
            'value_difference' => '0.00',
            'blocking_reason' => $candidate['classification'] === 'exact_match' ? null : $candidate['classification'],
        ];
        if ($candidate['entity_type'] !== 'item') {
            $row['solastock'] = count($candidate['solastock_record_ids']) === 1
                ? $this->genericRecord($candidate['entity_type'], 'solastock', (int) $candidate['solastock_record_ids'][0], $mapping)
                : null;
            $row['solabooks'] = count($candidate['solabooks_record_ids']) === 1
                ? $this->genericRecord($candidate['entity_type'], 'solabooks', (int) $candidate['solabooks_record_ids'][0], $mapping)
                : null;
            return $row;
        }

        $stockId = count($candidate['solastock_record_ids']) === 1 ? (int) $candidate['solastock_record_ids'][0] : null;
        $booksId = count($candidate['solabooks_record_ids']) === 1 ? (int) $candidate['solabooks_record_ids'][0] : null;
        $stock = $stockId ? DB::connection('tenant')->table('items')->where('organization_id', $mapping->solastock_organization_id)->where('id', $stockId)->first() : null;
        $books = $booksId ? DB::connection('tenant')->table('inventory_items')->where('organization_id', $mapping->finance_organization_id)->where('id', $booksId)->first() : null;
        $stockBalance = $stockId ? DB::connection('tenant')->table('stock_balances')->where('organization_id', $mapping->solastock_organization_id)->where('item_id', $stockId)
            ->selectRaw('COALESCE(SUM(on_hand_qty),0) quantity, COALESCE(SUM(total_value),0) value, MAX(average_cost) average_cost')->first() : null;
        $stockQty = Decimal::qty((string) ($stockBalance->quantity ?? '0'));
        $stockValue = Decimal::money((string) ($stockBalance->value ?? '0'));
        $booksQty = Decimal::qty((string) ($books->qty_on_hand ?? '0'));
        $booksCost = (string) ($books->average_cost ?? $books->avg_cost ?? '0');
        $booksValue = Decimal::money(Decimal::mul($booksQty, $booksCost));

        $row['solastock'] = $stock ? [
            'id' => (string) $stock->id,
            'name' => (string) $stock->name,
            'sku' => (string) $stock->sku,
            'barcode' => $this->stockBarcode((int) $stock->id),
            'category_id' => $stock->category_id ? (string) $stock->category_id : null,
            'unit_id' => $stock->base_unit_id ? (string) $stock->base_unit_id : null,
            'quantity' => $stockQty,
            'inventory_value' => $stockValue,
            'valuation_method' => $stock->costing_method ?: 'organization_default',
            'tracking_type' => $stock->tracking_type,
            'account_override' => [
                'inventory_asset' => $stock->inventory_account_ref,
                'cogs' => $stock->cogs_account_ref,
                'revenue' => $stock->income_account_ref,
            ],
            'warehouses' => $this->stockWarehouses((int) $stock->id, (int) $mapping->solastock_organization_id),
            'archived' => $stock->deleted_at !== null,
        ] : null;
        $row['solabooks'] = $books ? [
            'id' => (string) $books->id,
            'name' => (string) $books->name,
            'sku' => $books->sku,
            'barcode' => $books->barcode,
            'category_id' => $books->category_id ? (string) $books->category_id : null,
            'unit_id' => $books->unit_id ? (string) $books->unit_id : null,
            'quantity' => $booksQty,
            'inventory_value' => $booksValue,
            'valuation_method' => $books->valuation_method,
            'tracking_type' => $books->tracking_type,
            'account_override' => [
                'inventory_asset' => $books->inventory_asset_account_id ? (string) $books->inventory_asset_account_id : null,
                'cogs' => $books->cogs_account_id ? (string) $books->cogs_account_id : null,
                'revenue' => $books->income_account_id ? (string) $books->income_account_id : null,
            ],
            'archived' => $books->deleted_at !== null,
        ] : null;
        $row['quantity_difference'] = Decimal::qty(Decimal::sub($stockQty, $booksQty));
        $row['value_difference'] = Decimal::money(Decimal::sub($stockValue, $booksValue));
        if ($row['blocking_reason'] === null
            && (! Decimal::isZero($row['quantity_difference'], Decimal::QTY_SCALE)
                || ! Decimal::isZero($row['value_difference'], Decimal::MONEY_SCALE))) {
            $row['blocking_reason'] = 'separate_inventory_difference';
        }

        return $row;
    }

    private function totals(array $comparison): array
    {
        $totals = [
            'exact_matches' => 0, 'review_required' => 0, 'missing_in_solastock' => 0,
            'missing_in_solabooks' => 0, 'ambiguous' => 0, 'blocked' => 0,
            'total_quantity_difference' => '0.0000', 'total_valuation_difference' => '0.00',
        ];
        foreach ($comparison as $row) {
            match ($row['classification']) {
                'exact_match' => $totals['exact_matches']++,
                'missing_solastock_record' => $totals['missing_in_solastock']++,
                'missing_finance_record' => $totals['missing_in_solabooks']++,
                'ambiguous_match', 'conflicting_candidates', 'conflicting_mapping' => $totals['ambiguous']++,
                default => $totals['review_required']++,
            };
            if ($row['blocking_reason']) {
                $totals['blocked']++;
            }
            $totals['total_quantity_difference'] = Decimal::qty(Decimal::add($totals['total_quantity_difference'], $row['quantity_difference']));
            $totals['total_valuation_difference'] = Decimal::money(Decimal::add($totals['total_valuation_difference'], $row['value_difference']));
        }
        $difference = $totals['total_valuation_difference'];
        $totals['proposed_accounting_effect'] = Decimal::cmp($difference, '0') === 0 ? [] : [
            'requires_separate_accounting_approval' => true,
            'debit' => Decimal::cmp($difference, '0') > 0 ? 'inventory_asset' : 'opening_offset',
            'credit' => Decimal::cmp($difference, '0') > 0 ? 'opening_offset' : 'inventory_asset',
            'amount' => ltrim(Decimal::money(Decimal::cmp($difference, '0') < 0 ? substr($difference, 1) : $difference), '-'),
            'currency' => 'organization_base_currency',
            'automatic_posting' => false,
        ];

        return $totals;
    }

    private function accountingSetup(IntegrationOrganizationMapping $mapping): array
    {
        $required = [
            'inventory_asset', 'cogs', 'grni', 'opening_offset', 'adjustment_gain',
            'adjustment_loss', 'landed_cost_clearing', 'transfer_clearing',
            'accounts_receivable', 'accounts_payable', 'input_tax', 'output_tax', 'rounding',
            'sales_revenue',
        ];
        $rows = DB::connection('tenant')->table('integration_account_mappings')
            ->where('organization_id', $mapping->solastock_organization_id)->where('integration', 'solabooks')
            ->whereIn('mapping_type', $required)->get()->keyBy('mapping_type');
        $accounts = collect($required)->map(function (string $role) use ($rows, $mapping): array {
            $row = $rows->get($role);
            $account = $row?->solabooks_account_id
                ? DB::connection('tenant')->table('accounts')->where('organization_id', $mapping->finance_organization_id)->where('id', $row->solabooks_account_id)->first()
                : null;
            $valid = $row && in_array($row->status, ['mapped', 'verified'], true) && $account
                && (bool) ($account->is_active ?? true) && (bool) ($account->is_postable ?? true);
            return [
                'role' => $role,
                'source' => 'organization_or_category_default',
                'account_id' => $account ? (string) $account->id : null,
                'account_code' => $account->code ?? null,
                'account_name' => $account->name ?? null,
                'valid' => (bool) $valid,
                'blocking_reason' => $valid ? null : 'required_account_mapping_missing_or_invalid',
            ];
        })->all();
        $base = strtoupper((string) $mapping->base_currency_code);
        $currencyValid = preg_match('/^[A-Z]{3}$/', $base) === 1 && $mapping->currency_verified_at !== null;

        return [
            'base_currency' => $base,
            'currency_verified' => $currencyValid,
            'accounts' => $accounts,
            'complete' => $currencyValid && collect($accounts)->every(fn (array $row) => $row['valid']),
            'inheritance_policy' => 'item_override_then_category_then_organization',
        ];
    }

    private function state(array $comparison, array $accounting, array $masterData): string
    {
        if (! $accounting['complete'] || ! $masterData['complete']) {
            return 'setup_required';
        }
        return collect($comparison)->every(fn (array $row) => $row['blocking_reason'] === null)
            ? 'ready_for_approval' : 'review_required';
    }

    /** @return array<string,mixed> */
    private function masterDataSetup(IntegrationOrganizationMapping $mapping): array
    {
        $org = (int) $mapping->solastock_organization_id;
        $activeCount = function (string $table) use ($org): int {
            if (! Schema::connection('tenant')->hasTable($table)) {
                return 0;
            }
            $query = DB::connection('tenant')->table($table)->where('organization_id', $org);
            if (Schema::connection('tenant')->hasColumn($table, 'is_active')) {
                $query->where('is_active', true);
            }
            if (Schema::connection('tenant')->hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            return (int) $query->count();
        };
        $settings = Schema::connection('tenant')->hasTable('inventory_settings')
            ? DB::connection('tenant')->table('inventory_settings')->where('organization_id', $org)->first() : null;
        $inventoryItems = Item::query()->where('organization_id', $org)->where('item_type', 'inventory')->get();
        $missingUnit = $inventoryItems->whereNull('base_unit_id')->count();
        $missingCategory = $inventoryItems->whereNull('category_id')->count();
        $invalidTracking = $inventoryItems->reject(fn (Item $item) => in_array(
            (string) $item->tracking_type, ['none', 'lot', 'serial', 'lot_serial'], true
        ))->count();
        $valuation = (string) ($settings->default_costing_method ?? '');
        $units = $activeCount('units');
        $categories = $activeCount('item_categories');
        $warehouses = $activeCount('warehouses');
        $blockers = array_values(array_filter([
            $units > 0 ? null : 'owner_unit_setup_required',
            $categories > 0 ? null : 'owner_category_setup_required',
            $warehouses > 0 ? null : 'owner_warehouse_setup_required',
            in_array($valuation, ['fifo', 'average'], true) ? null : 'owner_valuation_method_required',
            $missingUnit === 0 ? null : 'inventory_items_missing_authoritative_unit',
            $missingCategory === 0 ? null : 'inventory_items_missing_category',
            $invalidTracking === 0 ? null : 'invalid_tracking_policy',
        ]));

        return [
            'complete' => $blockers === [],
            'units' => $units,
            'categories' => $categories,
            'warehouses' => $warehouses,
            'valuation_method' => $valuation ?: null,
            'inventory_items' => $inventoryItems->count(),
            'items_missing_unit' => $missingUnit,
            'items_missing_category' => $missingCategory,
            'item_identity_policy' => 'stable_mapping_uuid_sku_barcode_discovery_only',
            'tracking_policy_valid' => $invalidTracking === 0,
            'blockers' => $blockers,
            'decision_class' => $blockers === [] ? 'safe_deterministic_preparation' : 'owner_decision',
        ];
    }

    private function mapping(int $organizationId): IntegrationOrganizationMapping
    {
        $mapping = $this->mappingOrNull($organizationId);
        if (! $mapping) {
            $this->fail('verified_immutable_mapping_required');
        }
        return $mapping;
    }

    private function mappingOrNull(int $organizationId): ?IntegrationOrganizationMapping
    {
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $organizationId)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', 'solastock-journal.v2')
            ->whereIn('status', ['verified_hold', 'verified'])
            ->first();
        if (! $mapping || (int) $mapping->central_organization_id !== $organizationId) {
            return null;
        }
        return $mapping;
    }

    /**
     * Read-only upgrade readiness for dual-subscribed organizations which do
     * not yet have an immutable v2 mapping. Candidate discovery is informative
     * only: SKU is never persisted as identity and ambiguous rows stay blocked.
     */
    private function preMappingReadiness(int $organizationId): array
    {
        $tenant = (string) DB::connection('tenant')->getDatabaseName();
        $financeOrg = Schema::connection('tenant')->hasTable('organizations')
            ? DB::connection('tenant')->table('organizations')->where('central_org_id', $organizationId)->first()
            : null;
        $financeOrgId = (int) ($financeOrg->id ?? 0);
        $stockItems = Schema::connection('tenant')->hasTable('items')
            ? DB::connection('tenant')->table('items')->where('organization_id', $organizationId)->whereNull('deleted_at')->get()
            : collect();
        $financeItems = $financeOrgId > 0 && Schema::connection('tenant')->hasTable('inventory_items')
            ? DB::connection('tenant')->table('inventory_items')->where('organization_id', $financeOrgId)->get()
            : collect();

        $comparison = [];
        $seenFinance = [];
        foreach ($stockItems as $stock) {
            $matches = $financeItems->filter(fn ($item) => strtoupper(trim((string) ($item->sku ?? ''))) !== ''
                && strtoupper(trim((string) ($item->sku ?? ''))) === strtoupper(trim((string) ($stock->sku ?? ''))));
            foreach ($matches as $match) {
                $seenFinance[(int) $match->id] = true;
            }
            $classification = $matches->count() === 1 ? 'exact_candidate_requires_owner_review'
                : ($matches->count() > 1 ? 'ambiguous' : 'missing_solabooks_record');
            $comparison[] = $this->preMappingItemRow($stock, $matches->values(), $classification);
        }
        foreach ($financeItems as $finance) {
            if (! isset($seenFinance[(int) $finance->id])) {
                $comparison[] = $this->preMappingItemRow(null, collect([$finance]), 'missing_solastock_record');
            }
        }

        $counts = fn (string $table, int $org, string $column = 'organization_id'): int =>
            Schema::connection('tenant')->hasTable($table)
                ? (int) DB::connection('tenant')->table($table)->where($column, $org)->count()
                : 0;
        $blockers = array_values(array_filter([
            'verified_immutable_mapping_required',
            $financeOrgId > 0 ? null : 'finance_organization_identity_missing',
            $stockItems->isEmpty() ? 'owner_master_data_setup_required' : null,
            collect($comparison)->contains(fn (array $row) => $row['blocking_reason'] !== null)
                ? 'item_mapping_review_required' : null,
            'accountant_account_role_approval_required',
            'frozen_cutoff_and_zero_variance_required',
        ]));
        $core = [
            'version' => self::VERSION,
            'organization_mapping_uuid' => null,
            'identity' => [
                'central_organization_id' => $organizationId,
                'tenant_database_identity' => $tenant,
                'finance_organization_id' => $financeOrgId ?: null,
                'solastock_organization_id' => $organizationId,
                'integration_mapping_uuid' => null,
                'contract_version' => 'solastock-journal.v2',
            ],
            'comparison' => $comparison,
            'totals' => [
                'exact_candidates' => collect($comparison)->where('classification', 'exact_candidate_requires_owner_review')->count(),
                'exact_matches' => 0,
                'review_required' => collect($comparison)->whereNotNull('blocking_reason')->count(),
                'missing_solastock' => collect($comparison)->where('classification', 'missing_solastock_record')->count(),
                'missing_in_solastock' => collect($comparison)->where('classification', 'missing_solastock_record')->count(),
                'missing_solabooks' => collect($comparison)->where('classification', 'missing_solabooks_record')->count(),
                'missing_in_solabooks' => collect($comparison)->where('classification', 'missing_solabooks_record')->count(),
                'ambiguous' => collect($comparison)->where('classification', 'ambiguous')->count(),
                'blocked' => collect($comparison)->whereNotNull('blocking_reason')->count(),
                'total_quantity_difference' => Decimal::qty(collect($comparison)->reduce(
                    fn (string $carry, array $row) => Decimal::add($carry, $row['quantity_difference'], 4), '0'
                )),
                'total_valuation_difference' => Decimal::money(collect($comparison)->reduce(
                    fn (string $carry, array $row) => Decimal::add($carry, $row['value_difference'], 2), '0'
                )),
            ],
            'readiness' => [
                'units' => $counts('units', $organizationId),
                'unit_conversions' => $counts('unit_conversions', $organizationId),
                'categories' => $counts('item_categories', $organizationId),
                'warehouses' => $counts('warehouses', $organizationId),
                'customers' => $counts('inventory_customers', $organizationId),
                'suppliers' => $counts('inventory_suppliers', $organizationId),
                'tax_mappings' => $counts('integration_tax_mappings', $organizationId),
                'account_mappings' => $counts('integration_account_mappings', $organizationId),
            ],
            'decision_classes' => [
                'safe_deterministic_preparation', 'owner_decision', 'accountant_decision',
                'subscription_owner_decision', 'physical_count_requirement',
                'historical_review_requirement', 'permanently_blocked_excluded',
            ],
            'blockers' => $blockers,
        ];

        return $core + [
            'read_only' => true,
            'generated_at' => now()->utc()->toIso8601String(),
            'snapshot_hash' => $this->hash($core),
            'connection_state' => 'setup_required',
            'activation_available' => false,
            'legacy_fallback' => false,
        ];
    }

    private function preMappingItemRow(?object $stock, Collection $financeMatches, string $classification): array
    {
        $finance = $financeMatches->count() === 1 ? $financeMatches->first() : null;
        $stockQty = (string) ($stock && Schema::connection('tenant')->hasTable('stock_balances')
            ? DB::connection('tenant')->table('stock_balances')->where('organization_id', $stock->organization_id)
                ->where('item_id', $stock->id)->sum('on_hand_qty') : '0');
        $stockValue = (string) ($stock && Schema::connection('tenant')->hasTable('stock_balances')
            ? DB::connection('tenant')->table('stock_balances')->where('organization_id', $stock->organization_id)
                ->where('item_id', $stock->id)->sum('total_value') : '0');
        $financeQty = (string) ($finance->qty_on_hand ?? '0');
        $financeValue = $finance
            ? Decimal::money(Decimal::mul($financeQty, (string) ($finance->average_cost ?? '0'), 6))
            : '0.00';
        $blocking = match ($classification) {
            'exact_candidate_requires_owner_review' => 'candidate_identity_requires_owner_confirmation',
            'ambiguous' => 'ambiguous_identity_requires_owner_review',
            'missing_solastock_record' => 'owner_must_create_or_exclude_solastock_record',
            default => 'missing_finance_record_requires_owner_review',
        };

        return [
            'fingerprint' => $this->hash([
                'stock_id' => $stock?->id,
                'finance_ids' => $financeMatches->pluck('id')->map('strval')->sort()->values()->all(),
                'classification' => $classification,
            ]),
            'entity_type' => 'item',
            'classification' => $classification,
            'sku' => (string) ($stock->sku ?? $finance->sku ?? ''),
            'solastock_record_ids' => $stock ? [(string) $stock->id] : [],
            'solabooks_record_ids' => $financeMatches->pluck('id')->map('strval')->values()->all(),
            'solastock' => $stock ? [
                'id' => (string) $stock->id,
                'name' => (string) ($stock->name ?? ''),
                'sku' => (string) ($stock->sku ?? ''),
                'quantity' => Decimal::qty($stockQty),
                'inventory_value' => Decimal::money($stockValue),
            ] : null,
            'solabooks' => $finance ? [
                'id' => (string) $finance->id,
                'name' => (string) ($finance->name ?? ''),
                'sku' => (string) ($finance->sku ?? ''),
                'quantity' => Decimal::qty($financeQty),
                'inventory_value' => $financeValue,
            ] : null,
            'solastock_quantity' => Decimal::qty($stockQty),
            'solabooks_quantity' => Decimal::qty($financeQty),
            'solastock_value' => Decimal::money($stockValue),
            'solabooks_value' => $financeValue,
            'quantity_difference' => Decimal::qty(Decimal::sub($stockQty, $financeQty, 4)),
            'value_difference' => Decimal::money(Decimal::sub($stockValue, $financeValue, 2)),
            'blocking_reason' => $blocking,
            'mapping_confidence' => $classification === 'exact_candidate_requires_owner_review' ? 'review_required' : 'review_required',
            'decision_class' => str_contains($blocking, 'owner') ? 'owner_decision' : 'historical_review_requirement',
        ];
    }

    private function run(IntegrationOrganizationMapping $mapping, string $runUuid, bool $lock = false): object
    {
        $query = DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('run_uuid', $runUuid)->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('central_client_id', $mapping->central_client_id)
            ->where('central_organization_id', $mapping->central_organization_id)
            ->where('finance_organization_id', $mapping->finance_organization_id)
            ->where('solastock_organization_id', $mapping->solastock_organization_id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $run = $query->first();
        if (! $run) {
            $this->fail('wizard_run_scope_mismatch');
        }
        return $run;
    }

    private function identity(IntegrationOrganizationMapping $mapping): array
    {
        return [
            'central_client_id' => (int) $mapping->central_client_id,
            'central_organization_id' => (int) $mapping->central_organization_id,
            'tenant_database_identity' => (string) $mapping->tenant_database_identity,
            'finance_organization_id' => (int) $mapping->finance_organization_id,
            'solastock_organization_id' => (int) $mapping->solastock_organization_id,
            'integration_mapping_uuid' => (string) $mapping->mapping_uuid,
            'contract_version' => (string) $mapping->contract_version,
            'base_currency' => (string) $mapping->base_currency_code,
        ];
    }

    private function stockBarcode(int $itemId): ?string
    {
        if (! Schema::connection('tenant')->hasTable('item_barcodes')) {
            return null;
        }
        $query = DB::connection('tenant')->table('item_barcodes')->where('item_id', $itemId);
        if (Schema::connection('tenant')->hasColumn('item_barcodes', 'is_primary')) {
            $query->orderByDesc('is_primary');
        }

        return $query->orderBy('id')->value('barcode');
    }

    private function stockWarehouses(int $itemId, int $organizationId): array
    {
        return DB::connection('tenant')->table('stock_balances as b')
            ->leftJoin('warehouses as w', function ($join): void {
                $join->on('w.id', '=', 'b.warehouse_id')->on('w.organization_id', '=', 'b.organization_id');
            })
            ->where('b.organization_id', $organizationId)->where('b.item_id', $itemId)
            ->groupBy('b.warehouse_id', 'w.name')->orderBy('b.warehouse_id')
            ->get(['b.warehouse_id', 'w.name', DB::raw('SUM(b.on_hand_qty) quantity'), DB::raw('SUM(b.total_value) value')])
            ->map(fn ($row) => [
                'id' => (string) $row->warehouse_id,
                'name' => $row->name,
                'quantity' => Decimal::qty((string) $row->quantity),
                'value' => Decimal::money((string) $row->value),
            ])->all();
    }

    private function genericRecord(string $entityType, string $application, int $id, IntegrationOrganizationMapping $mapping): ?array
    {
        $definitions = [
            'customer' => ['solastock' => ['inventory_customers', 'name', 'code'], 'solabooks' => ['customers', 'name', 'customer_number']],
            'supplier' => ['solastock' => ['inventory_suppliers', 'name', 'code'], 'solabooks' => ['suppliers', 'name', 'supplier_number']],
            'category' => ['solastock' => ['item_categories', 'name', null], 'solabooks' => ['inventory_categories', 'name', null]],
            'unit' => ['solastock' => ['units', 'name', 'symbol'], 'solabooks' => ['inventory_units', 'name', 'symbol']],
            'warehouse' => ['solastock' => ['warehouses', 'name', null], 'solabooks' => ['inventory_locations', 'location_name', null]],
            'account_role' => ['solastock' => ['integration_account_mappings', 'mapping_type', null], 'solabooks' => ['accounts', 'name', 'code']],
            'tax' => ['solastock' => ['integration_tax_mappings', 'tax_code', 'treatment'], 'solabooks' => ['taxes', 'name', 'code']],
            'unit_conversion' => ['solastock' => ['unit_conversions', 'id', 'factor']],
        ];
        $definition = $definitions[$entityType][$application] ?? null;
        if (! $definition || ! Schema::connection('tenant')->hasTable($definition[0])) {
            return null;
        }
        [$table, $nameColumn, $codeColumn] = $definition;
        $query = DB::connection('tenant')->table($table)->where('id', $id);
        if (Schema::connection('tenant')->hasColumn($table, 'organization_id')) {
            $query->where('organization_id', $application === 'solastock' ? $mapping->solastock_organization_id : $mapping->finance_organization_id);
        }
        $record = $query->first();
        if (! $record) {
            return null;
        }
        return [
            'id' => (string) $id,
            'name' => (string) ($record->{$nameColumn} ?? ''),
            'code' => $codeColumn ? ($record->{$codeColumn} ?? null) : null,
            'archived' => isset($record->deleted_at) || (isset($record->is_active) && ! (bool) $record->is_active),
        ];
    }

    private function safeDecisionDetails(array $details): array
    {
        return collect($details)->only([
            'reason', 'selected_record_id', 'physical_count_reference', 'accounting_approval_required', 'note',
        ])->map(fn ($value) => is_string($value) ? mb_substr($value, 0, 500) : $value)->all();
    }

    private function audit(string $runUuid, ?string $decisionUuid, string $action, mixed $before, mixed $after, int $actor): void
    {
        DB::connection('tenant')->table('integration_connection_wizard_audits')->insert([
            'run_uuid' => $runUuid,
            'decision_uuid' => $decisionUuid,
            'action' => $action,
            'before_hash' => $before === null ? null : $this->hash($before),
            'after_hash' => $this->hash($after),
            'safe_metadata' => json_encode($after),
            'actor_user_id' => $actor,
            'created_at' => now(),
        ]);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (is_object($item)) {
                $item = (array) $item;
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['connection_wizard' => $code]);
    }
}
