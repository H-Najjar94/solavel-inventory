<?php

namespace App\Console\Commands;

use App\Services\Integration\IntegrationReconciliationService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use RuntimeException;

final class ReconcileSolaBooksIntegration extends Command
{
    protected $signature = 'integration:reconcile
        {--database= : Explicit tenant_XXXXXX database}
        {--organization= : SolaStock organization ID}';

    protected $description = 'Read-only SolaStock/Finance reconciliation without repair';

    public function handle(
        TenantManager $tenants,
        OrganizationContext $organizations,
        IntegrationReconciliationService $service,
    ): int {
        $database = trim((string) $this->option('database'));
        $organization = (int) $this->option('organization');
        if (! preg_match('/^tenant_[0-9]{6}$/D', $database) || $organization <= 0) {
            throw new RuntimeException('Explicit tenant and organization are required.');
        }
        $tenants->switchToDatabase($database);
        $organizations->set($organization);
        $this->line(json_encode(
            $service->report($organization),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
