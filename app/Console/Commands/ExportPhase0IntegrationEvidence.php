<?php

namespace App\Console\Commands;

use App\Services\Integration\IntegrationEvents;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ExportPhase0IntegrationEvidence extends Command
{
    protected $signature = 'integration:phase0-export
        {--database= : Explicit tenant_XXXXXX database}
        {--output= : Permanent evidence directory outside Git}';

    protected $description = 'Export deterministic read-only SolaStock Phase 0 integration evidence';

    public function handle(TenantManager $tenants): int
    {
        $database = trim((string) $this->option('database'));
        $output = rtrim(trim((string) $this->option('output')), '/');
        if (! preg_match('/^tenant_\d{6}$/', $database) || $output === '') {
            $this->error('Use --database=tenant_XXXXXX --output=/approved/permanent/evidence/path.');

            return self::INVALID;
        }

        $tenants->switchToDatabase($database);
        foreach (['integration_outbox_events', 'integration_settings'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                $this->error("{$database}: required table {$table} is missing.");

                return self::FAILURE;
            }
        }
        if (! is_dir($output) && ! mkdir($output, 0750, true) && ! is_dir($output)) {
            $this->error("Cannot create {$output}.");

            return self::FAILURE;
        }

        $eventFiles = [];
        foreach (['pending', 'ignored'] as $status) {
            $rows = DB::connection('tenant')->table('integration_outbox_events')
                ->where('integration', 'solabooks')->where('status', $status)
                ->orderBy('organization_id')->orderBy('id')->get()
                ->filter(function ($event): bool {
                    $payload = json_decode((string) $event->payload, true) ?: [];

                    return IntegrationEvents::postsJournalForPayload((string) $event->event_type, $payload);
                })
                ->map(fn ($event) => $this->eventRow($database, $event))->all();
            $eventFiles[$status] = $this->writeJsonLines("{$output}/{$database}-{$status}-events.jsonl", $rows);
        }

        $connections = DB::connection('tenant')->table('integration_settings as s')
            ->leftJoin('organizations as o', 'o.central_org_id', '=', 's.organization_id')
            ->where('s.integration', 'solabooks')
            ->whereIn('s.mode', ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'])
            ->orderBy('s.organization_id')->get([
                's.id', 's.organization_id', 's.solabooks_organization_id', 's.mode',
                's.last_sync_at', 's.created_at', 's.updated_at', 'o.central_org_id',
            ])->map(function ($row) use ($database): array {
                $financeOrg = (int) $row->solabooks_organization_id;
                $balance = Schema::connection('tenant')->hasTable('inventory_items')
                    ? DB::connection('tenant')->table('inventory_items')
                        ->where('organization_id', $financeOrg)
                        ->selectRaw('COALESCE(SUM(CASE WHEN ABS(qty_on_hand) > 0.000001 OR ABS(qty_on_hand * COALESCE(NULLIF(avg_cost,0),average_cost,0)) > 0.000001 THEN 1 ELSE 0 END),0) records, COALESCE(SUM(qty_on_hand),0) quantity, COALESCE(SUM(qty_on_hand * COALESCE(NULLIF(avg_cost,0),average_cost,0)),0) value')
                        ->first()
                    : null;
                $writes = Schema::connection('tenant')->hasTable('inventory_movements')
                    ? DB::connection('tenant')->table('inventory_movements')->where('organization_id', $financeOrg)
                        ->selectRaw('COUNT(*) records, MIN(created_at) first_at, MAX(created_at) last_at')->first()
                    : null;

                return [
                    'tenant' => $database,
                    'client_id' => (int) substr($database, -6),
                    'connection_id' => (int) $row->id,
                    'inventory_organization_id' => (int) $row->organization_id,
                    'finance_organization_id' => $financeOrg,
                    'central_organization_id' => $row->central_org_id ? (int) $row->central_org_id : null,
                    'mode' => $row->mode,
                    'legacy_balance_records' => (int) ($balance->records ?? 0),
                    'legacy_quantity' => (string) ($balance->quantity ?? 0),
                    'legacy_value' => (string) ($balance->value ?? 0),
                    'legacy_write_records' => (int) ($writes->records ?? 0),
                    'legacy_first_write_at' => $writes->first_at ?? null,
                    'legacy_last_write_at' => $writes->last_at ?? null,
                    'last_successful_delivery_at' => $row->last_sync_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'reconciliation_classification' => 'connected_with_legacy_finance_inventory',
                ];
            })->filter(fn (array $row) => (float) $row['legacy_quantity'] !== 0.0
                || (float) $row['legacy_value'] !== 0.0
                || $row['legacy_write_records'] > 0)->values()->all();
        $connectionFile = $this->writeJsonLines("{$output}/{$database}-connected-legacy-inventory.jsonl", $connections);

        $manifest = [
            'schema_version' => 1,
            'tenant' => $database,
            'read_only' => true,
            'files' => [
                'pending' => $eventFiles['pending'],
                'ignored' => $eventFiles['ignored'],
                'connected_legacy_inventory' => $connectionFile,
            ],
            'explained_qa_difference' => [
                'pending_inventory_effect' => array_sum(array_map(fn ($r) => (float) $r['inventory_effect'], $this->readJsonLines($eventFiles['pending']['path']))),
                'ignored_inventory_effect' => array_sum(array_map(fn ($r) => (float) $r['inventory_effect'], $this->readJsonLines($eventFiles['ignored']['path']))),
            ],
        ];
        $manifest['explained_qa_difference']['total'] =
            $manifest['explained_qa_difference']['pending_inventory_effect']
            + $manifest['explained_qa_difference']['ignored_inventory_effect'];
        file_put_contents(
            "{$output}/{$database}-manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION).PHP_EOL
        );

        $this->line(json_encode($manifest, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function eventRow(string $database, object $event): array
    {
        $payload = json_decode((string) $event->payload, true) ?: [];
        $effect = (float) ($payload['total_inventory_value_change'] ?? 0);

        return [
            'tenant' => $database,
            'client_id' => (int) substr($database, -6),
            'event_id' => (int) $event->id,
            'event_uuid' => $event->event_uuid,
            'organization_id' => (int) $event->organization_id,
            'source_key' => $event->idempotency_key,
            'event_type' => $event->event_type,
            'journal_id' => $event->external_document_id ?: null,
            'document_reference' => $event->aggregate_number,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => (int) $event->aggregate_id,
            'currency' => $payload['currency'] ?? $payload['currency_code'] ?? null,
            'exchange_rate' => $payload['exchange_rate'] ?? null,
            'debit' => $effect > 0 ? abs($effect) : 0,
            'credit' => $effect < 0 ? abs($effect) : 0,
            'inventory_effect' => $effect,
            'status' => $event->status,
            'attempts' => (int) $event->attempts,
            'mapping_status' => $event->mapping_status,
            'occurred_at' => $event->occurred_at,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
            'sent_at' => $event->sent_at,
            'reconciliation_classification' => $event->status.'_accounting_event',
        ];
    }

    private function writeJsonLines(string $path, array $rows): array
    {
        $content = implode('', array_map(
            fn ($row) => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION).PHP_EOL,
            $rows
        ));
        file_put_contents($path, $content);

        return ['path' => $path, 'count' => count($rows), 'sha256' => hash('sha256', $content)];
    }

    private function readJsonLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }
}
