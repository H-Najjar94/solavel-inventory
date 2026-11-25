<?php

namespace App\Console\Commands;

use App\Services\Integration\IgnoredReceiptEventReissue;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;

class ReissueIgnoredReceiptEvent extends Command
{
    protected $signature = 'integration:reissue-ignored-receipt
        {--client= : Central client id (tenant)}
        {--organization= : SolaStock organization id}
        {--event= : Ignored grn.posted outbox event id}
        {--actor= : SolaStock user id recorded in the audit}
        {--apply : Commit; without it the rebuild is validated and rolled back}';

    protected $description = 'Re-issue ONE ignored grn.posted accounting event under the current contract (same key, audited, retry-safe)';

    public function handle(TenantManager $tenants, OrganizationContext $context, IgnoredReceiptEventReissue $reissue): int
    {
        $tenants->useTenant((int) $this->option('client'));
        $context->set((int) $this->option('organization'));
        $result = $reissue->reissue((int) $this->option('organization'), (int) $this->option('event'),
            $this->option('actor') ? (int) $this->option('actor') : null, (bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
