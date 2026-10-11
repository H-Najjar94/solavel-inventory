<?php

namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\InventorySetting;
use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationStatusService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class IntegrationConnectionConfigurationTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function paired_nonproduction_status_accepts_the_scoped_supervisor_proof(): void
    {
        $this->useTenantA();
        \App\Models\Tenant\IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'central_client_id' => 7,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => \Illuminate\Support\Facades\DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 41,
            'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'AED',
        ]);
        $proof = new class {
            public bool $running = true;
            public function runningFor(int $clientId, int $organizationId): bool
            {
                return $this->running && $clientId === 7 && $organizationId === TenantTestManager::ORG_A;
            }
        };
        $this->app->instance(\App\Services\Integration\TransportWorkerHeartbeat::class, $proof);
        $this->assertTrue(app(IntegrationStatusService::class)->status(TenantTestManager::ORG_A)['transport']['worker_running']);
        $proof->running = false;
        $this->assertFalse(app(IntegrationStatusService::class)->status(TenantTestManager::ORG_A)['transport']['worker_running']);
    }

    #[Test]
    public function reports_use_only_the_verified_currency_of_the_scoped_mapping(): void
    {
        $this->useTenantA();
        $mapping = \App\Models\Tenant\IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => \Illuminate\Support\Facades\DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 41, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active',
            'base_currency_code' => 'AED', 'currency_verified_at' => now(),
        ]);
        $reports = app(\App\Services\Reports\InventoryReportService::class);
        $this->assertSame('AED', $reports->baseCurrency());
        app(\App\Tenancy\OrganizationContext::class)->set(TenantTestManager::ORG_A + 100);
        $this->assertNotSame('AED', $reports->baseCurrency());
        app(\App\Tenancy\OrganizationContext::class)->set(TenantTestManager::ORG_A);
        $mapping->update(['currency_verified_at' => null]);
        $this->assertNotSame('AED', $reports->baseCurrency());
    }

    #[Test]
    public function standalone_schema_without_finance_accounts_has_no_valid_mappings(): void
    {
        $this->useTenantA();
        $database = \Illuminate\Support\Facades\DB::getFacadeRoot();
        $builder = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        $builder->shouldReceive('hasTable')->once()->with('accounts')->andReturn(false);
        $connection = \Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($builder);
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->once()->with('tenant')->andReturn($connection);
        try {
            $this->assertSame([], app(\App\Services\Integration\OrganizationAccountRequirements::class)->validMappedRoles(TenantTestManager::ORG_A));
        } finally {
            \Illuminate\Support\Facades\DB::swap($database);
        }
    }

    #[Test]
    public function account_mapping_details_use_the_configured_workflow_roles(): void
    {
        $this->useTenantA();
        IntegrationSetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A, 'integration' => IntegrationEvents::INTEGRATION],
            ['mode' => 'connected_pending_mapping', 'meta' => ['transport_enabled_workflows' => ['grn.posted']]],
        );
        $expected = app(\App\Services\Integration\OrganizationAccountRequirements::class)->roles(TenantTestManager::ORG_A);
        $this->assertNotEmpty($expected);
        $response = app(IntegrationController::class)->accountMappings();
        $this->assertSame(200, $response->getStatusCode());
        // The current default contract also provisions internal-consumption expense mapping.
        $this->assertSame(array_values(array_unique([...$expected, 'internal_consumption_expense'])), array_column($response->getData(true)['data']['mappings'], 'mapping_type'));
        IntegrationSetting::query()->where('integration', IntegrationEvents::INTEGRATION)
            ->firstOrFail()->update(['meta' => ['transport_enabled_workflows' => []]]);
        $this->assertSame(['internal_consumption_expense'], array_column(app(IntegrationController::class)->accountMappings()->getData(true)['data']['mappings'], 'mapping_type'));
    }

    #[Test]
    public function an_organization_row_without_an_immutable_mapping_is_not_reported_as_connected(): void
    {
        $this->useTenantA();
        IntegrationSetting::query()->where('integration', IntegrationEvents::INTEGRATION)->delete();
        $financeOrgId = 41;
        $service = new class($financeOrgId) extends IntegrationStatusService {
            public function __construct(private int $financeOrgId) {}

            protected function sharedFinanceOrganizationId(int $orgId): ?int
            {
                return $this->financeOrgId;
            }
        };

        $status = $service->status(TenantTestManager::ORG_A);

        $this->assertSame('disconnected', $status['mode']);
        $this->assertFalse($status['workspace_connected']);
        $this->assertNull($status['solabooks_organization_id']);
        $this->assertFalse($status['connection_implemented']);
        $this->assertFalse($status['delivery_configured']);
    }

    #[Test]
    public function owner_configuration_encrypts_the_key_and_status_never_returns_it(): void
    {
        $this->useTenantA();
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        $plainKey = 'solabooks_test.'.str_repeat('x', 40);
        $response = app(IntegrationController::class)->configure(Request::create('/integration/solabooks/connection', 'PUT', [
            'mode' => 'connected_pending_mapping',
            'client_id' => 990002,
            'solabooks_organization_id' => TenantTestManager::ORG_A,
            'api_key' => $plainKey,
            'require_mapping_before_post' => true,
        ]));
        $payload = $response->getData(true);
        $setting = IntegrationSetting::query()->where('integration', IntegrationEvents::INTEGRATION)->firstOrFail();

        $this->assertSame($plainKey, $setting->apiKey());
        $this->assertStringNotContainsString($plainKey, json_encode($setting->meta));
        $this->assertStringNotContainsString($plainKey, json_encode($payload));
        $this->assertTrue($payload['data']['delivery_configured']);
        $this->assertSame('connected_pending_mapping', $payload['data']['mode']);
    }

    #[Test]
    public function tax_mappings_use_stable_codes_and_are_organization_scoped(): void
    {
        $this->useTenantA();
        \Illuminate\Support\Facades\DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A, 'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        InventorySetting::query()->create([
            'default_costing_method' => 'fifo', 'allow_negative_stock' => false,
            'taxes' => [
                ['code' => 'STD16', 'name' => 'Standard 16%', 'rate' => 16, 'treatment' => 'standard', 'active' => true, 'purchase' => true, 'sales' => true],
                ['code' => 'ZERO', 'name' => 'Zero', 'rate' => 0, 'treatment' => 'zero', 'active' => true, 'purchase' => true, 'sales' => true],
            ],
        ]);

        app(IntegrationController::class)->updateTaxMappings(Request::create('/integration/solabooks/mappings/taxes', 'PUT', [
            'mappings' => [
                ['tax_code' => 'STD16', 'solabooks_tax_id' => 7, 'solabooks_tax_code' => 'VAT_STD_B', 'input_tax_account_id' => 647, 'output_tax_account_id' => 648, 'status' => 'mapped'],
                ['tax_code' => 'ZERO', 'solabooks_tax_id' => 8, 'solabooks_tax_code' => 'VAT_ZR_B', 'status' => 'mapped'],
            ],
        ]));

        $this->assertSame(2, IntegrationTaxMapping::query()->where('status', 'mapped')->count());
        $this->assertSame(100, app(IntegrationController::class)->status()->getData(true)['data']['tax_mapping_completeness_pct']);

        $this->useTenantB();
        $this->assertSame(0, IntegrationTaxMapping::query()->count());
    }
}
