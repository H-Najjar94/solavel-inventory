<?php

namespace App\Services\FinancialOrigins;

use App\Models\Tenant\IntegrationSetting;
use App\Services\Purchasing\ReceivingRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Read-only versioned support; schema installation never enables a financial source. */
final class FinancialOriginCapabilities
{
    public const CONTRACT = 'financial-origin.v1';
    public const CORE_VERSION = 'financial-origins-core-1';

    private const REQUIRED = [
        'stock_financial_origin_requests' => ['organization_id', 'organization_mapping_uuid', 'request_uuid', 'source_document_type', 'source_document_id', 'source_journal_id', 'source_revision'],
        'stock_financial_origin_lines' => ['request_id', 'source_document_line_id', 'fulfilled_quantity', 'cancelled_quantity', 'unit_conversion_factor'],
        'stock_financial_origin_commands' => ['operation_uuid', 'request_uuid', 'source_journal_id', 'actor_id', 'payload_hash', 'status'],
        'stock_financial_origin_outbox' => ['operation_uuid', 'source_document_type', 'payload_hash', 'payload'],
        'purchase_valuation_holds' => ['source_document_type', 'source_document_id', 'source_journal_id'],
        'finance_document_requests' => ['organization_id', 'organization_mapping_uuid', 'request_uuid', 'source_document_type', 'source_document_id', 'source_journal_id', 'source_revision', 'command_central_actor_id', 'closing_source_journal_id'],
        'finance_document_positions' => ['request_uuid', 'position_uuid', 'source_document_line_id', 'booked_acquisition_base', 'snapshot'],
        'finance_document_matches' => ['operation_uuid', 'position_uuid', 'physical_journal_id', 'value_plan', 'reversal_generation', 'closure_snapshot', 'release_snapshot'],
        'finance_document_physical_events' => ['event_uuid', 'source_hash', 'physical_document_id', 'state'],
        'finance_document_reverse_generations' => ['organization_id', 'operation_uuid', 'generation', 'reversal_operation_uuid', 'reverse_quote', 'release_snapshot', 'released_at'],
        'finance_document_physical_operations' => ['operation_uuid', 'request_uuid', 'state'],
    ];

    public function inspect(int $financeOrganizationId): array
    {
        $mapping = app(ReceivingRequestService::class)->mapping();
        abort_unless($financeOrganizationId > 0 && (int) $mapping->finance_organization_id === $financeOrganizationId
            && (int) $mapping->solastock_organization_id === app(OrganizationContext::class)->idOrFail()
            && $mapping->contract_version === 'solastock-journal.v2', 403);
        $setting = IntegrationSetting::query()->where('integration', 'solabooks')
            ->where('solabooks_organization_id', $financeOrganizationId)->first();
        abort_unless($setting && $setting->mode === 'active', 409, 'workspace_connection_not_ready');
        // Two schema queries, bound to the actual tenant database; names alone are not index proof.
        $db = DB::connection('tenant');
        $columns = $db->table('information_schema.columns')->where('table_schema', $db->getDatabaseName())
            ->whereIn('table_name', array_keys(self::REQUIRED))->get(['table_name', 'column_name']);
        $present = [];
        foreach ($columns as $column) $present[$column->table_name][$column->column_name] = true;
        $ready = true;
        foreach (self::REQUIRED as $table => $required) {
            foreach ($required as $column) if (! isset($present[$table][$column])) { $ready = false; break 2; }
        }
        $rows = $db->table('information_schema.statistics')->where('table_schema', $db->getDatabaseName())
            ->where('table_name', 'finance_document_reverse_generations')->orderBy('seq_in_index')
            ->get(['index_name', 'non_unique', 'column_name']);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->index_name]['unique'] = (int) $row->non_unique === 0;
            $indexes[$row->index_name]['columns'][] = $row->column_name;
        }
        foreach ([
            'fin_origin_reverse_uuid_unique' => ['unique' => true, 'columns' => ['reversal_operation_uuid']],
            'fin_origin_reverse_generation_unique' => ['unique' => true, 'columns' => ['organization_id', 'operation_uuid', 'generation']],
            'fin_origin_reverse_request_idx' => ['unique' => false, 'columns' => ['organization_id', 'request_uuid']],
        ] as $name => $definition) {
            if (($indexes[$name] ?? null) !== $definition) $ready = false;
        }
        $cashReady = app(\App\Services\Integration\Cash219SchemaReadiness::class)->ready();
        $supported = [];
        if ($ready && config('integration_safety.financial_origin_expense_handoff_enabled', false) === true) $supported[] = 'expense';
        if ($ready && $cashReady && config('integration_safety.financial_origin_cash_handoff_enabled', false) === true) $supported[] = 'sales_receipt';
        return [
            'contract_version' => self::CONTRACT,
            'supported_source_document_types' => $supported,
            'cash_schema_ready' => $cashReady,
            'cash_contract_version' => \App\Services\Integration\Cash219SchemaReadiness::VERSION,
            'schema_ready' => $ready,
            'finance_core_version' => self::CORE_VERSION, 'stock_core_version' => self::CORE_VERSION,
            'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => (int) $mapping->central_client_id,
            'central_organization_id' => (int) $mapping->central_organization_id,
            'finance_organization_id' => (int) $mapping->finance_organization_id,
            'solastock_organization_id' => (int) $mapping->solastock_organization_id,
        ];
    }

    public function assertSourceSupported(string $type, int $financeOrganizationId): void
    {
        abort_unless(in_array($type, $this->inspect($financeOrganizationId)['supported_source_document_types'], true),
            409, 'financial_origin_source_not_supported');
    }
}
