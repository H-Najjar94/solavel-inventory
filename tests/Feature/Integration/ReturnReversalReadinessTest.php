<?php

namespace Tests\Feature\Integration;

use App\Services\Integration\ReturnReversalReadiness;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Read-only, organization-scoped readiness for reversing posted customer returns. */
class ReturnReversalReadinessTest extends TestCase
{
    use TenantAware;

    private function setting(int $org, string $mode, ?array $workflows): void
    {
        DB::connection('tenant')->table('integration_settings')->insert([
            'organization_id' => $org, 'integration' => 'solabooks', 'mode' => $mode,
            'meta' => json_encode($workflows === null ? ['transport_enabled' => true] : ['transport_enabled' => true, 'transport_enabled_workflows' => $workflows]),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function snapshot(): array
    {
        return DB::connection('tenant')->table('integration_settings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public static function states(): array
    {
        return [
            'not connected' => [null, null, 'not_connected', 'n/a'],
            'inactive connection' => ['paused', ['sales_return.reversed'], 'connection_inactive', 'present'],
            'stored list without reversal' => ['active', ['sales_return.posted'], 'needs_wizard', 'missing'],
            'stored list with reversal' => ['active', ['sales_return.posted', 'sales_return.reversed'], 'ready', 'present'],
            'default reviewed scope' => ['active', null, 'ready', 'default'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('states')]
    public function readiness_reports_each_state_without_changing_the_connection(?string $mode, ?array $workflows, string $state, string $scope): void
    {
        $this->useTenantA();
        if ($mode !== null) $this->setting(TenantTestManager::ORG_A, $mode, $workflows);
        $before = $this->snapshot();
        $result = app(ReturnReversalReadiness::class)->forOrganization(TenantTestManager::ORG_A);
        $this->assertSame($state, $result['state']);
        $this->assertSame($scope, $result['scope']);
        $this->assertSame($before, $this->snapshot(), 'Readiness must never change integration settings');
    }

    #[Test]
    public function another_organizations_setting_never_answers_for_this_organization(): void
    {
        $this->useTenantA();
        $this->setting(TenantTestManager::ORG_B, 'active', ['sales_return.posted', 'sales_return.reversed']);
        $this->assertSame('not_connected', app(ReturnReversalReadiness::class)->forOrganization(TenantTestManager::ORG_A)['state']);
    }
}
