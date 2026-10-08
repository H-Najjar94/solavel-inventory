<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantCredentialDeriver;
use App\Services\Warehouses\DefaultWarehouseService;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class InitializeInventoryOrganization extends Command
{
    protected $signature = 'inventory:initialize-organization {client} {organization} {--apply}';

    protected $description = 'Initialize the first warehouse for one canonically entitled Stock organization';

    public function handle(DefaultWarehouseService $service, TenantCredentialDeriver $credentials): int
    {
        $client = (int) $this->argument('client');
        $organization = (int) $this->argument('organization');
        try {
            $registry = DB::connection(config('tenancy.central_connection', 'mysql'));
            $eligible = $registry->table('organizations as o')->join('clients as c', 'c.id', '=', 'o.client_id')
                ->where('o.id', $organization)->where('o.client_id', $client)->where('o.is_active', true)->where('c.is_active', true)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('organization_projects as a')->join('projects as p', 'p.id', '=', 'a.project_id')
                    ->whereColumn('a.organization_id', 'o.id')->where('a.is_active', true)->where('p.slug', 'inventory'))->exists();
            if ($client < 1 || $organization < 1 || ! $eligible) {
                throw new \RuntimeException('Organization is not canonically enabled.');
            }
            $derived = $credentials->deriveCredentials($client);
            $database = sprintf('tenant_%06d', $client);
            if ($derived['db_name'] !== $database) {
                throw new \RuntimeException('Canonical tenant mismatch.');
            }
            foreach (['database' => 'db_name', 'username' => 'db_user', 'password' => 'db_pass', 'host' => 'db_host', 'port' => 'db_port'] as $field => $source) {
                config(["database.connections.tenant.$field" => $derived[$source]]);
            }
            unset($derived);
            DB::purge('tenant');
            app(OrganizationContext::class)->set($organization);
            $result = $service->ensure($organization, (bool) $this->option('apply'));
            $this->line(json_encode($result + ['client_id' => $client, 'database' => $database], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(__('inventory.warehouse_setup.initialization_failed'));

            return self::FAILURE;
        }
    }
}
