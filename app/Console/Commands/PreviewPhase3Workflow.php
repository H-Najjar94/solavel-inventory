<?php

namespace App\Console\Commands;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\WorkflowPreviewService;
use App\Services\Integration\WorkflowMatchingService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PreviewPhase3Workflow extends Command
{
    protected $signature = 'integration:workflow-preview
        {--database= : Explicit tenant_XXXXXX database}
        {--organization= : SolaStock central organization ID}
        {--document-type= : Stable lifecycle document type}
        {--document-id= : Tenant-local source document ID}
        {--landed-cost-total= : Read-only landed-cost amount}
        {--landed-cost-allocated=0 : Amount allocated by this preview}
        {--allocation-method=value : quantity, weight, or value}';

    protected $description = 'Preview a Phase 3 purchasing or sales workflow without mutation';

    public function handle(
        TenantManager $tenants,
        OrganizationContext $organizations,
        WorkflowPreviewService $preview,
        WorkflowMatchingService $matching,
    ): int {
        $database = trim((string) $this->option('database'));
        $organization = (int) $this->option('organization');
        $type = trim((string) $this->option('document-type'));
        $id = (int) $this->option('document-id');
        $landedCost = $this->option('landed-cost-total');
        if (! preg_match('/^tenant_[0-9]{6}$/D', $database) || $organization <= 0
            || ($landedCost === null && ($type === '' || $id <= 0))) {
            throw new RuntimeException('Explicit tenant and organization plus a document identity or landed-cost preview are required.');
        }
        $tenants->switchToDatabase($database);
        $organizations->set($organization);
        if ($landedCost !== null && ! IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $organization)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', 'solastock-journal.v2')
            ->where('status', 'verified_hold')
            ->where('activation_state', 'maintenance_hold')
            ->exists()) {
            throw new RuntimeException('A verified held organization mapping is required.');
        }
        $report = $landedCost !== null
            ? [
                'schema_version' => 'phase3.landed-cost-preview.v1',
                'read_only' => true,
                ...$matching->landedCost(
                    (string) $landedCost,
                    (string) $this->option('landed-cost-allocated'),
                    (string) $this->option('allocation-method'),
                ),
            ]
            : $preview->preview($type, $id);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
