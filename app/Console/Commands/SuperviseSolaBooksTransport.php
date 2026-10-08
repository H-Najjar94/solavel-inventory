<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Integration\TransportTargetIsolation;
use App\Services\Integration\TransportWorkerHeartbeat;
use App\Services\Purchasing\ReceiptHandoffService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use RuntimeException;

final class SuperviseSolaBooksTransport extends Command
{
    protected $signature = 'integration:transport-supervise
        {--once : Perform one allowlist cycle and exit}
        {--sleep=5 : Idle seconds between allowlist cycles}
        {--limit=25 : Maximum events per approved target per cycle}';

    protected $description = 'Server-owned Advanced/Enterprise Stock to Finance v2 worker';

    private bool $stop = false;

    public function handle(
        ApprovedTransportTargetRegistry $registry,
        TenantManager $tenants,
        OrganizationContext $organizations,
        DurableOutboxTransportService $transport,
        TransportWorkerHeartbeat $heartbeat,
        TransportTargetIsolation $isolation,
    ): int {
        if (! config('integration_transport.worker_enabled', false)) {
            throw new RuntimeException('Dedicated transport worker is disabled.');
        }
        $this->installSignals();
        $processed = 0;
        $lastState = null;
        do {
            $targets = $registry->targets();
            // Health reflects the durable per-target diagnostics: a target stays failing while
            // any of its stages is recorded (including while backing off) until it succeeds.
            $failing = $isolation->failingTargets($targets);
            $heartbeat->write(TransportWorkerHeartbeat::stateFor(count($targets), $failing), count($targets), $processed, $failing);
            foreach ($targets as $target) {
                if ($this->stop) {
                    break;
                }
                // One organization's failure must never stop delivery for the others:
                // each stage is isolated, diagnosed durably and backed off on its own.
                $mapping = $isolation->attempt($target, 'connection', function () use ($tenants, $target) {
                    $tenants->switchToDatabase($target['database']);

                    return IntegrationOrganizationMapping::query()
                        ->where('central_client_id', $target['client_id'])
                        ->where('central_organization_id', $target['organization_id'])
                        ->where('tenant_database_identity', $target['database'])
                        ->where('contract_version', SolaStockJournalContract::VERSION)
                        ->where('status', 'verified')
                        ->where('activation_state', 'active')
                        ->first();
                }, null);
                if (! $mapping) {
                    continue;
                }
                $organizations->set((int) $mapping->solastock_organization_id);
                try {
                    $limit = min(250, max(1, (int) $this->option('limit')));
                    $processed += (int) $isolation->attempt($target, 'party_sync', fn () => app(\App\Services\Integration\ContinuousPartySync::class)->process($mapping, 2));
                    $processed += (int) $isolation->attempt($target, 'catalog_sync', fn () => app(\App\Services\Catalog\DurableCatalogSync::class)->process($mapping, 2));
                    $processed += (int) $isolation->attempt($target, 'journal_outbox', function () use ($transport, $mapping, $limit): int {
                        $count = 0;
                        for ($i = 0; $i < $limit; $i++) {
                            $event = $transport->claim((int) $mapping->solastock_organization_id, gethostname().':'.getmypid());
                            if (! $event) {
                                break;
                            }
                            $transport->processClaim($event);
                            $count++;
                        }

                        return $count;
                    });
                    $processed += (int) $isolation->attempt($target, 'receipt_handoff', fn () => app(ReceiptHandoffService::class)->deliverDue(min(25, $limit)));
                    // Mixed-version tenants may not yet have the additive sales outbox.
                    $processed += (int) $isolation->attempt($target, 'sales_handoff', function (): int {
                        if (! \Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('sales_document_outbox')) {
                            return 0;
                        }

                        return app(\App\Services\Sales\ShipmentHandoffService::class)->deliverDue(1)
                            + app(\App\Services\Sales\SalesNotificationPublisher::class)->process(1)
                            + app(\App\Services\FinancialOrigins\CashNotificationPublisher::class)->process(1);
                    });
                    $processed += (int) $isolation->attempt($target, 'purchasing_notifications', fn () => app(\App\Services\Purchasing\PurchasingNotificationPublisher::class)->process(1));
                    $processed += (int) $isolation->attempt($target, 'document_incidents', fn () => app(\App\Services\Integration\DocumentIncidentNotificationPublisher::class)->process(1));
                } finally {
                    $organizations->forget();
                }
            }
            $failing = $isolation->failingTargets($targets);
            $state = TransportWorkerHeartbeat::stateFor(count($targets), $failing);
            $heartbeat->write($state, count($targets), $processed, $failing);
            if ($state !== $lastState) {
                // Logged on health transitions only (never per cycle); per-target detail is
                // logged once per backoff window by TransportTargetIsolation.
                \Illuminate\Support\Facades\Log::log(in_array($state, ['degraded', 'failing'], true) ? 'warning' : 'info',
                    'integration.transport.supervisor_health', [
                        'state' => $state,
                        'previous_state' => $lastState,
                        'approved_targets' => count($targets),
                        'failing_targets' => $failing,
                    ]);
                $lastState = $state;
            }
            if ($this->option('once') || $this->stop) {
                break;
            }
            sleep(min(60, max(1, (int) $this->option('sleep'))));
        } while (! $this->stop);

        $organizations->forget();
        $heartbeat->write('stopped', 0, $processed);

        return self::SUCCESS;
    }

    private function installSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop = true);
        pcntl_signal(SIGINT, fn () => $this->stop = true);
    }
}
