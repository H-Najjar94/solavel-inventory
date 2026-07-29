<?php

namespace App\Console\Commands;

use App\Services\Integration\Phase2MappingDiscoveryService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use RuntimeException;

final class DiscoverPhase2Mappings extends Command
{
    protected $signature = 'integration:phase2-discover
        {--organization-mapping= : Immutable organization mapping UUID}
        {--tenant-database= : Explicit tenant_XXXXXX database for an operator run}
        {--solastock-organization= : SolaStock organization ID for the tenant context}
        {--output= : Absolute JSON report path}
        {--apply : Apply deterministic exact one-to-one mappings}
        {--approved-manifest-hash= : Manifest hash from a reviewed read-only run}
        {--actor= : Optional approving user ID}';

    protected $description = 'Read-only Phase 2 mapping discovery; optionally apply an approved deterministic manifest';

    public function handle(Phase2MappingDiscoveryService $service, TenantManager $tenants): int
    {
        $mappingUuid = trim((string) $this->option('organization-mapping'));
        if ($mappingUuid === '') {
            $this->error('--organization-mapping is required.');
            return self::FAILURE;
        }

        $operation = fn () => $this->option('apply')
            ? $service->applyDeterministic(
                $mappingUuid,
                strtolower(trim((string) $this->option('approved-manifest-hash'))),
                $this->option('actor') ? (int) $this->option('actor') : null,
            )
            : $service->discover($mappingUuid);
        $tenantDatabase = trim((string) $this->option('tenant-database'));
        $solaStockOrganization = (int) $this->option('solastock-organization');
        if (($tenantDatabase === '') !== ($solaStockOrganization <= 0)) {
            throw new RuntimeException('--tenant-database and --solastock-organization must be supplied together.');
        }
        if ($tenantDatabase !== '' && ! preg_match('/^tenant_[0-9]{6}$/D', $tenantDatabase)) {
            throw new RuntimeException('--tenant-database must use the tenant_XXXXXX format.');
        }
        $result = $tenantDatabase === ''
            ? $operation()
            : $tenants->runForTenant($solaStockOrganization, $operation, $tenantDatabase);

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $output = trim((string) $this->option('output'));
        if ($output !== '') {
            if (! str_starts_with($output, '/')) {
                throw new RuntimeException('--output must be an absolute path outside the Git worktree.');
            }
            $directory = dirname($output);
            if (! is_dir($directory) || ! is_writable($directory)) {
                throw new RuntimeException('The output directory must already exist and be writable.');
            }
            $resolvedDirectory = realpath($directory);
            $resolvedWorktree = realpath(base_path());
            if ($resolvedDirectory === $resolvedWorktree
                || str_starts_with((string) $resolvedDirectory, (string) $resolvedWorktree . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('--output must be outside the Git worktree.');
            }
            if (file_put_contents($output, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the mapping report.');
            }
            chmod($output, 0640);
            $this->info('Report written with SHA-256 ' . hash('sha256', $json));
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
