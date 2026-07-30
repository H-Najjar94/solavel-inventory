<?php

namespace App\Console\Commands;

use App\Services\Integration\Phase5aManifestService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use RuntimeException;

final class GeneratePhase5aManifest extends Command
{
    protected $signature = 'integration:phase5a-manifest
        {--tenant-database= : Exact tenant_XXXXXX database}
        {--solastock-organization= : Exact SolaStock organization}
        {--organization-mapping= : Immutable organization mapping UUID}
        {--output= : Permanent evidence directory outside Git}';

    protected $description = 'Generate deterministic read-only Phase 5A historical repair manifests';

    public function handle(Phase5aManifestService $service, TenantManager $tenants): int
    {
        $database = trim((string) $this->option('tenant-database'));
        $organization = (int) $this->option('solastock-organization');
        $mapping = trim((string) $this->option('organization-mapping'));
        $output = rtrim(trim((string) $this->option('output')), '/');
        if (! preg_match('/^tenant_[0-9]{6}$/D', $database) || $organization <= 0 || $mapping === '' || ! str_starts_with($output, '/')) {
            throw new RuntimeException('Exact tenant, organization, mapping UUID, and absolute output path are required.');
        }
        $resolvedWorktree = realpath(base_path());
        $parent = realpath(dirname($output));
        if ($parent && ($parent === $resolvedWorktree || str_starts_with($parent, $resolvedWorktree.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Evidence output must be outside Git.');
        }

        $result = $tenants->runForTenant(
            $organization,
            fn (): array => $service->generate($mapping, $output),
            $database,
        );
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
