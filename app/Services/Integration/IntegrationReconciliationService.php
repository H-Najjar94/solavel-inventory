<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IntegrationReconciliationService
{
    public function report(int $organizationId): array
    {
        $db = DB::connection('tenant');
        $financeOrganizationId = Schema::connection('tenant')->hasTable('integration_organization_mappings')
            ? (int) ($db->table('integration_organization_mappings')
                ->where('solastock_organization_id', $organizationId)
                ->whereIn('status', ['verified', 'verified_hold'])
                ->value('finance_organization_id') ?: $organizationId)
            : $organizationId;
        $events = $db->table('integration_outbox_events')
            ->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->orderBy('id')->get();
        $hasJournals = Schema::connection('tenant')->hasTable('journal_entries');
        $rows = $events->map(function (object $event) use ($db, $financeOrganizationId, $hasJournals): array {
            $journal = $hasJournals
                ? $db->table('journal_entries')
                    ->where('organization_id', $financeOrganizationId)
                    ->where('source_key', 'external-api:'.hash('sha256', (string) $event->idempotency_key))
                    ->first(['id'])
                : null;

            return [
                'event_id' => (int) $event->id,
                'event_uuid' => (string) $event->event_uuid,
                'workflow' => (string) $event->event_type,
                'document_type' => (string) $event->aggregate_type,
                'document_id' => (string) $event->aggregate_id,
                'status' => (string) $event->status,
                'journal_id' => $journal?->id,
                'classification' => $this->eventClassification($event, $journal),
            ];
        })->all();

        return [
            'schema_version' => 'phase4.reconciliation.v1',
            'read_only' => true,
            'organization_id' => $organizationId,
            'finance_organization_id' => $financeOrganizationId,
            'generated_at' => now()->toIso8601String(),
            'inventory' => $this->inventoryChecks($organizationId),
            'events' => [
                'counts' => collect($rows)->countBy('classification')->sortKeys()->all(),
                'rows' => $rows,
            ],
            'workflows' => $this->workflowChecks($organizationId),
            'mutation' => [
                'events' => 0, 'journals' => 0, 'documents' => 0,
                'inventory' => 0, 'balances' => 0, 'mappings' => 0,
            ],
        ];
    }

    private function inventoryChecks(int $organizationId): array
    {
        $db = DB::connection('tenant');
        $balances = Schema::connection('tenant')->hasTable('stock_balances')
            ? $db->table('stock_balances')->where('organization_id', $organizationId)
                ->selectRaw('COUNT(*) rows_count, COALESCE(SUM(on_hand_qty),0) quantity')
                ->selectRaw('COALESCE(SUM(total_value),0) value')->first()
            : null;
        $ledger = Schema::connection('tenant')->hasTable('stock_ledger')
            ? $db->table('stock_ledger')->where('organization_id', $organizationId)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0) quantity")
                ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN total_cost ELSE -total_cost END),0) value")->first()
            : null;
        $layers = Schema::connection('tenant')->hasTable('cost_layers')
            ? $db->table('cost_layers')->where('organization_id', $organizationId)
                ->selectRaw('COALESCE(SUM(remaining_qty),0) quantity')
                ->selectRaw('COALESCE(SUM(remaining_qty * unit_cost),0) value')->first()
            : null;

        return [
            'stock_balance_quantity' => (string) ($balances->quantity ?? '0'),
            'stock_balance_value' => (string) ($balances->value ?? '0'),
            'stock_ledger_quantity' => (string) ($ledger->quantity ?? '0'),
            'stock_ledger_value' => (string) ($ledger->value ?? '0'),
            'valuation_layer_quantity' => (string) ($layers->quantity ?? '0'),
            'valuation_layer_value' => (string) ($layers->value ?? '0'),
            'quantity_classification' => (string) ($balances->quantity ?? '0') === (string) ($ledger->quantity ?? '0')
                ? 'reconciled' : 'amount_mismatch',
            'valuation_classification' => $layers === null ? 'unexplained'
                : ((string) $layers->quantity === (string) ($balances->quantity ?? '0')
                    ? 'reconciled' : 'amount_mismatch'),
        ];
    }

    private function workflowChecks(int $organizationId): array
    {
        if (! Schema::connection('tenant')->hasTable('integration_document_lifecycle_mappings')) {
            return ['counts' => [], 'rows' => []];
        }
        $rows = DB::connection('tenant')->table('integration_document_lifecycle_mappings')
            ->where('solastock_organization_id', $organizationId)
            ->orderBy('id')
            ->get(['mapping_uuid', 'source_document_type', 'source_document_id', 'matching_state',
                'ordered_qty', 'reserved_qty', 'received_qty', 'billed_qty',
                'shipped_qty', 'invoiced_qty', 'returned_qty', 'conflict_code'])
            ->map(fn (object $row): array => [
                ...((array) $row),
                'classification' => $row->conflict_code ? 'mapping_required'
                    : match ($row->matching_state) {
                        'matched' => 'reconciled',
                        'partially_matched' => 'timing',
                        'conflict' => 'identity_mismatch',
                        default => 'pending',
                    },
            ])->all();

        return ['counts' => collect($rows)->countBy('classification')->sortKeys()->all(), 'rows' => $rows];
    }

    private function eventClassification(object $event, ?object $journal): string
    {
        return match (true) {
            $event->status === 'ignored' => 'ignored_historical',
            in_array($event->status, ['pending', 'ready', 'processing', 'retry_scheduled'], true) => $event->status === 'pending' ? 'pending' : 'timing',
            in_array($event->status, ['review_required', 'blocked_mapping'], true) => 'mapping_required',
            $event->status === 'blocked_contract' => 'currency_mismatch',
            in_array($event->status, ['failed', 'dead_letter'], true) => 'delivery_failed',
            $event->status === 'sent' && ! $journal => 'missing_finance_record',
            $journal !== null => 'reconciled',
            default => 'unexplained',
        };
    }
}
