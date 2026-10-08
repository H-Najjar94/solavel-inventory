<?php

namespace App\Console\Commands;

use App\Services\Integration\Phase5RepairApplyGuard;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

final class ApplyPhase5HistoricalRepair extends Command
{
    protected $signature = 'integration:phase5-repair
        {--manifest= : Absolute immutable manifest path}
        {--manifest-sha256= : Exact approved SHA-256}
        {--tenant-database= : Exact tenant_XXXXXX database}
        {--organization= : Exact SolaStock organization}
        {--batch= : Explicit repair batch identifier}
        {--approval= : External approval identifier}
        {--snapshot= : Database backup/snapshot reference}
        {--apply : Request guarded apply mode}';

    protected $description = 'Guarded Phase 5 repair runner (apply is compiled off in Phase 5A)';

    public function handle(TenantManager $tenants, Phase5RepairApplyGuard $guard): int
    {
        if (! $this->option('apply')) {
            $this->info('DRY RUN ONLY: use integration:phase5a-manifest to generate immutable candidates.');

            return self::SUCCESS;
        }

        $database = trim((string) $this->option('tenant-database'));
        $organization = (int) $this->option('organization');
        $validDatabase = preg_match('/^tenant_[0-9]{6}$/D', $database)
            || (app()->environment('testing') && preg_match('/^solastock_test_[a-z0-9_]+$/D', $database));
        if (! $validDatabase || $organization <= 0) {
            $this->error('APPLY_ABORTED: exact tenant and organization are required.');

            return self::FAILURE;
        }
        $code = $tenants->runForTenant($organization, fn (): string => $guard->rejectAndAudit([
            'manifest' => $this->option('manifest'),
            'manifest_sha256' => strtolower((string) $this->option('manifest-sha256')),
            'organization' => $organization,
            'batch' => $this->option('batch'),
            'approval' => $this->option('approval'),
            'snapshot' => $this->option('snapshot'),
        ]), $database);
        $this->error(strtoupper($code).': no repair was applied; the abort was audited.');

        return self::FAILURE;
    }
}
