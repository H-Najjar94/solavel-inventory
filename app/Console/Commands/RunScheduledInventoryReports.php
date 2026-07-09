<?php

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReportRunner;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

class RunScheduledInventoryReports extends Command
{
    protected $signature = 'inventory:reports:run-due
        {--org= : Organization id whose tenant DB will be activated}
        {--database= : Explicit tenant database override for QA/canary}
        {--limit=25 : Maximum due schedules to run for this org}';

    protected $description = 'Run due SolaStock scheduled reports for one explicit tenant/org';

    public function handle(TenantManager $tenants, ScheduledReportRunner $runner): int
    {
        $org = (int) $this->option('org');
        if ($org <= 0) {
            $this->error('Provide --org=<organization id>.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $tenants->useTenant($org, $this->option('database') ?: null);

        $result = $runner->runDue($limit > 0 ? $limit : 25);
        $this->info('Checked: '.$result['checked']);
        $this->info('Ran: '.$result['ran']);
        $this->info('Failed: '.$result['failed']);

        foreach ($result['results'] as $row) {
            $line = "  - #{$row['id']}: {$row['status']}";
            if ($row['error']) {
                $line .= ' ('.$row['error'].')';
            }
            $this->line($line);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
