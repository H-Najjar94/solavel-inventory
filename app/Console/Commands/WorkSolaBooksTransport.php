<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\IntegrationSafetyHold;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WorkSolaBooksTransport extends Command
{
    protected $signature = 'integration:transport-work
        {--database= : Explicit tenant_XXXXXX database}
        {--once : Exit after one claim cycle}
        {--limit=25 : Maximum events before graceful exit}
        {--worker-id= : Stable process identity}';

    protected $description = 'Dedicated solastock-finance-v2 durable outbox worker';

    private bool $stop = false;

    public function handle(
        TenantManager $tenants,
        OrganizationContext $organizations,
        IntegrationSafetyHold $safety,
        DurableOutboxTransportService $transport,
    ): int {
        $database = trim((string) $this->option('database'));
        if (! preg_match('/^tenant_[0-9]{6}$/D', $database)) {
            throw new RuntimeException('An explicit tenant_XXXXXX database is required.');
        }
        // Fail before tenant switching, querying, claiming, attempts, or HTTP.
        $safety->assertUatDatabaseEnabled($database);
        if (! config('integration_transport.worker_enabled', false)
            && ! $safety->workerEnabledFor(
                (int) config('integration_safety.phase6a_uat.organization_id', 0),
                $database,
            )) {
            throw new RuntimeException('Dedicated transport worker is disabled.');
        }
        $tenants->switchToDatabase($database);
        $this->installSignalHandlers();
        $workerId = trim((string) $this->option('worker-id'))
            ?: gethostname().':'.getmypid();
        $limit = min(250, max(1, (int) $this->option('limit')));
        $processed = 0;
        $this->heartbeat($workerId, 'running', $processed, true);
        while (! $this->stop && $processed < $limit) {
            $this->heartbeat($workerId, 'running', $processed);
            $organizationIds = IntegrationOrganizationMapping::query()
                ->where('contract_version', 'solastock-journal.v2')
                ->where('status', 'verified_hold')
                ->where('activation_state', 'maintenance_hold')
                ->orderBy('solastock_organization_id')
                ->pluck('solastock_organization_id');
            $claimedAny = false;
            foreach ($organizationIds as $organizationId) {
                if ($this->stop) {
                    break;
                }
                $organizations->set((int) $organizationId);
                try {
                    $transport->recoverExpiredLeases((int) $organizationId);
                    $event = $transport->claim((int) $organizationId, $workerId);
                } catch (RuntimeException $exception) {
                    $this->warn(json_encode([
                        'organization_id' => (int) $organizationId,
                        'state' => 'blocked',
                        'safe_error' => mb_substr($exception->getMessage(), 0, 300),
                    ], JSON_UNESCAPED_SLASHES));

                    continue;
                }
                if (! $event) {
                    continue;
                }
                $claimedAny = true;
                $result = $transport->processClaim($event);
                $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
                $processed++;
                if ($processed >= $limit) {
                    break;
                }
            }
            $organizations->forget();
            if ($this->option('once') || ! $claimedAny) {
                break;
            }
        }
        $this->heartbeat($workerId, 'stopped', $processed);

        return self::SUCCESS;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop = true);
        pcntl_signal(SIGINT, fn () => $this->stop = true);
    }

    private function heartbeat(string $workerId, string $state, int $processed, bool $starting = false): void
    {
        $existing = DB::connection('tenant')->table('integration_transport_worker_heartbeats')
            ->where('worker_id', $workerId)->first();
        DB::connection('tenant')->table('integration_transport_worker_heartbeats')->updateOrInsert(
            ['worker_id' => mb_substr($workerId, 0, 120)],
            [
                'queue_name' => (string) config('integration_transport.worker.queue'),
                'state' => $state,
                'processed_count' => $processed,
                'started_at' => $starting || ! $existing ? now() : $existing->started_at,
                'last_seen_at' => now(),
                'stopped_at' => $state === 'stopped' ? now() : null,
                'served_commit' => trim((string) @file_get_contents(base_path('RELEASE_SHA'))) ?: null,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
    }
}
