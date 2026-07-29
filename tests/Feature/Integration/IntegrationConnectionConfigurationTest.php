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
    public function shared_solabooks_workspace_is_reported_as_read_only_connected_without_delivery_credentials(): void
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

        $this->assertSame('connected_readonly', $status['mode']);
        $this->assertTrue($status['workspace_connected']);
        $this->assertSame($financeOrgId, $status['solabooks_organization_id']);
        $this->assertFalse($status['delivery_configured']);
    }

    #[Test]
    public function owner_configuration_encrypts_the_key_and_status_never_returns_it(): void
    {
        $this->useTenantA();
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
