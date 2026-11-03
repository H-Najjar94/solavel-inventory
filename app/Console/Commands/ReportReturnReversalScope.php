<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\IntegrationEvents;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * READ-ONLY rollout planning. For one exact organization, prints its SolaBooks connection mode and whether the
 * stored transport_enabled_workflows contains sales_return.reversed. Organizations that never stored an explicit
 * list fall back to the (now complete) default reviewed scope. Changes nothing.
 */
final class ReportReturnReversalScope extends Command
{
    protected $signature = 'returns:report-reversal-scope {--organization=* : Exact SolaStock organization id (repeatable)}';

    protected $description = 'Read-only: per organization, is sales_return.reversed in transport_enabled_workflows';

    public function handle(TenantManager $tenants): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('organization')), fn ($id) => $id > 0)));
        if ($ids === []) {
            $this->error('At least one exact --organization is required.');

            return self::FAILURE;
        }
        $rows = [];
        foreach ($ids as $organization) {
            $rows[] = $tenants->runForTenant($organization, function () use ($organization): array {
                $r = app(\App\Services\Integration\ReturnReversalReadiness::class)->forOrganization($organization);
                $action = match ($r['state']) {
                    'not_connected' => 'not connected',
                    'connection_inactive' => 'none (connection not active)',
                    'needs_wizard' => 'ADD sales_return.reversed (via the connection wizard run, not by hand)',
                    default => $r['scope'] === 'default' ? 'none (no stored list; default scope applies)' : 'none',
                };

                return [$organization, $r['mode'] ?? 'disconnected', $r['mode'] ? ($r['transport_enabled'] ? 'yes' : 'no') : '-',
                    ['present' => 'present', 'missing' => 'MISSING', 'default' => 'default', 'n/a' => 'n/a'][$r['scope']], $action];
            });
        }
        $this->table(['org', 'mode', 'transport', 'sales_return.reversed', 'rollout action'], $rows);
        $this->info('Read-only. Nothing was changed.');

        return self::SUCCESS;
    }
}
