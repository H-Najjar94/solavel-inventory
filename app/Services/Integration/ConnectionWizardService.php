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
    public const VERSION = 'solabooks-solastock.connection-wizard.v2';

    public const DECISIONS = [
        'bind_existing',
        'create_solastock_record',
        'keep_solastock_authority',
        'physical_count_required',
        'retain_blocked',
        'exclude_initial_connection',
        'select_authoritative_record',
        'resolve_account_category',
        'approve_exact_binding',
        'reject_exact_binding',
        'classify_inventory_item',
        'classify_service_non_inventory',
        'select_unit',
        'propose_unit_creation',
        'define_unit_conversion',
        'select_category',
        'propose_category_creation',
        'select_warehouse',
        'select_party',
        'select_tax',
        'retain_currency',
        'exclude_currency',
        'retain_historical_exclusion',
        'review_cutoff_document',
        'select_account_role',
        'retain_account_role_unresolved',
    ];

    public const BULK_ACTIONS = [
        'approve_exact_sku_candidates',
        'retain_historical_exclusions',
        'exclude_service_non_inventory_records',
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
            'guided_setup' => $this->guidedSetup($comparison, (string) ($accounting['base_currency'] ?? ''), (int) $mapping->finance_organization_id),
        ];

        return $core + [
            'read_only' => true,
            'generated_at' => now()->utc()->toIso8601String(),
            'snapshot_hash' => $this->hash($core),
            'connection_state' => $this->state($comparison, $accounting, $masterData),
            'active_draft' => $this->activeDraftSummary($organizationId),
            'activation_available' => false,
        ];
    }

    public function start(int $organizationId, int $actorUserId): array
    {
        $preview = $this->discover($organizationId);
        $identity = $preview['identity'];
        if ((int) ($identity['central_client_id'] ?? 0) <= 0
            || (int) ($identity['finance_organization_id'] ?? 0) <= 0
            || (int) ($identity['central_organization_id'] ?? 0) !== $organizationId) {
            $this->fail('authoritative_setup_identity_required');
        }

        $existing = DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('solastock_organization_id', $organizationId)
            ->whereIn('state', ['draft_decisions', 'decisions_complete', 'snapshot_required', 'cutoff_review', 'preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'])
            ->whereNull('discarded_at')->latest('id')->first();
        if ($existing) {
            return $this->finalPreview($organizationId, (string) $existing->run_uuid);
        }

        $createdAt = now()->utc();
        $runUuid = (string) Str::uuid();
        $draftId = 'DRAFT-'.$organizationId.'-'.$createdAt->format('Ymd\THis\Z').'-'.strtoupper(substr($runUuid, 0, 8));
        $manifestHash = (string) ($preview['discovery_manifest_hash'] ?? $this->hash($preview['comparison'] ?? []));
        $beforeImageHash = (string) ($preview['discovery_before_image_hash'] ?? $preview['snapshot_hash']);

        DB::connection('tenant')->transaction(function () use ($preview, $identity, $createdAt, $runUuid, $draftId, $manifestHash, $beforeImageHash, $actorUserId): void {
            DB::connection('tenant')->table('integration_connection_wizard_runs')->insert([
                'run_uuid' => $runUuid,
                'organization_mapping_uuid' => $preview['organization_mapping_uuid'] ?: null,
                'central_client_id' => $identity['central_client_id'],
                'central_organization_id' => $identity['central_organization_id'],
                'tenant_database_identity' => (string) $identity['tenant_database_identity'],
                'finance_organization_id' => $identity['finance_organization_id'],
                'solastock_organization_id' => $identity['solastock_organization_id'],
                'state' => 'draft_decisions',
                'draft_version' => 1,
                'lock_version' => 1,
                'cutoff_at' => null,
                'snapshot_id' => $draftId,
                'snapshot_hash' => $preview['snapshot_hash'],
                'discovery_manifest_hash' => $manifestHash,
                'discovery_before_image_hash' => $beforeImageHash,
                'authority_choices' => json_encode(['inventory' => 'solastock', 'accounting' => 'solabooks']),
                'workflow_allowlist' => json_encode(config('integration_connection_wizard.allowed_workflows', [])),
                'comparison_totals' => json_encode($preview['totals']),
                'snapshot_payload' => $this->canonicalJson([
                    'comparison' => $preview['comparison'],
                    'totals' => $preview['totals'],
                    'accounting' => $preview['accounting'],
                    'master_data' => $preview['master_data'] ?? $preview['readiness'] ?? null,
                ]),
                'created_by_user_id' => $actorUserId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->audit($runUuid, null, 'draft_started', null, [
                'draft_id' => $draftId,
                'draft_discovery_hash' => $preview['snapshot_hash'],
                'state' => 'draft_decisions',
            ], $actorUserId);
        });

        return $this->finalPreview($organizationId, $runUuid);
    }

    public function show(int $organizationId, string $runUuid): array
    {
        return $this->finalPreview($organizationId, $runUuid);
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
        int $expectedLockVersion = 0,
        string $expectedBeforeHash = '',
        bool $canOwnerReview = true,
        bool $canAccountingReview = false,
    ): array {
        if (! in_array($action, self::DECISIONS, true)) {
            $this->fail('unsupported_mapping_decision');
        }
        $persistenceResult = 'saved';
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $fingerprint, $action, $stockIds, $booksIds, $safeDetails, $actorUserId, $expectedLockVersion, $expectedBeforeHash, $canOwnerReview, $canAccountingReview, &$persistenceResult): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if (! in_array($run->state, ['draft_decisions', 'decisions_complete', 'snapshot_required'], true) || $run->invalidated_at || $run->discarded_at) {
                $this->fail('wizard_run_not_editable');
            }
            if ($expectedLockVersion > 0 && (int) $run->lock_version !== $expectedLockVersion) {
                $this->fail('draft_optimistic_lock_conflict');
            }
            $preview = $this->discover($organizationId);
            $candidate = collect($preview['comparison'])->firstWhere('fingerprint', $fingerprint);
            if (! $candidate) {
                $this->fail('candidate_before_image_changed');
            }
            $beforeHash = $this->hash($candidate);
            if ($expectedBeforeHash !== '' && ! hash_equals($beforeHash, $expectedBeforeHash)) {
                $this->fail('candidate_before_image_changed');
            }
            $reviewerRole = $candidate['decision_class'] === 'accountant_decision' ? 'accountant' : 'owner';
            if (! in_array($action, $this->allowedActionsForCandidate($candidate), true)) {
                $this->fail('wizard_decision_not_valid_for_candidate');
            }
            $availableStockUnits = collect($candidate['safe_details']['available_stock_units'] ?? [])
                ->keyBy(fn (array $unit) => (string) ($unit['id'] ?? ''));
            $candidateStockIds = array_values(array_map('strval', $candidate['solastock_record_ids'] ?? []));
            $conversionTargetIds = $availableStockUnits->keys()->filter()->map('strval')->all();
            $selectedRecordId = (string) ($safeDetails['selected_record_id'] ?? '');
            if (in_array($action, ['select_unit', 'select_category'], true)
                && ($selectedRecordId === '' || ! in_array($selectedRecordId, $candidateStockIds, true))) {
                $this->fail('wizard_selected_record_required');
            }
            if ($action === 'define_unit_conversion'
                && ($selectedRecordId === '' || ! in_array($selectedRecordId, $conversionTargetIds, true))) {
                $this->fail('wizard_selected_record_required');
            }
            if ($action === 'define_unit_conversion') {
                $factor = trim((string) ($safeDetails['conversion_factor'] ?? ''));
                if ($candidate['entity_type'] !== 'unit'
                    || empty($candidate['solabooks']['id'])
                    || ! $availableStockUnits->has($selectedRecordId)
                    || ! preg_match('/^(?:0*[1-9]\d{0,11})(?:\.\d{1,6})?$|^0\.\d{0,5}[1-9]$/', $factor)) {
                    throw ValidationException::withMessages([
                        'safe_details.conversion_factor' => ['wizard_unit_conversion_invalid'],
                    ]);
                }
            }
            if (in_array($action, ['propose_unit_creation', 'propose_category_creation'], true)
                && (empty($candidate['solabooks']['name']) || ! empty($candidateStockIds))) {
                $this->fail('wizard_creation_proposal_not_valid');
            }
            if (($reviewerRole === 'accountant' && ! $canAccountingReview)
                || ($reviewerRole === 'owner' && ! $canOwnerReview)) {
                $this->fail('wizard_decision_role_forbidden');
            }
            $current = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('candidate_fingerprint', $fingerprint)->lockForUpdate()->first();
            $normalizedStockIds = array_values(array_map('strval', $stockIds));
            $normalizedBooksIds = array_values(array_map('strval', $booksIds));
            $normalizedSafeDetails = $this->safeDecisionDetails($safeDetails, $action, $candidate, $availableStockUnits->all());
            if ($current && $current->status === 'selected'
                && hash_equals((string) $current->candidate_before_hash, $beforeHash)
                && (string) $current->action === $action
                && json_decode($current->solastock_record_ids ?: '[]', true) === $normalizedStockIds
                && json_decode($current->solabooks_record_ids ?: '[]', true) === $normalizedBooksIds
                && json_decode($current->safe_details ?: '{}', true) === $normalizedSafeDetails) {
                $persistenceResult = 'already_saved';
                return;
            }
            $decisionUuid = $current?->decision_uuid ?? (string) Str::uuid();
            $before = $current ? (array) $current : null;
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->updateOrInsert(
                ['run_uuid' => $runUuid, 'candidate_fingerprint' => $fingerprint],
                [
                    'decision_uuid' => $decisionUuid,
                    'entity_type' => $candidate['entity_type'],
                    'reviewer_role' => $reviewerRole,
                    'decision_version' => (int) ($current?->decision_version ?? 0) + 1,
                    'action' => $action,
                    'solastock_record_ids' => json_encode($normalizedStockIds),
                    'solabooks_record_ids' => json_encode($normalizedBooksIds),
                    'candidate_before_hash' => $beforeHash,
                    'safe_details' => json_encode($normalizedSafeDetails),
                    'status' => 'selected',
                    'actor_user_id' => $actorUserId,
                    'reviewed_by_user_id' => $actorUserId,
                    'reviewed_at' => now(),
                    'created_at' => $current?->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );
            $after = compact('fingerprint', 'action', 'stockIds', 'booksIds', 'beforeHash');
            $this->audit($runUuid, $decisionUuid, $current ? 'decision_revised' : 'decision_selected', $before, $after, $actorUserId);
            $selected = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('status', 'selected')->pluck('candidate_fingerprint')->all();
            $automatic = collect($preview['guided_setup']['automatic_bindings'] ?? [])->pluck('fingerprint')->all();
            $required = collect($preview['comparison'])->whereNotNull('blocking_reason')
                ->reject(fn (array $candidate) => in_array($candidate['fingerprint'], $automatic, true))
                ->pluck('fingerprint')->all();
            $complete = count(array_diff($required, $selected)) === 0;
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)
                ->update([
                    'state' => $complete ? 'decisions_complete' : 'draft_decisions',
                    'lock_version' => DB::raw('lock_version + 1'),
                    'decisions_completed_at' => $complete ? now() : null,
                    'approval_payload_hash' => null, 'approved_by_user_id' => null, 'approved_at' => null,
                    'owner_approved_by_user_id' => null, 'owner_approved_at' => null, 'owner_approval_hash' => null,
                    'accountant_approved_by_user_id' => null, 'accountant_approved_at' => null, 'accountant_approval_hash' => null,
                    'updated_at' => now(),
                ]);
        });

        $result = $this->finalPreview($organizationId, $runUuid);
        $canonical = collect($result['decisions'])->firstWhere('candidate_fingerprint', $fingerprint);
        if (! $canonical || (string) $canonical['action'] !== $action) {
            $this->fail('wizard_save_confirmation_mismatch');
        }
        return $result + [
            'persistence_result' => $persistenceResult,
            'canonical_decision' => $canonical,
        ];
    }

    public function reverseDecision(int $organizationId, string $runUuid, string $decisionUuid, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $decisionUuid, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if (! in_array($run->state, ['draft_decisions', 'decisions_complete', 'snapshot_required'], true)) {
                $this->fail('wizard_run_not_editable');
            }
            $decision = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('decision_uuid', $decisionUuid)->lockForUpdate()->first();
            if (! $decision || $decision->status === 'reversed') {
                $this->fail('decision_not_reversible');
            }
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->where('id', $decision->id)
                ->update(['status' => 'reversed', 'actor_user_id' => $actorUserId, 'updated_at' => now()]);
            $this->audit($runUuid, $decisionUuid, 'decision_reversed', (array) $decision, ['status' => 'reversed'], $actorUserId);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('run_uuid', $runUuid)
                ->update(['state' => 'draft_decisions', 'lock_version' => DB::raw('lock_version + 1'),
                    'decisions_completed_at' => null, 'approval_payload_hash' => null,
                    'approved_by_user_id' => null, 'approved_at' => null, 'updated_at' => now()]);
        });

        return $this->finalPreview($organizationId, $runUuid);
    }

    public function finalPreview(int $organizationId, string $runUuid): array
    {
        $run = $this->runForOrganization($organizationId, $runUuid);
        $preview = $this->discover($organizationId);
        $frozen = $run->snapshot_frozen_at !== null;
        $manifestHash = (string) ($preview['discovery_manifest_hash'] ?? $this->hash($preview['comparison'] ?? []));
        $beforeImageHash = (string) ($preview['discovery_before_image_hash'] ?? $preview['snapshot_hash']);
        if ($frozen && (! hash_equals((string) $run->discovery_manifest_hash, $manifestHash)
            || ! hash_equals((string) $run->discovery_before_image_hash, $beforeImageHash)
            || ! hash_equals((string) $run->snapshot_hash, (string) $preview['snapshot_hash']))) {
            $this->fail('snapshot_or_before_image_changed');
        }

        $decisions = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $runUuid)->where('status', 'selected')->get()->keyBy('candidate_fingerprint');
        $candidatesByFingerprint = collect($preview['comparison'])->keyBy('fingerprint');
        $validDecisions = $decisions->filter(fn ($decision, $fingerprint) => $this->decisionMatchesCandidate(
            $decision, $candidatesByFingerprint->get($fingerprint)
        ));
        $automatic = collect($preview['guided_setup']['automatic_bindings'] ?? [])->pluck('fingerprint');
        $blocking = collect($preview['comparison'])->filter(function (array $candidate) use ($validDecisions, $automatic): bool {
            if ($candidate['blocking_reason'] === null) {
                return false;
            }
            if ($automatic->contains($candidate['fingerprint'])) {
                return false;
            }
            $decision = $validDecisions->get($candidate['fingerprint']);
            return ! $decision || in_array($decision->action, ['physical_count_required', 'retain_blocked'], true);
        })->values();
        $approvalCore = [
            'version' => self::VERSION,
            'run_uuid' => $runUuid,
            'identity' => $preview['identity'],
            'cutoff_at' => $run->cutoff_at ? (string) $run->cutoff_at : null,
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
            'proposed_accounting_effect' => $preview['totals']['proposed_accounting_effect'] ?? [],
            'automatic_configuration_hash' => $preview['guided_setup']['source_invalidation_hash'] ?? null,
            'blocked_fingerprints' => $blocking->pluck('fingerprint')->all(),
        ];
        $approvalHash = $this->hash($approvalCore);
        $ready = $blocking->isEmpty() && $frozen && $run->cutoff_reviewed_at !== null;
        $decisionsList = $decisions->sortKeys()->map(fn ($row) => [
            'decision_uuid' => $row->decision_uuid,
            'candidate_fingerprint' => $row->candidate_fingerprint,
            'entity_type' => $row->entity_type,
            'reviewer_role' => $row->reviewer_role ?? 'owner',
            'decision_version' => (int) ($row->decision_version ?? 1),
            'action' => $row->action,
            'solastock_record_ids' => json_decode($row->solastock_record_ids ?: '[]', true),
            'solabooks_record_ids' => json_decode($row->solabooks_record_ids ?: '[]', true),
            'safe_details' => json_decode($row->safe_details ?: '{}', true),
            'candidate_before_hash' => $row->candidate_before_hash,
            'reviewed_by_user_id' => $row->reviewed_by_user_id ?? $row->actor_user_id,
            'reviewed_at' => $row->reviewed_at ?? $row->updated_at,
            'valid_for_current_candidate' => $validDecisions->has($row->candidate_fingerprint),
        ])->values()->all();
        // `decisions` in the approval core intentionally has a compact hash
        // shape. The API response must replace it with the canonical decision
        // contract below; PHP's array-union operator would silently retain the
        // compact list and omit candidate_fingerprint/decision_version.
        return array_merge($approvalCore, [
            'approval_payload_hash' => $approvalHash,
            'state' => $ready && $run->state === 'cutoff_review' ? 'preview_ready' : (string) $run->state,
            'lock_version' => (int) $run->lock_version,
            'draft_version' => (int) $run->draft_version,
            'decisions' => $decisionsList,
            'comparison' => collect($preview['comparison'])->map(fn (array $row) => $row + ['candidate_before_hash' => $this->hash($row)])->all(),
            'totals' => $preview['totals'],
            'accounting' => $preview['accounting'] ?? ['accounts' => [], 'complete' => false],
            'master_data' => $preview['master_data'] ?? null,
            'readiness' => $preview['readiness'] ?? null,
            'guided_setup' => $preview['guided_setup'] ?? null,
            'blockers' => $preview['blockers'] ?? [],
            'blocking' => $blocking->all(),
            'snapshot_frozen_at' => $run->snapshot_frozen_at,
            'owner_approved_at' => $run->owner_approved_at ?? null,
            'accountant_approved_at' => $run->accountant_approved_at ?? null,
            'activation_available' => false,
            'rollback_behavior' => 'Before activation, reverse decisions and regenerate the snapshot. After activation, pause delivery; operational and accounting reversals remain separate and preserve evidence.',
        ]);
    }

    public function bulkDecide(int $organizationId, string $runUuid, string $bulkAction, array $fingerprints,
        string $confirmation, int $actorUserId, int $expectedLockVersion, bool $canOwnerReview): array
    {
        if (! $canOwnerReview || ! in_array($bulkAction, self::BULK_ACTIONS, true)) {
            $this->fail('wizard_bulk_action_forbidden');
        }
        $fingerprints = array_values(array_unique(array_map('strval', $fingerprints)));
        if ($fingerprints === [] || ! hash_equals('CONFIRM '.count($fingerprints).' RECORDS', $confirmation)) {
            $this->fail('wizard_bulk_confirmation_mismatch');
        }
        $run = $this->runForOrganization($organizationId, $runUuid);
        if ((int) $run->lock_version !== $expectedLockVersion) {
            $this->fail('draft_optimistic_lock_conflict');
        }
        $preview = $this->finalPreview($organizationId, $runUuid);
        $candidates = collect($preview['comparison'])->whereIn('fingerprint', $fingerprints)->values();
        if ($candidates->count() !== count($fingerprints)) $this->fail('wizard_bulk_scope_mismatch');
        [$eligible, $action] = match ($bulkAction) {
            'approve_exact_sku_candidates' => [
                $candidates->every(fn (array $row) => $row['entity_type'] === 'item' && $row['classification'] === 'exact_candidate_requires_owner_review'),
                'approve_exact_binding',
            ],
            'retain_historical_exclusions' => [
                $candidates->every(fn (array $row) => $row['entity_type'] === 'historical_event'),
                'retain_historical_exclusion',
            ],
            'exclude_service_non_inventory_records' => [
                $candidates->every(fn (array $row) => $row['entity_type'] === 'item'
                    && $row['classification'] === 'missing_solastock_record'
                    && ($row['safe_details']['owner_classification'] ?? null) === 'service_non_inventory'),
                'classify_service_non_inventory',
            ],
        };
        if (! $eligible) $this->fail('wizard_bulk_candidates_not_homogeneous');

        return DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $candidates, $action, $actorUserId, $expectedLockVersion): array {
            $lockedRun = $this->runForOrganization($organizationId, $runUuid, true);
            if ((int) $lockedRun->lock_version !== $expectedLockVersion) {
                $this->fail('draft_optimistic_lock_conflict');
            }
            $alreadySelected = DB::connection('tenant')->table('integration_connection_wizard_decisions')
                ->where('run_uuid', $runUuid)->where('status', 'selected')
                ->whereIn('candidate_fingerprint', $candidates->pluck('fingerprint')->all())
                ->where('action', $action)->pluck('candidate_fingerprint')->all();
            $pending = $candidates->reject(fn (array $candidate) => in_array($candidate['fingerprint'], $alreadySelected, true))->values();
            if ($pending->isEmpty()) {
                return $this->finalPreview($organizationId, $runUuid) + ['bulk_result' => 'already_saved'];
            }
            foreach ($pending as $candidate) {
                $current = $this->runForOrganization($organizationId, $runUuid);
                $this->decide($organizationId, $runUuid, $candidate['fingerprint'], $action,
                    $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
                    ['reason' => 'confirmed_homogeneous_bulk_decision'], $actorUserId,
                    (int) $current->lock_version, $candidate['candidate_before_hash'], true, false);
            }
            $this->audit($runUuid, null, 'confirmed_bulk_decision', null, [
                'action' => $action, 'fingerprints' => $pending->pluck('fingerprint')->all(),
            ], $actorUserId);
            return $this->finalPreview($organizationId, $runUuid);
        });
    }

    public function requestSnapshot(int $organizationId, string $runUuid, int $expectedLockVersion, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $expectedLockVersion, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if ($run->state !== 'decisions_complete' || (int) $run->lock_version !== $expectedLockVersion) {
                $this->fail('decisions_complete_lock_required');
            }
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                'state' => 'snapshot_required', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'snapshot_requested', null, ['draft_version' => (int) $run->draft_version], $actorUserId);
        });
        return $this->finalPreview($organizationId, $runUuid);
    }

    public function freezeSnapshot(int $organizationId, string $runUuid, int $expectedLockVersion, int $actorUserId): array
    {
        $preview = $this->discover($organizationId);
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $expectedLockVersion, $actorUserId, $preview): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if ($run->state !== 'snapshot_required' || (int) $run->lock_version !== $expectedLockVersion) {
                $this->fail('snapshot_required_lock_mismatch');
            }
            $frozenAt = now()->utc();
            $snapshotId = 'CONNECTION-'.$organizationId.'-'.$frozenAt->format('Ymd\THis\Z').'-'.strtoupper(substr($runUuid, 0, 8));
            $manifestHash = (string) ($preview['discovery_manifest_hash'] ?? $this->hash($preview['comparison'] ?? []));
            $beforeHash = (string) ($preview['discovery_before_image_hash'] ?? $preview['snapshot_hash']);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                'state' => 'cutoff_review', 'snapshot_id' => $snapshotId, 'snapshot_hash' => $preview['snapshot_hash'],
                'discovery_manifest_hash' => $manifestHash, 'discovery_before_image_hash' => $beforeHash,
                'snapshot_payload' => $this->canonicalJson($preview), 'snapshot_frozen_at' => $frozenAt,
                'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'snapshot_frozen', null, [
                'snapshot_id' => $snapshotId, 'snapshot_hash' => $preview['snapshot_hash'],
            ], $actorUserId);
        });
        return $this->finalPreview($organizationId, $runUuid);
    }

    public function reviewCutoff(int $organizationId, string $runUuid, string $cutoffAt, array $physicalCounts,
        string $unexplainedVariance, int $expectedLockVersion, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $cutoffAt, $physicalCounts, $unexplainedVariance, $expectedLockVersion, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if ($run->state !== 'cutoff_review' || (int) $run->lock_version !== $expectedLockVersion || $run->snapshot_frozen_at === null) {
                $this->fail('frozen_snapshot_cutoff_review_required');
            }
            $variance = Decimal::money($unexplainedVariance);
            $choices = json_decode($run->authority_choices ?: '{}', true);
            $choices['physical_counts'] = collect($physicalCounts)->map(fn ($row) => collect((array) $row)
                ->only(['item_id', 'warehouse_id', 'quantity', 'counted_at', 'reference'])->all())->values()->all();
            $choices['unexplained_variance'] = $variance;
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                'cutoff_at' => $cutoffAt, 'cutoff_reviewed_at' => now(),
                'authority_choices' => json_encode($choices),
                'state' => Decimal::isZero($variance, 2) ? 'preview_ready' : 'cutoff_review',
                'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'cutoff_reviewed', null, [
                'cutoff_at' => $cutoffAt, 'physical_count_rows' => count($physicalCounts),
                'unexplained_variance' => $variance,
            ], $actorUserId);
        });
        return $this->finalPreview($organizationId, $runUuid);
    }

    public function approveRole(int $organizationId, string $runUuid, string $approvalHash, string $reviewerRole,
        int $actorUserId, bool $authorized): array
    {
        if (! $authorized || ! in_array($reviewerRole, ['owner', 'accountant'], true)) $this->fail('wizard_approval_role_forbidden');
        $preview = $this->finalPreview($organizationId, $runUuid);
        if ($preview['state'] !== 'preview_ready' || ! hash_equals($preview['approval_payload_hash'], $approvalHash)) {
            $this->fail('approval_payload_changed');
        }
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $approvalHash, $reviewerRole, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if (! in_array($run->state, ['preview_ready', 'owner_approved', 'accountant_approved'], true)) $this->fail('preview_ready_required');
            $prefix = $reviewerRole === 'owner' ? 'owner' : 'accountant';
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                $prefix.'_approved_by_user_id' => $actorUserId,
                $prefix.'_approved_at' => now(), $prefix.'_approval_hash' => $approvalHash,
                'state' => ($reviewerRole === 'owner' ? $run->accountant_approved_at : $run->owner_approved_at)
                    ? 'activation_ready' : $prefix.'_approved',
                'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, $prefix.'_approved', null, ['approval_payload_hash' => $approvalHash], $actorUserId);
        });
        return $this->finalPreview($organizationId, $runUuid);
    }

    public function resetDraft(int $organizationId, string $runUuid, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if ($run->state === 'connected') $this->fail('connected_run_cannot_be_reset');
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->where('run_uuid', $runUuid)
                ->where('status', 'selected')->update(['status' => 'reversed', 'actor_user_id' => $actorUserId, 'updated_at' => now()]);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                'state' => 'draft_decisions', 'draft_version' => DB::raw('draft_version + 1'),
                'lock_version' => DB::raw('lock_version + 1'), 'cutoff_at' => null,
                'snapshot_frozen_at' => null, 'cutoff_reviewed_at' => null, 'decisions_completed_at' => null,
                'owner_approved_by_user_id' => null, 'owner_approved_at' => null, 'owner_approval_hash' => null,
                'accountant_approved_by_user_id' => null, 'accountant_approved_at' => null, 'accountant_approval_hash' => null,
                'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'draft_reset', null, ['next_draft_version' => (int) $run->draft_version + 1], $actorUserId);
        });
        return $this->finalPreview($organizationId, $runUuid);
    }

    public function discardDraft(int $organizationId, string $runUuid, int $actorUserId): array
    {
        DB::connection('tenant')->transaction(function () use ($organizationId, $runUuid, $actorUserId): void {
            $run = $this->runForOrganization($organizationId, $runUuid, true);
            if ($run->state === 'connected') $this->fail('connected_run_cannot_be_discarded');
            DB::connection('tenant')->table('integration_connection_wizard_decisions')->where('run_uuid', $runUuid)
                ->where('status', 'selected')->update(['status' => 'discarded', 'actor_user_id' => $actorUserId, 'updated_at' => now()]);
            DB::connection('tenant')->table('integration_connection_wizard_runs')->where('id', $run->id)->update([
                'state' => 'discarded', 'discarded_by_user_id' => $actorUserId, 'discarded_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($runUuid, null, 'draft_discarded', null, ['draft_version' => (int) $run->draft_version], $actorUserId);
        });
        return ['run_uuid' => $runUuid, 'state' => 'discarded', 'activation_available' => false];
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
        if (! $categoryId) {
            $this->fail('category_mapping_required_before_item_creation');
        }
        if (! $unitId) {
            $this->fail('unit_mapping_required_before_inventory_item_creation');
        }
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
            'item_type' => (string) ($books->item_type ?? $books->type ?? ''),
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
            'solabooks_quantity' => '0.0000', 'solastock_quantity' => '0.0000',
            'solabooks_inventory_value' => '0.00', 'solastock_inventory_value' => '0.00',
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
            $totals['solabooks_quantity'] = Decimal::qty(Decimal::add($totals['solabooks_quantity'], $row['solabooks']['quantity'] ?? '0'));
            $totals['solastock_quantity'] = Decimal::qty(Decimal::add($totals['solastock_quantity'], $row['solastock']['quantity'] ?? '0'));
            $totals['solabooks_inventory_value'] = Decimal::money(Decimal::add($totals['solabooks_inventory_value'], $row['solabooks']['inventory_value'] ?? '0'));
            $totals['solastock_inventory_value'] = Decimal::money(Decimal::add($totals['solastock_inventory_value'], $row['solastock']['inventory_value'] ?? '0'));
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
        $centralOrganization = DB::connection('mysql')->table('organizations')
            ->where('id', $organizationId)->where('is_active', true)->whereNull('deleted_at')->first();
        $centralClientId = (int) ($centralOrganization->client_id ?? 0);
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
        $comparison = array_merge($comparison, $this->preMappingSetupDecisionRows(
            $organizationId, $financeOrgId, $financeItems
        ));

        $counts = fn (string $table, int $org, string $column = 'organization_id'): int =>
            Schema::connection('tenant')->hasTable($table)
                ? (int) DB::connection('tenant')->table($table)->where($column, $org)->count()
                : 0;
        $blockers = array_values(array_filter([
            $centralClientId > 0 ? null : 'central_client_identity_missing',
            $financeOrgId > 0 ? null : 'finance_organization_identity_missing',
            $stockItems->isEmpty() ? 'owner_master_data_setup_required' : null,
            collect($comparison)->contains(fn (array $row) => $row['blocking_reason'] !== null)
                ? 'item_mapping_review_required' : null,
            'accountant_account_role_approval_required',
            'frozen_cutoff_and_zero_variance_required',
        ]));
        $quantityDifference = Decimal::qty(collect($comparison)->reduce(
            fn (string $carry, array $row) => Decimal::add($carry, $row['quantity_difference'], 4), '0'
        ));
        $valuationDifference = Decimal::money(collect($comparison)->reduce(
            fn (string $carry, array $row) => Decimal::add($carry, $row['value_difference'], 2), '0'
        ));
        $baseCurrency = (string) ($this->financeBaseCurrency($financeOrg) ?? '');
        $accountingEffect = $this->preMappingAccountingEffect(
            $organizationId, $financeOrgId, $comparison, $baseCurrency
        );
        $core = [
            'version' => self::VERSION,
            'organization_mapping_uuid' => null,
            'identity' => [
                'central_client_id' => $centralClientId ?: null,
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
                'total_quantity_difference' => $quantityDifference,
                'total_valuation_difference' => $valuationDifference,
                'proposed_accounting_effect' => $accountingEffect,
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
            'accounting' => [
                'base_currency' => $baseCurrency,
                'accounts' => collect($comparison)->where('entity_type', 'account_role')->map(fn (array $row) => [
                    'role' => $row['safe_details']['role'] ?? null,
                    'account_id' => $row['solabooks']['id'] ?? null,
                    'account_code' => $row['solabooks']['code'] ?? null,
                    'account_name' => $row['solabooks']['name'] ?? null,
                    'account_type' => $row['safe_details']['account_type'] ?? null,
                    'active' => $row['safe_details']['active'] ?? null,
                    'postable' => $row['safe_details']['postable'] ?? null,
                    'candidate_reason' => $row['safe_details']['candidate_reason'] ?? null,
                    'affected_workflows' => $row['safe_details']['affected_workflows'] ?? [],
                    'valid' => false,
                    'blocking_reason' => $row['blocking_reason'],
                ])->values()->all(),
                'complete' => false,
                'inheritance_policy' => 'item_override_then_category_then_organization',
            ],
            'decision_classes' => [
                'safe_deterministic_preparation', 'owner_decision', 'accountant_decision',
                'subscription_owner_decision', 'physical_count_requirement',
                'historical_review_requirement', 'permanently_blocked_excluded',
            ],
            'blockers' => $blockers,
            'guided_setup' => $this->guidedSetup($comparison, $baseCurrency, $financeOrgId),
        ];

        return $core + [
            'read_only' => true,
            'generated_at' => now()->utc()->toIso8601String(),
            'snapshot_hash' => $this->hash($core),
            'connection_state' => 'setup_available',
            'active_draft' => $this->activeDraftSummary($organizationId),
            'activation_available' => false,
            'legacy_fallback' => false,
        ];
    }

    /**
     * Compact presentation contract for the customer-facing assistant.
     *
     * Deterministic Finance identities are source-hashed into the versioned
     * draft/snapshot, but never materialized as operational mappings here.
     * Only exceptions which require a human decision remain editable.
     */
    private function guidedSetup(array $comparison, string $baseCurrency, int $financeOrgId): array
    {
        $rows = collect($comparison);
        $accountRows = $rows->where('entity_type', 'account_role');
        $resolvedAccounts = $accountRows->where('classification', 'candidate_requires_accountant_approval');
        $accountExceptions = $accountRows->reject(fn (array $row) =>
            $row['classification'] === 'candidate_requires_accountant_approval'
        );
        $taxRows = $rows->where('entity_type', 'tax');
        $resolvedTaxes = $taxRows->filter(fn (array $row) =>
            count($row['solabooks_record_ids'] ?? []) === 1
            && ($row['safe_details']['active'] ?? true) === true
        );
        $taxExceptions = $taxRows->reject(fn (array $row) =>
            count($row['solabooks_record_ids'] ?? []) === 1
            && ($row['safe_details']['active'] ?? true) === true
        );

        $base = strtoupper($baseCurrency);
        $usedCodes = $this->financeUsedCurrencyCodes($financeOrgId)->push($base)->filter()->unique();
        $currencyRows = $rows->where('entity_type', 'currency');
        $reservedCodes = ['XTS', 'XXX'];
        $operationalCurrencies = $currencyRows->filter(fn (array $row) =>
            $usedCodes->contains(strtoupper((string) ($row['solabooks']['code'] ?? '')))
        );
        $currencyExceptions = $operationalCurrencies->filter(function (array $row) use ($base): bool {
            $code = strtoupper((string) ($row['solabooks']['code'] ?? ''));
            $rate = (string) ($row['safe_details']['configured_rate'] ?? '');
            return ($row['safe_details']['active'] ?? false) !== true
                || ($code !== $base && (! is_numeric($rate) || (float) $rate <= 0));
        });
        $advancedCurrencies = $currencyRows->reject(fn (array $row) =>
            $operationalCurrencies->contains('fingerprint', $row['fingerprint'])
        );

        $visible = $rows->reject(function (array $row) use ($resolvedAccounts, $resolvedTaxes): bool {
            if ($resolvedAccounts->contains('fingerprint', $row['fingerprint'])
                || $resolvedTaxes->contains('fingerprint', $row['fingerprint'])) return true;
            if ($row['entity_type'] === 'currency' || $row['entity_type'] === 'historical_event') return true;
            // SolaStock warehouses are already authoritative and need no Finance
            // recreation unless a real conflict is discovered.
            return $row['entity_type'] === 'warehouse'
                && ($row['safe_details']['source'] ?? null) === 'existing_solastock_record';
        });
        $exceptionGroups = [
            'items' => $visible->where('entity_type', 'item')->pluck('fingerprint')->values()->all(),
            'inventory_quantities' => $visible->where('entity_type', 'item')
                ->filter(fn (array $row) => Decimal::cmp((string) $row['quantity_difference'], '0') !== 0)
                ->pluck('fingerprint')->values()->all(),
            'units' => $visible->whereIn('entity_type', ['unit', 'category'])->pluck('fingerprint')->values()->all(),
            'warehouses' => $visible->where('entity_type', 'warehouse')->pluck('fingerprint')->values()->all(),
            'parties' => $visible->whereIn('entity_type', ['customer', 'supplier'])->pluck('fingerprint')->values()->all(),
            'accounting' => $accountExceptions->merge($taxExceptions)->pluck('fingerprint')->values()->all(),
            'currencies' => $currencyExceptions->pluck('fingerprint')->values()->all(),
            'cutoff_documents' => $visible->where('entity_type', 'cutoff_document')->pluck('fingerprint')->values()->all(),
        ];
        $automatic = $resolvedAccounts->merge($resolvedTaxes)->merge($operationalCurrencies)
            ->map(fn (array $row) => [
                'entity_type' => $row['entity_type'],
                'fingerprint' => $row['fingerprint'],
                'finance_record_ids' => $row['solabooks_record_ids'],
                'source_hash' => $this->hash($row),
                'status' => 'deterministic_draft_binding',
            ])->values();

        $financeOperational = $rows->contains(fn (array $row): bool =>
            ! empty($row['solabooks_record_ids'])
            && (Decimal::cmp((string) ($row['solabooks']['quantity'] ?? '0'), '0') !== 0
                || Decimal::cmp((string) ($row['solabooks']['inventory_value'] ?? '0'), '0') !== 0)
        );
        $stockOperational = $rows->contains(fn (array $row): bool =>
            ! empty($row['solastock_record_ids'])
            && (Decimal::cmp((string) ($row['solastock']['quantity'] ?? '0'), '0') !== 0
                || Decimal::cmp((string) ($row['solastock']['inventory_value'] ?? '0'), '0') !== 0)
        );
        $customerScenario = $financeOperational && $stockOperational ? 'previously_separate'
            : ($financeOperational ? 'finance_first' : ($stockOperational ? 'stock_first' : 'new_both'));

        return [
            'version' => 'connection-assistant.v1',
            'customer_scenario' => $customerScenario,
            'automatic_bindings' => $automatic->all(),
            'automatic_bindings_hash' => $this->hash($automatic->all()),
            'checks' => [
                'organization_verified' => $financeOrgId > 0,
                'finance_connected' => $financeOrgId > 0,
                'base_currency_inherited' => $base !== '',
                'accounts_resolved' => $resolvedAccounts->count(),
                'account_exceptions' => $accountExceptions->count(),
                'taxes_resolved' => $resolvedTaxes->count(),
                'tax_exceptions' => $taxExceptions->count(),
                'items_exact' => $rows->where('classification', 'exact_candidate_requires_owner_review')->count(),
                'item_exceptions' => $rows->where('entity_type', 'item')->whereNotNull('blocking_reason')->count(),
            ],
            'currency_summary' => [
                'base_currency' => $base ?: null,
                'operational' => $operationalCurrencies->map(fn (array $row) => $row['solabooks']['code'])->filter()->unique()->values()->all(),
                'missing_rate_count' => $currencyExceptions->count(),
                'advanced_remediation_count' => $advancedCurrencies->count(),
                'reserved_codes' => $advancedCurrencies->map(fn (array $row) => strtoupper((string) ($row['solabooks']['code'] ?? '')))
                    ->filter(fn (string $code) => in_array($code, $reservedCodes, true))->unique()->values()->all(),
            ],
            'exception_groups' => $exceptionGroups,
            'visible_exception_fingerprints' => collect($exceptionGroups)->flatten()->unique()->values()->all(),
            'historical_exclusion_count' => $rows->where('entity_type', 'historical_event')->count(),
            'source_invalidation_hash' => $this->hash([
                'automatic' => $automatic->all(),
                'exceptions' => $exceptionGroups,
                'currency' => $operationalCurrencies->pluck('fingerprint')->values()->all(),
            ]),
            'operational_mutation_allowed' => false,
        ];
    }

    private function financeUsedCurrencyCodes(int $financeOrgId): Collection
    {
        if ($financeOrgId <= 0) return collect();
        $codes = collect();
        foreach ([
            'journal_entry_lines' => ['organization_id', ['transaction_currency_code', 'currency_code']],
            'journal_entries' => ['organization_id', ['transaction_currency_code', 'currency_code']],
            'bills' => ['organization_id', ['currency_code']],
            'invoices' => ['organization_id', ['currency_code']],
        ] as $table => [$organizationColumn, $currencyColumns]) {
            if (! Schema::connection('tenant')->hasTable($table)
                || ! Schema::connection('tenant')->hasColumn($table, $organizationColumn)) continue;
            foreach ($currencyColumns as $currencyColumn) {
                if (! Schema::connection('tenant')->hasColumn($table, $currencyColumn)) continue;
                $codes = $codes->merge(DB::connection('tenant')->table($table)
                    ->where($organizationColumn, $financeOrgId)->whereNotNull($currencyColumn)
                    ->distinct()->pluck($currencyColumn));
            }
        }
        return $codes->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn (string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)->unique()->values();
    }

    /**
     * Preview the GL-to-authoritative-stock correction, never the legacy-item
     * subledger difference. The candidate account remains unapproved and the
     * offset remains an explicit accountant decision.
     */
    private function preMappingAccountingEffect(
        int $stockOrganizationId,
        int $financeOrganizationId,
        array $comparison,
        string $baseCurrency,
    ): array {
        $inventoryCandidate = collect($comparison)->first(fn (array $row) =>
            $row['entity_type'] === 'account_role'
            && ($row['safe_details']['role'] ?? null) === 'inventory_asset'
            && count($row['solabooks_record_ids'] ?? []) === 1
        );
        $accountId = (int) ($inventoryCandidate['solabooks_record_ids'][0] ?? 0);
        foreach (['stock_balances', 'journal_entry_lines', 'journal_entries'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                return [
                    'status' => 'accounting_preview_requires_snapshot',
                    'requires_separate_accounting_approval' => true,
                    'automatic_posting' => false,
                ];
            }
        }
        if ($accountId <= 0) {
            return [
                'status' => 'inventory_control_account_selection_required',
                'requires_separate_accounting_approval' => true,
                'automatic_posting' => false,
            ];
        }

        $db = DB::connection('tenant');
        $stockValue = Decimal::money((string) $db->table('stock_balances')
            ->where('organization_id', $stockOrganizationId)->sum('total_value'));
        $gl = $db->table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('line.organization_id', $financeOrganizationId)
            ->where('line.account_id', $accountId)
            ->where('journal.status', 'posted')
            ->whereNull('line.deleted_at')->whereNull('journal.deleted_at')
            ->selectRaw('COALESCE(SUM(line.base_debit - line.base_credit), 0) AS balance')
            ->value('balance');
        $glValue = Decimal::money((string) ($gl ?? '0'));
        return $this->accountingEffectFromValues($accountId, $stockValue, $glValue, $baseCurrency);
    }

    private function accountingEffectFromValues(
        int $accountId,
        string $stockValue,
        string $glValue,
        string $baseCurrency,
    ): array {
        $difference = Decimal::money(Decimal::sub($stockValue, $glValue, 2));
        if (Decimal::isZero($difference, 2)) {
            return [];
        }

        $increase = Decimal::cmp($difference, '0') > 0;
        return [
            'status' => 'preview_only_unapproved_account_candidate',
            'requires_separate_accounting_approval' => true,
            'inventory_control_account_candidate_id' => (string) $accountId,
            'inventory_control_current' => $glValue,
            'authoritative_stock_target' => $stockValue,
            'debit' => $increase ? 'inventory_asset_candidate' : 'accountant_selected_cutoff_offset',
            'credit' => $increase ? 'accountant_selected_cutoff_offset' : 'inventory_asset_candidate',
            'amount' => ltrim(Decimal::money($increase ? $difference : substr($difference, 1)), '-'),
            'currency' => $baseCurrency,
            'automatic_posting' => false,
        ];
    }

    /** Draft-only candidates. Nothing returned here is an operational mapping. */
    private function preMappingSetupDecisionRows(int $stockOrgId, int $financeOrgId, Collection $financeItems): array
    {
        if ($financeOrgId <= 0) {
            return [];
        }
        $rows = [];
        $normal = static fn (mixed $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        $referencedUnits = $financeItems->pluck('unit_id')->filter()->unique()->sort()->values();
        if (Schema::connection('tenant')->hasTable('units')) {
            $stockUnits = DB::connection('tenant')->table('units')->where('organization_id', $stockOrgId)
                ->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->get();
            $availableStockUnits = $stockUnits->map(fn ($unit) => [
                'id' => (string) $unit->id,
                'name' => (string) ($unit->name ?? ''),
                'code' => (string) ($unit->code ?? ''),
                'symbol' => (string) ($unit->symbol ?? ''),
            ])->values()->all();
            $resolvedUnitIds = [];
            foreach (DB::connection('tenant')->table('units')->whereIn('id', $referencedUnits)->orderBy('id')->get() as $unit) {
                $resolvedUnitIds[] = (int) $unit->id;
                $matches = $stockUnits->filter(fn ($stock) => $normal($stock->name ?? '') !== ''
                    && ($normal($stock->name ?? '') === $normal($unit->name ?? '')
                        || ($normal($stock->symbol ?? '') !== '' && $normal($stock->symbol ?? '') === $normal($unit->symbol ?? ''))));
                $affected = $financeItems->where('unit_id', (int) $unit->id)->map(fn ($item) => [
                    'id' => (string) ($item->id ?? ''), 'name' => (string) ($item->name ?? ''),
                    'sku' => (string) ($item->sku ?? ''),
                    'item_type' => (string) ($item->item_type ?? $item->type ?? ''),
                    'quantity' => Decimal::qty((string) ($item->qty_on_hand ?? '0')),
                ])->values()->all();
                $rows[] = $this->draftCandidate('unit', 'owner_review_required', $matches->count() === 1 ? $matches->first() : null, $unit,
                    'owner_unit_selection_required', 'owner_decision', [
                        'source' => 'finance_item_reference', 'affected_items' => $affected,
                        'solastock_candidate_count' => $matches->count(),
                        'available_stock_units' => $availableStockUnits,
                    ]);
            }
            foreach ($referencedUnits->reject(fn ($id) => in_array((int) $id, $resolvedUnitIds, true)) as $missingUnitId) {
                $affected = $financeItems->where('unit_id', (int) $missingUnitId)->map(fn ($item) => [
                    'id' => (string) ($item->id ?? ''), 'name' => (string) ($item->name ?? ''),
                    'sku' => (string) ($item->sku ?? ''),
                    'item_type' => (string) ($item->item_type ?? $item->type ?? ''),
                    'quantity' => Decimal::qty((string) ($item->qty_on_hand ?? '0')),
                ])->values()->all();
                $rows[] = $this->draftCandidate('unit', 'owner_review_required', null,
                    (object) ['id' => (int) $missingUnitId, 'name' => ''],
                    'owner_unit_selection_required', 'owner_decision', [
                        'source' => 'dangling_finance_item_unit_reference',
                        'authoritative_unit_details_required' => true,
                        'finance_reference_id' => (string) $missingUnitId,
                        'affected_items' => $affected,
                        'solastock_candidate_count' => 0,
                        'available_stock_units' => $availableStockUnits,
                    ]);
            }
        }

        $referencedCategories = $financeItems->pluck('category_id')->filter()->unique()->sort()->values();
        if (Schema::connection('tenant')->hasTable('inventory_categories')) {
            $stockCategories = Schema::connection('tenant')->hasTable('item_categories')
                ? DB::connection('tenant')->table('item_categories')->where('organization_id', $stockOrgId)
                    ->whereNull('deleted_at')->orderBy('id')->get()
                : collect();
            foreach (DB::connection('tenant')->table('inventory_categories')->where(function ($query) use ($financeOrgId): void {
                    $query->where('organization_id', $financeOrgId)->orWhereNull('organization_id');
                })
                ->whereIn('id', $referencedCategories)->orderBy('id')->get() as $category) {
                $matches = $stockCategories->filter(fn ($stock) => $normal($stock->name ?? '') !== ''
                    && $normal($stock->name ?? '') === $normal($category->name ?? ''));
                $affected = $financeItems->where('category_id', (int) $category->id)->map(fn ($item) => [
                    'id' => (string) ($item->id ?? ''), 'name' => (string) ($item->name ?? ''),
                    'sku' => (string) ($item->sku ?? ''),
                    'item_type' => (string) ($item->item_type ?? $item->type ?? ''),
                    'quantity' => Decimal::qty((string) ($item->qty_on_hand ?? '0')),
                ])->values()->all();
                $rows[] = $this->draftCandidate('category', 'owner_review_required', $matches->count() === 1 ? $matches->first() : null, $category,
                    'owner_category_selection_required', 'owner_decision', [
                        'source' => 'finance_item_reference', 'affected_items' => $affected,
                        'solastock_candidate_count' => $matches->count(),
                    ]);
            }
        }

        foreach (['warehouses' => 'warehouse', 'inventory_customers' => 'customer', 'inventory_suppliers' => 'supplier'] as $table => $entity) {
            if (! Schema::connection('tenant')->hasTable($table)) continue;
            $org = $table === 'warehouses' ? $stockOrgId : $stockOrgId;
            foreach (DB::connection('tenant')->table($table)->where('organization_id', $org)->orderBy('id')->get() as $record) {
                $rows[] = $this->draftCandidate($entity, 'owner_review_required', $record, null,
                    'owner_authoritative_record_selection_required', 'owner_decision', ['source' => 'existing_solastock_record']);
            }
        }
        foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $table => $entity) {
            if (! Schema::connection('tenant')->hasTable($table)) continue;
            foreach (DB::connection('tenant')->table($table)->where('organization_id', $financeOrgId)->orderBy('id')->get() as $record) {
                $rows[] = $this->draftCandidate($entity, 'owner_review_required', null, $record,
                    'owner_authoritative_record_selection_required', 'owner_decision', ['source' => 'existing_finance_record']);
            }
        }
        if (Schema::connection('tenant')->hasTable('taxes')) {
            foreach (DB::connection('tenant')->table('taxes')->where('organization_id', $financeOrgId)->orderBy('id')->get() as $tax) {
                $rows[] = $this->draftCandidate('tax', 'owner_review_required', null, $tax,
                    'owner_tax_selection_required', 'owner_decision', [
                        'rate' => (string) ($tax->rate ?? ''), 'classification' => $tax->classification ?? null,
                        'active' => (bool) ($tax->is_active ?? true),
                        'finance_authoritative' => true,
                    ]);
            }
        }
        if (Schema::connection('tenant')->hasTable('organization_currencies') && Schema::connection('tenant')->hasTable('currencies')) {
            foreach (DB::connection('tenant')->table('organization_currencies as oc')->join('currencies as c', 'c.id', '=', 'oc.currency_id')
                ->where('oc.organization_id', $financeOrgId)->where('oc.is_enabled', true)
                ->orderBy('c.code')->get(['c.id', 'c.code', 'c.name', 'c.is_active', 'oc.default_exchange_rate']) as $currency) {
                $rows[] = $this->draftCandidate('currency', 'owner_review_required', null, $currency,
                    'operational_currency_review_required', 'owner_decision', [
                        'active' => (bool) $currency->is_active, 'configured_rate' => (string) $currency->default_exchange_rate,
                    ]);
            }
        }

        foreach ($this->accountRoleCandidates($financeOrgId) as $candidate) {
            $rows[] = $candidate;
        }

        if (Schema::connection('tenant')->hasTable('integration_outbox_events')) {
            foreach (DB::connection('tenant')->table('integration_outbox_events')->where('organization_id', $stockOrgId)
                ->where('status', 'ignored')->orderBy('id')->get() as $event) {
                $rows[] = $this->draftCandidate('historical_event', 'historical_exclusion_review', $event, null,
                    'historical_event_must_remain_excluded', 'owner_decision', [
                        'event_type' => $event->event_type ?? null,
                        'source_key' => $event->idempotency_key ?? $event->event_uuid ?? null,
                    ]);
            }
        }
        foreach ([
            'goods_receipts' => ['posted'],
            'bills' => ['draft', 'unpaid'],
            'invoices' => ['draft', 'unpaid'],
        ] as $table => $statuses) {
            if (! Schema::connection('tenant')->hasTable($table)) continue;
            foreach (DB::connection('tenant')->table($table)->where('organization_id', $table === 'goods_receipts' ? $stockOrgId : $financeOrgId)
                ->whereIn('status', $statuses)->orderBy('id')->get() as $document) {
                $rows[] = $this->draftCandidate('cutoff_document', 'cutoff_review_required', $table === 'goods_receipts' ? $document : null,
                    $table === 'goods_receipts' ? null : $document, 'open_or_posted_document_cutoff_review_required', 'owner_decision', [
                        'document_type' => $table, 'status' => $document->status ?? null,
                    ]);
            }
        }

        return $rows;
    }

    private function accountRoleCandidates(int $financeOrgId): array
    {
        if (! Schema::connection('tenant')->hasTable('accounts')) return [];
        $definitions = [
            'inventory_asset' => ['asset', ['inventory'], ['receipt', 'shipment', 'adjustment', 'opening']],
            'cogs' => ['expense', ['cogs', 'cost of goods'], ['shipment', 'return']],
            'grni' => ['liability', ['grni', 'received not invoiced'], ['goods_receipt', 'supplier_bill']],
            'opening_offset' => ['equity', ['opening'], ['opening_stock']],
            'adjustment_gain' => ['revenue', ['adjustment gain'], ['positive_adjustment']],
            'adjustment_loss' => ['expense', ['adjustment loss', 'shrinkage'], ['negative_adjustment']],
            'landed_cost_clearing' => ['asset', ['landed', 'clearing'], ['landed_cost']],
            'transfer_clearing' => ['asset', ['transfer', 'clearing'], ['cross_entity_transfer']],
            'accounts_receivable' => ['asset', ['receivable'], ['customer_invoice']],
            'accounts_payable' => ['liability', ['payable'], ['supplier_bill']],
            'input_tax' => ['asset', ['input', 'tax'], ['supplier_bill']],
            'output_tax' => ['liability', ['output', 'tax'], ['customer_invoice']],
            'rounding' => ['expense', ['rounding', 'cutoff'], ['currency_rounding', 'cutoff_correction']],
        ];
        $accounts = DB::connection('tenant')->table('accounts')->where('organization_id', $financeOrgId)
            ->where('is_active', true)->where('is_postable', true)->orderBy('code')->orderBy('id')->get();
        $rows = [];
        foreach ($definitions as $role => [$type, $keywords, $workflows]) {
            $matches = $accounts->filter(function ($account) use ($type, $keywords): bool {
                if (strtolower((string) ($account->type ?? '')) !== $type) return false;
                $name = strtolower((string) ($account->name ?? '').' '.(string) ($account->code ?? ''));
                return collect($keywords)->contains(fn (string $keyword) => str_contains($name, $keyword));
            })->values();
            $candidate = $matches->count() === 1 ? $matches->first() : null;
            $classification = $candidate ? 'candidate_requires_accountant_approval'
                : ($matches->count() > 1 ? 'ambiguous_account_role' : 'unresolved_account_role');
            $rows[] = $this->draftCandidate('account_role', $classification,
                null, $candidate, 'account_role_requires_explicit_accountant_selection', 'accountant_decision', [
                    'role' => $role, 'account_type' => $candidate->type ?? $type,
                    'active' => $candidate ? (bool) $candidate->is_active : null,
                    'postable' => $candidate ? (bool) $candidate->is_postable : null,
                    'organization_id' => $financeOrgId,
                    'candidate_reason' => $candidate ? 'active_postable_owned_type_and_documented_purpose_match'
                        : ($matches->count() > 1 ? 'multiple_owned_candidates_require_finance_review' : 'no_unambiguous_owned_candidate'),
                    'candidate_count' => $matches->count(),
                    'affected_workflows' => $workflows,
                ]);
        }
        return $rows;
    }

    private function draftCandidate(string $entityType, string $classification, ?object $stock, ?object $books,
        string $blocking, string $decisionClass, array $details = []): array
    {
        $record = fn (?object $value): ?array => $value ? array_filter([
            'id' => (string) ($value->id ?? ''),
            'name' => is_string($value->name ?? null) ? (string) $value->name : (string) ($value->code ?? $value->id ?? ''),
            'code' => $value->code ?? $value->sku ?? $value->event_uuid ?? null,
            'sku' => $value->sku ?? null,
            'quantity' => '0.0000', 'inventory_value' => '0.00',
        ], fn ($item) => $item !== null) : null;
        $stockRecord = $record($stock);
        $booksRecord = $record($books);
        $identity = [
            'entity_type' => $entityType, 'classification' => $classification,
            'stock_id' => $stockRecord['id'] ?? null, 'books_id' => $booksRecord['id'] ?? null,
            'role' => $details['role'] ?? null, 'document_type' => $details['document_type'] ?? null,
        ];
        return [
            'fingerprint' => $this->hash($identity), 'entity_type' => $entityType,
            'classification' => $classification,
            'solastock_record_ids' => isset($stockRecord['id']) ? [$stockRecord['id']] : [],
            'solabooks_record_ids' => isset($booksRecord['id']) ? [$booksRecord['id']] : [],
            'solastock' => $stockRecord, 'solabooks' => $booksRecord,
            'quantity_difference' => '0.0000', 'value_difference' => '0.00',
            'blocking_reason' => $blocking, 'mapping_confidence' => 'review_required',
            'decision_class' => $decisionClass, 'safe_details' => $details,
        ];
    }

    /**
     * The wizard API never accepts a free-form action for a candidate type. This
     * keeps draft writes expressive without allowing them to become operational
     * master-data commands through a direct request.
     */
    private function allowedActionsForCandidate(array $candidate): array
    {
        return match ($candidate['entity_type']) {
            'item' => match ($candidate['classification']) {
                'exact_candidate_requires_owner_review' => ['approve_exact_binding', 'reject_exact_binding', 'physical_count_required'],
                'missing_solastock_record' => ['create_solastock_record', 'classify_service_non_inventory', 'exclude_initial_connection', 'physical_count_required'],
                'missing_solabooks_record' => ['keep_solastock_authority', 'exclude_initial_connection', 'physical_count_required'],
                default => ['retain_blocked', 'physical_count_required'],
            },
            'unit' => ! empty($candidate['solastock'])
                ? ['select_unit', 'retain_blocked']
                : array_values(array_filter([
                    'propose_unit_creation',
                    ! empty($candidate['safe_details']['available_stock_units']) ? 'define_unit_conversion' : null,
                    'retain_blocked',
                ])),
            'category' => ['select_category', 'propose_category_creation', 'retain_blocked'],
            'warehouse' => ['select_warehouse', 'retain_blocked'],
            'customer', 'supplier' => ['select_party', 'retain_blocked'],
            'tax' => ['select_tax', 'retain_blocked'],
            'currency' => ['retain_currency', 'exclude_currency', 'retain_blocked'],
            'historical_event' => ['retain_historical_exclusion'],
            'cutoff_document' => ['review_cutoff_document', 'retain_blocked'],
            'account_role' => ['select_account_role', 'retain_account_role_unresolved'],
            default => ['retain_blocked'],
        };
    }

    private function decisionMatchesCandidate(object $decision, ?array $candidate): bool
    {
        if (! $candidate || ! in_array((string) $decision->action, $this->allowedActionsForCandidate($candidate), true)) {
            return false;
        }
        $action = (string) $decision->action;
        $details = json_decode($decision->safe_details ?: '{}', true) ?: [];
        $stockIds = array_values(array_map('strval', $candidate['solastock_record_ids'] ?? []));
        if (in_array($action, ['select_unit', 'select_category'], true)) {
            return isset($details['selected_record_id'])
                && in_array((string) $details['selected_record_id'], $stockIds, true);
        }
        if ($action === 'define_unit_conversion') {
            $availableIds = collect($candidate['safe_details']['available_stock_units'] ?? [])
                ->pluck('id')->map('strval')->all();
            return isset($details['selected_record_id'], $details['conversion_factor'])
                && in_array((string) $details['selected_record_id'], $availableIds, true)
                && (string) ($details['conversion_direction'] ?? '') === 'solabooks_to_solastock';
        }
        if (in_array($action, ['propose_unit_creation', 'propose_category_creation'], true)) {
            return ! empty($candidate['solabooks']['name']) && empty($stockIds);
        }
        return true;
    }

    private function activeDraftSummary(int $organizationId): ?array
    {
        if (! Schema::connection('tenant')->hasTable('integration_connection_wizard_runs')
            || ! Schema::connection('tenant')->hasColumn('integration_connection_wizard_runs', 'tenant_database_identity')) {
            return null;
        }
        $run = DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('central_organization_id', $organizationId)
            ->where('solastock_organization_id', $organizationId)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->whereIn('state', [
                'draft_decisions', 'decisions_complete', 'snapshot_required', 'cutoff_review',
                'preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready',
            ])->whereNull('discarded_at')->latest('id')->first();

        return $run ? [
            'run_uuid' => (string) $run->run_uuid,
            'state' => (string) $run->state,
            'draft_version' => (int) $run->draft_version,
            'lock_version' => (int) $run->lock_version,
            'updated_at' => (string) $run->updated_at,
        ] : null;
    }

    private function financeBaseCurrency(?object $financeOrg): ?string
    {
        if (! $financeOrg || ! isset($financeOrg->base_currency_id) || ! Schema::connection('tenant')->hasTable('currencies')) return null;
        return DB::connection('tenant')->table('currencies')->where('id', $financeOrg->base_currency_id)->value('code');
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

    private function runForOrganization(int $organizationId, string $runUuid, bool $lock = false): object
    {
        $query = DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('run_uuid', $runUuid)
            ->where('solastock_organization_id', $organizationId)
            ->where('central_organization_id', $organizationId)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName());
        if ($lock) $query->lockForUpdate();
        $run = $query->first();
        if (! $run) $this->fail('wizard_run_scope_mismatch');
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

    private function safeDecisionDetails(array $details, string $action = '', array $candidate = [], array $availableStockUnits = []): array
    {
        if (array_key_exists('physical_quantity', $details)) {
            $quantity = trim((string) $details['physical_quantity']);
            if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $quantity)) {
                throw ValidationException::withMessages([
                    'safe_details.physical_quantity' => ['wizard_physical_quantity_invalid'],
                ]);
            }
            $details['physical_quantity'] = $quantity;
        }
        if ($action === 'define_unit_conversion') {
            $selectedId = (string) $details['selected_record_id'];
            $selectedUnit = $availableStockUnits[$selectedId] ?? null;
            $details['conversion_factor'] = trim((string) $details['conversion_factor']);
            $details['selected_record_id'] = $selectedId;
            $details['source_record_id'] = (string) $candidate['solabooks']['id'];
            $details['conversion_direction'] = 'solabooks_to_solastock';
            $details['target_unit_name'] = (string) ($selectedUnit['name'] ?? '');
            $details['target_unit_code'] = (string) ($selectedUnit['code'] ?? '');
        }
        return collect($details)->only([
            'reason', 'selected_record_id', 'physical_count_reference', 'physical_quantity',
            'accounting_approval_required', 'note', 'conversion_factor', 'source_record_id',
            'conversion_direction', 'target_unit_name', 'target_unit_code',
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
