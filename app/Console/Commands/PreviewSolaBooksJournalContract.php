<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOutboxEvent;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Integration\SolaStockJournalContractBuilder;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PreviewSolaBooksJournalContract extends Command
{
    protected $signature = 'integration:contract-preview
        {--database= : Explicit tenant_XXXXXX database}
        {--organization= : SolaStock central organization ID}
        {--event= : Existing outbox event ID}';

    protected $description = 'Build a signed-journal v2 proposal without delivery or mutation';

    public function handle(TenantManager $tenants, OrganizationContext $organizations, SolaStockJournalContractBuilder $builder): int
    {
        $database = trim((string) $this->option('database'));
        $orgId = (int) $this->option('organization');
        $eventId = (int) $this->option('event');
        if (! preg_match('/^tenant_\d{6}$/', $database) || $orgId <= 0 || $eventId <= 0) {
            $this->error('--database=tenant_XXXXXX, --organization, and --event are required.');

            return self::INVALID;
        }
        $tenants->switchToDatabase($database);
        $organizations->set($orgId);
        $before = $this->snapshot($eventId, $orgId);
        try {
            $event = IntegrationOutboxEvent::query()->where('organization_id', $orgId)->findOrFail($eventId);
            $payload = $builder->build($event);
            $report = [
                'valid' => true,
                'contract_version' => $payload['contract_version'],
                'payload_hash' => SolaStockJournalContract::payloadHash($payload),
                'payload' => $payload,
                'mutation' => ['performed' => false, 'before' => $before, 'after' => $this->snapshot($eventId, $orgId)],
            ];
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line(json_encode([
                'valid' => false,
                'error' => ['code' => 'contract_preview_failed', 'message' => $e->getMessage()],
                'mutation' => ['performed' => false, 'before' => $before, 'after' => $this->snapshot($eventId, $orgId)],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }

    private function snapshot(int $eventId, int $orgId): array
    {
        $event = DB::connection('tenant')->table('integration_outbox_events')
            ->where('organization_id', $orgId)->where('id', $eventId)
            ->first(['status', 'attempts', 'next_attempt_at', 'sent_at', 'updated_at']);

        return [
            'event' => $event,
            'outbox_count' => DB::connection('tenant')->table('integration_outbox_events')->where('organization_id', $orgId)->count(),
        ];
    }
}
