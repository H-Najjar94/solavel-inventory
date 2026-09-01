<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Integration\TransportWorkerHeartbeat;
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
    ): int {
        if (! config('integration_transport.worker_enabled', false)) {
            throw new RuntimeException('Dedicated transport worker is disabled.');
        }
        $this->installSignals();
        $processed = 0;
        do {
            $targets = $registry->targets();
            $heartbeat->write($targets === [] ? 'idle' : 'running', count($targets), $processed);
            foreach ($targets as $target) {
                if ($this->stop) {
                    break;
                }
                $tenants->switchToDatabase($target['database']);
                $mapping = IntegrationOrganizationMapping::query()
                    ->where('central_client_id', $target['client_id'])
                    ->where('central_organization_id', $target['organization_id'])
                    ->where('tenant_database_identity', $target['database'])
                    ->where('contract_version', SolaStockJournalContract::VERSION)
                    ->where('status', 'verified')
                    ->where('activation_state', 'active')
                    ->first();
                if (! $mapping) {
                    continue;
                }
                $organizations->set((int) $mapping->solastock_organization_id);
                for ($i = 0, $limit = min(250, max(1, (int) $this->option('limit'))); $i < $limit; $i++) {
                    $event = $transport->claim((int) $mapping->solastock_organization_id, gethostname().':'.getmypid());
                    if (! $event) {
                        break;
                    }
                    $transport->processClaim($event);
                    $processed++;
                }
                $organizations->forget();
            }
            $heartbeat->write($targets === [] ? 'idle' : 'running', count($targets), $processed);
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
