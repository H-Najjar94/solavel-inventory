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
                $setting = IntegrationSetting::query()->where('organization_id', $organization)
                    ->where('integration', IntegrationEvents::INTEGRATION)->first();
                if (! $setting) {
                    return [$organization, 'disconnected', '-', 'n/a', 'not connected'];
                }
                $stored = data_get($setting->meta, 'transport_enabled_workflows');
                $enabled = data_get($setting->meta, 'transport_enabled') === true;
                $has = is_array($stored) && in_array('sales_return.reversed', $stored, true);
                $action = match (true) {
                    $setting->mode !== 'active' => 'none (connection not active)',
                    ! is_array($stored) => 'none (no stored list; default scope applies)',
                    $has => 'none',
                    default => 'ADD sales_return.reversed (via the connection wizard run, not by hand)',
                };

                return [$organization, $setting->mode, $enabled ? 'yes' : 'no', is_array($stored) ? ($has ? 'present' : 'MISSING') : 'default', $action];
            });
        }
        $this->table(['org', 'mode', 'transport', 'sales_return.reversed', 'rollout action'], $rows);
        $this->info('Read-only. Nothing was changed.');

        return self::SUCCESS;
    }
}
