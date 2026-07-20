<?php

namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\IntegrationEvents;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class IntegrationConnectionConfigurationTest extends TestCase
{
    use TenantAware;

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
}
