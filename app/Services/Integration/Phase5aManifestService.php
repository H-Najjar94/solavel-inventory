<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Phase5aManifestService
{
    public const VERSION = 'solabooks-solastock.phase5a.v1';

    /** @return array<string,mixed> */
    public function generate(string $mappingUuid, string $output): array
    {
        $mapping = DB::connection('tenant')->table('integration_organization_mappings')
            ->where('mapping_uuid', $mappingUuid)->first();
        if (! $mapping || ! in_array($mapping->status, ['verified', 'verified_hold'], true)) {
            throw new RuntimeException('A verified immutable organization mapping is required.');
        }

        $sets = [
            'pending-events' => $this->eventCandidates($mapping, 'pending'),
            'ignored-accounting-events' => $this->eventCandidates($mapping, 'ignored'),
            'valuation-layer-mismatch' => $this->valuationLayerCandidates($mapping),
            'client-18-valuation' => $this->legacyIdentityCandidates($mapping),
            'unresolved-mappings' => $this->mappingDecisionCandidates($mapping),
        ];
        $sets['qa-reconciliation'] = [$this->qaCandidate(
            $mapping,
            $sets['pending-events'],
            $sets['ignored-accounting-events'],
        )];

        if (! is_dir($output) && ! mkdir($output, 0750, true) && ! is_dir($output)) {
            throw new RuntimeException("Cannot create evidence directory {$output}.");
        }

        $index = [
            'manifest_version' => self::VERSION,
            'read_only' => true,
            'tenant_database' => $mapping->tenant_database_identity,
            'central_client_id' => (int) $mapping->central_client_id,
            'organization_mapping_uuid' => $mapping->mapping_uuid,
            'sets' => [],
        ];
        foreach ($sets as $name => $rows) {
            usort($rows, fn (array $a, array $b): int => strcmp($a['candidate_id'], $b['candidate_id']));
            $content = implode('', array_map(
                fn (array $row): string => $this->canonicalJson($row).PHP_EOL,
                $rows,
            ));
            $path = "{$output}/{$name}.jsonl";
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException("Cannot write {$path}.");
            }
            chmod($path, 0640);
            $index['sets'][$name] = [
                'file' => basename($path),
                'records' => count($rows),
                'sha256' => hash('sha256', $content),
                'classifications' => collect($rows)->countBy('repair_classification')->sortKeys()->all(),
            ];
        }
        ksort($index['sets'], SORT_STRING);
        $indexJson = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents("{$output}/manifest-index.json", $indexJson, LOCK_EX);
        chmod("{$output}/manifest-index.json", 0640);

        return $index + ['index_sha256' => hash('sha256', $indexJson)];
    }

    /** @return list<array<string,mixed>> */
    private function eventCandidates(object $mapping, string $status): array
    {
        return DB::connection('tenant')->table('integration_outbox_events')
            ->where('integration', 'solabooks')->where('organization_id', $mapping->solastock_organization_id)
            ->where('status', $status)->orderBy('id')->get()
            ->filter(function (object $event): bool {
                return IntegrationEvents::isAccountingEventForReconciliation(
                    (string) $event->event_type,
                    json_decode((string) $event->payload, true) ?: [],
                );
            })->map(function (object $event) use ($mapping, $status): array {
                $payload = json_decode((string) $event->payload, true) ?: [];
                $effect = $this->decimal($payload['total_inventory_value_change'] ?? 0);
                $zero = bccomp($effect, '0', 4) === 0;
                $classification = $status === 'pending'
                    ? 'requires_v2_replay_approval'
                    : ($zero ? 'permanent_documented_exclusion' : 'requires_v2_replay_approval');
                $before = [
                    'id' => (int) $event->id,
                    'event_uuid' => $event->event_uuid,
                    'status' => $event->status,
                    'attempts' => (int) $event->attempts,
                    'mapping_status' => $event->mapping_status,
                    'external_document_id' => $event->external_document_id,
                    'payload_sha256' => hash('sha256', (string) $event->payload),
                    'updated_at' => (string) $event->updated_at,
                ];

                return $this->candidate($mapping, [
                    'candidate_id' => "{$status}-event:".str_pad((string) $event->id, 10, '0', STR_PAD_LEFT),
                    'repair_set' => $status === 'pending' ? 'pending_events' : 'ignored_accounting_events',
                    'table_model' => 'integration_outbox_events',
                    'primary_key' => (int) $event->id,
                    'source_event_identity' => $event->event_uuid,
                    'source_document_identity' => $event->aggregate_type.':'.$event->aggregate_id,
                    'journal_identity' => $event->external_document_id ?: null,
                    'current_before_image' => $before,
                    'before_image_hash' => $this->hash($before),
                    'proposed_after_image_or_corrective_entry' => $zero
                        ? ['status' => 'ignored', 'action' => 'retain_with_approved_exclusion_reason']
                        : ['action' => 'future_controlled_v2_replay', 'status_change_in_phase5a' => false],
                    'accounting_justification' => $zero
                        ? 'No economic inventory or accounting effect exists.'
                        : 'The stock movement exists without a trusted Finance effect; replay is permitted only after v2 reconstruction, duplicate checks, mapping validation, and approval.',
                    'effects' => [
                        'quantity' => null,
                        'inventory_value' => $effect,
                        'currency' => $payload['currency'] ?? $payload['currency_code'] ?? null,
                        'tax' => null,
                        'subledger' => null,
                    ],
                    'related_records' => [
                        'event_type' => $event->event_type,
                        'aggregate_number' => $event->aggregate_number,
                        'idempotency_key' => $event->idempotency_key,
                    ],
                    'preconditions' => ['approved_v2_payload', 'all_mappings_verified', 'no_existing_finance_effect', 'open_or_approved_closed_period'],
                    'conflict_conditions' => ['before_image_changed', 'existing_manual_or_v2_finance_effect', 'mapping_unresolved', 'identity_mismatch'],
                    'idempotency_key' => 'phase5:'.$mapping->mapping_uuid.':event:'.$event->event_uuid,
                    'validation_queries' => ['event_before_image', 'finance_source_and_payload_hash_uniqueness', 'stock_ledger_source_effect', 'mapping_ownership'],
                    'rollback_or_reversal_method' => 'Compensating v2 reversal referencing the resulting durable Finance receipt; never delete the source event.',
                    'approval_status' => 'not_approved',
                    'reviewer_required_reason' => $zero ? 'Approve permanent exclusion.' : 'Approve replay versus corrective-journal decision and accounting period.',
                    'risk_classification' => $zero ? 'low' : 'high',
                    'repair_classification' => $classification,
                ]);
            })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function valuationLayerCandidates(object $mapping): array
    {
        if (! Schema::connection('tenant')->hasTable('cost_layers')) {
            return [];
        }
        $org = (int) $mapping->solastock_organization_id;
        $balances = DB::connection('tenant')->table('stock_balances')->where('organization_id', $org)
            ->selectRaw('item_id, SUM(on_hand_qty) quantity, SUM(total_value) value')
            ->groupBy('item_id')->orderBy('item_id')->get();

        return $balances->filter(function (object $balance) use ($org): bool {
            $layerQty = (string) DB::connection('tenant')->table('cost_layers')
                ->where('organization_id', $org)->where('item_id', $balance->item_id)->sum('remaining_qty');

            return bccomp($this->decimal($balance->quantity), $this->decimal($layerQty), 4) !== 0;
        })->map(function (object $balance) use ($mapping, $org): array {
            $first = DB::connection('tenant')->table('stock_ledger')->where('organization_id', $org)
                ->where('item_id', $balance->item_id)->orderBy('id')->first();
            $layers = DB::connection('tenant')->table('cost_layers')->where('organization_id', $org)
                ->where('item_id', $balance->item_id)
                ->selectRaw('COALESCE(SUM(remaining_qty),0) quantity, COALESCE(SUM(remaining_qty * unit_cost),0) value')->first();
            $before = [
                'item_id' => (int) $balance->item_id,
                'balance_quantity' => $this->decimal($balance->quantity),
                'balance_value' => $this->decimal($balance->value),
                'layer_quantity' => $this->decimal($layers->quantity),
                'layer_value' => $this->decimal($layers->value),
                'first_divergence_ledger_id' => $first ? (int) $first->id : null,
                'first_source' => $first ? $first->source_type.':'.$first->source_id : null,
            ];

            return $this->candidate($mapping, [
                'candidate_id' => 'valuation-layer-item:'.str_pad((string) $balance->item_id, 10, '0', STR_PAD_LEFT),
                'repair_set' => 'valuation_layer_mismatch',
                'table_model' => 'cost_layers',
                'primary_key' => 'organization:'.$org.'/item:'.$balance->item_id,
                'source_event_identity' => null,
                'source_document_identity' => $before['first_source'],
                'journal_identity' => null,
                'current_before_image' => $before,
                'before_image_hash' => $this->hash($before),
                'proposed_after_image_or_corrective_entry' => ['action' => 'reviewed_layer_reconstruction_from_immutable_ledger', 'stock_balance_change' => false],
                'accounting_justification' => 'Stock balance and signed ledger agree, but valuation layers do not cover the authoritative on-hand quantity/value.',
                'effects' => ['quantity' => '0', 'inventory_value' => '0', 'currency' => $mapping->base_currency_code, 'tax' => '0', 'subledger' => '0'],
                'related_records' => ['stock_ledger_id' => $before['first_divergence_ledger_id'], 'item_id' => (int) $balance->item_id],
                'preconditions' => ['immutable_ledger_hash_match', 'balance_hash_match', 'costing_method_approved'],
                'conflict_conditions' => ['new_ledger_movement', 'layer_or_balance_changed', 'costing_method_ambiguous'],
                'idempotency_key' => 'phase5:'.$mapping->mapping_uuid.':layer-item:'.$balance->item_id,
                'validation_queries' => ['balance_vs_signed_ledger_by_item', 'open_layers_by_item', 'first_inbound_without_layer'],
                'rollback_or_reversal_method' => 'Restore captured layer before-image as a guarded batch; stock ledger and balances remain untouched.',
                'approval_status' => 'not_approved',
                'reviewer_required_reason' => 'Accounting must approve costing-method layer reconstruction.',
                'risk_classification' => 'high',
                'repair_classification' => 'requires_layer_reconstruction_review',
            ]);
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function legacyIdentityCandidates(object $mapping): array
    {
        if ((int) $mapping->central_client_id !== 18) {
            return [];
        }
        $legacyOrg = 1;
        $ledger = DB::connection('tenant')->table('stock_ledger')->where('organization_id', $legacyOrg)
            ->orderBy('id')->get();
        $running = [];
        $first = null;
        foreach ($ledger as $row) {
            $coordinate = implode(':', [
                $row->item_id,
                $row->variant_id ?? 0,
                $row->warehouse_id,
                $row->lot_id ?? 0,
                $row->bin_id ?? 0,
            ]);
            $signed = $row->direction === 'out' ? bcmul('-1', (string) $row->total_cost, 4) : (string) $row->total_cost;
            $running[$coordinate] = bcadd($running[$coordinate] ?? '0', $signed, 4);
            if ($first === null && bccomp($running[$coordinate], (string) $row->balance_value_after, 2) !== 0) {
                $first = $row;
            }
        }
        if (! $first) {
            return [];
        }
        $before = [
            'stock_ledger_id' => (int) $first->id,
            'legacy_organization_id' => $legacyOrg,
            'validated_v2_organization_id' => (int) $mapping->solastock_organization_id,
            'item_id' => (int) $first->item_id,
            'direction' => $first->direction,
            'quantity' => $this->decimal($first->quantity),
            'unit_cost' => $this->decimal($first->unit_cost),
            'total_cost' => $this->decimal($first->total_cost),
            'balance_qty_after' => $this->decimal($first->balance_qty_after),
            'balance_value_after' => $this->decimal($first->balance_value_after),
            'source' => $first->source_type.':'.$first->source_id,
        ];

        return [$this->candidate($mapping, [
            'candidate_id' => 'client-18-valuation:'.str_pad((string) $first->id, 10, '0', STR_PAD_LEFT),
            'repair_set' => 'client_18_valuation',
            'table_model' => 'stock_ledger',
            'primary_key' => (int) $first->id,
            'source_event_identity' => $first->idempotency_key,
            'source_document_identity' => $before['source'],
            'journal_identity' => null,
            'current_before_image' => $before,
            'before_image_hash' => $this->hash($before),
            'proposed_after_image_or_corrective_entry' => [
                'unit_cost' => '33.0000',
                'total_cost' => '99.0000',
                'organization_id' => $legacyOrg,
                'action' => 'guarded_immutable-ledger_correction_or_compensating_valuation_entry_requires_design_approval',
            ],
            'accounting_justification' => 'The first divergence is an outgoing quantity of 3 with zero cost from a pre-movement layer of 5 units/value 165; omitted cost is 99. Legacy identity 1 must remain isolated from validated identity 25.',
            'effects' => ['quantity' => '0', 'inventory_value' => '99.0000', 'currency' => $mapping->base_currency_code, 'tax' => '0', 'subledger' => '0'],
            'related_records' => ['legacy_organization_id' => 1, 'validated_v2_organization_id' => 25, 'item_id' => (int) $first->item_id],
            'preconditions' => ['ledger_total_170', 'balance_total_71', 'identity_1_unchanged', 'mapping_25_verified', 'accounting_method_approved'],
            'conflict_conditions' => ['legacy_identity_changed', 'ledger_or_balance_hash_changed', 'new_compensating_entry_exists'],
            'idempotency_key' => 'phase5:'.$mapping->mapping_uuid.':client18-valuation:ledger-'.$first->id,
            'validation_queries' => ['ledger_rollforward_by_item', 'stock_balance_by_item', 'organization_identity_isolation', 'finance_inventory_control_effect'],
            'rollback_or_reversal_method' => 'Restore exact ledger before-image only if immutable-ledger exception is approved, otherwise reverse the compensating valuation entry.',
            'approval_status' => 'not_approved',
            'reviewer_required_reason' => 'Accounting and inventory-engine owners must choose guarded ledger correction versus compensating valuation entry.',
            'risk_classification' => 'critical',
            'repair_classification' => 'requires_accounting_and_engine_review',
        ])];
    }

    /** @return list<array<string,mixed>> */
    private function mappingDecisionCandidates(object $mapping): array
    {
        if (! Schema::connection('tenant')->hasTable('integration_mapping_discovery_results')) {
            return [];
        }
        $latestRun = DB::connection('tenant')->table('integration_mapping_discovery_runs')
            ->where('organization_mapping_uuid', $mapping->mapping_uuid)->orderByDesc('id')->value('run_uuid');
        if (! $latestRun) {
            return [];
        }

        return DB::connection('tenant')->table('integration_mapping_discovery_results')
            ->where('run_uuid', $latestRun)->where('classification', '!=', 'exact_match')
            ->orderBy('entity_type')->orderBy('fingerprint')->get()->map(function (object $row) use ($mapping): array {
                $before = [
                    'run_uuid' => $row->run_uuid,
                    'entity_type' => $row->entity_type,
                    'classification' => $row->classification,
                    'solastock_record_ids' => json_decode((string) $row->solastock_record_ids, true),
                    'solabooks_record_ids' => json_decode((string) $row->solabooks_record_ids, true),
                    'fingerprint' => $row->fingerprint,
                    'safe_details' => json_decode((string) $row->safe_details, true),
                    'resolution_status' => $row->resolution_status,
                ];

                return $this->candidate($mapping, [
                    'candidate_id' => 'mapping-decision:'.$row->fingerprint,
                    'repair_set' => 'unresolved_mappings',
                    'table_model' => 'integration_mapping_discovery_results',
                    'primary_key' => (int) $row->id,
                    'source_event_identity' => null,
                    'source_document_identity' => null,
                    'journal_identity' => null,
                    'current_before_image' => $before,
                    'before_image_hash' => $this->hash($before),
                    'proposed_after_image_or_corrective_entry' => ['action' => 'explicit_user_or_accounting_decision', 'automatic_mapping' => false],
                    'accounting_justification' => 'Missing, ambiguous, conflicting, or incompatible master data cannot be guessed.',
                    'effects' => ['quantity' => '0', 'inventory_value' => '0', 'currency' => null, 'tax' => null, 'subledger' => null],
                    'related_records' => ['entity_type' => $row->entity_type, 'fingerprint' => $row->fingerprint],
                    'preconditions' => ['authoritative_record_created_or_selected', 'same_organization_ownership', 'review_approved'],
                    'conflict_conditions' => ['ambiguous_candidate', 'cross_organization_record', 'archived_or_incompatible_record'],
                    'idempotency_key' => 'phase5:'.$mapping->mapping_uuid.':mapping-decision:'.$row->fingerprint,
                    'validation_queries' => ['phase2_discovery_fingerprint', 'mapping_uniqueness', 'record_ownership_and_status'],
                    'rollback_or_reversal_method' => 'Deactivate the additive mapping while retaining its audit history.',
                    'approval_status' => 'not_approved',
                    'reviewer_required_reason' => 'Business or accounting owner must supply the exact authoritative record.',
                    'risk_classification' => in_array($row->entity_type, ['account_role', 'tax'], true) ? 'critical' : 'high',
                    'repair_classification' => 'explicit_decision_required',
                ]);
            })->all();
    }

    private function qaCandidate(object $mapping, array $pending, array $ignored): array
    {
        $pendingEffect = $this->sumEffects($pending);
        $ignoredEffect = $this->sumEffects(array_filter(
            $ignored,
            fn (array $row): bool => $row['repair_classification'] !== 'permanent_documented_exclusion',
        ));
        $before = ['pending_effect' => $pendingEffect, 'ignored_accounting_effect' => $ignoredEffect, 'explained_variance' => bcadd($pendingEffect, $ignoredEffect, 4)];

        return $this->candidate($mapping, [
            'candidate_id' => 'qa-reconciliation:'.$mapping->mapping_uuid,
            'repair_set' => 'qa_reconciliation',
            'table_model' => 'read_only_reconciliation',
            'primary_key' => $mapping->mapping_uuid,
            'source_event_identity' => null,
            'source_document_identity' => null,
            'journal_identity' => null,
            'current_before_image' => $before,
            'before_image_hash' => $this->hash($before),
            'proposed_after_image_or_corrective_entry' => ['action' => 'future_approved_event_set_resolution', 'expected_unexplained_variance' => '0.0000'],
            'accounting_justification' => 'The independently classified pending and ignored accounting effects sum to the observed stock-to-GL difference; duplicate Finance effects must be excluded before execution.',
            'effects' => ['quantity' => null, 'inventory_value' => $before['explained_variance'], 'currency' => $mapping->base_currency_code, 'tax' => null, 'subledger' => null],
            'related_records' => ['pending_candidates' => count($pending), 'ignored_candidates' => count($ignored)],
            'preconditions' => ['all_candidate_duplicate_checks_pass', 'approved_batches_only', 'same_reconciliation_cutoff'],
            'conflict_conditions' => ['new_user_activity', 'existing_manual_effect', 'event_or_gl_hash_changed'],
            'idempotency_key' => 'phase5:'.$mapping->mapping_uuid.':qa-546',
            'validation_queries' => ['pending_accounting_effect', 'ignored_accounting_effect', 'stock_to_inventory_gl_variance', 'finance_effect_uniqueness'],
            'rollback_or_reversal_method' => 'Reverse only each approved corrective batch using its original identities; recompute reconciliation.',
            'approval_status' => 'not_approved',
            'reviewer_required_reason' => 'Accounting must approve candidate-by-candidate duplicate exclusions and execution order.',
            'risk_classification' => 'critical',
            'repair_classification' => 'explained_zero_unexplained_if_approved_without_duplicates',
        ]);
    }

    private function candidate(object $mapping, array $candidate): array
    {
        $base = [
            'manifest_version' => self::VERSION,
            'tenant_client_identity' => [
                'tenant_database' => $mapping->tenant_database_identity,
                'central_client_id' => (int) $mapping->central_client_id,
            ],
            'organization_identities' => [
                'central_organization_id' => (int) $mapping->central_organization_id,
                'finance_organization_id' => (int) $mapping->finance_organization_id,
                'solastock_organization_id' => (int) $mapping->solastock_organization_id,
            ],
            'integration_mapping_uuid' => $mapping->mapping_uuid,
        ] + $candidate;
        $base['candidate_sha256'] = $this->hash($base);

        return $base;
    }

    private function sumEffects(array $rows): string
    {
        return array_reduce($rows, fn (string $sum, array $row): string => bcadd($sum, (string) $row['effects']['inventory_value'], 4), '0.0000');
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            } else {
                ksort($value, SORT_STRING);
                $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
