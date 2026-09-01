<?php

namespace Tests\Unit\Integration;

use App\Services\Integration\ApprovedTransportTargetRegistry;
use App\Services\Tenancy\TenantManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovedTransportTargetRegistryTest extends TestCase
{
    public function test_no_approved_targets_is_a_safe_idle_allowlist(): void
    {
        $this->assertSame([], $this->registry()->targetsFromRows([]));
    }

    #[DataProvider('approvedPlans')]
    public function test_only_paid_active_advanced_or_enterprise_state_creates_a_target(string $plan): void
    {
        $targets = $this->registry()->targetsFromRows([$this->row($plan)]);

        $this->assertSame([[
            'client_id' => 42,
            'organization_id' => 77,
            'database' => 'tenant_000042',
            'plan' => $plan,
        ]], $targets);
    }

    public static function approvedPlans(): array
    {
        return [['advanced'], ['enterprise']];
    }

    #[DataProvider('rejectedStates')]
    public function test_premium_unknown_suspended_expired_and_unapproved_states_never_become_targets(array $overrides): void
    {
        $this->assertSame([], $this->registry()->targetsFromRows([$this->row('advanced', $overrides)]));
    }

    public static function rejectedStates(): array
    {
        return [
            [['plan_code' => 'premium']],
            [['plan_code' => 'unknown']],
            [['effective_access_state' => 'suspended']],
            [['effective_access_state' => 'paid_expired']],
            [['integration_capabilities' => ['connection_activation_delivery_entitled' => false]]],
            [['accessible_apps' => ['inventory']]],
        ];
    }

    private function registry(): ApprovedTransportTargetRegistry
    {
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('resolveDatabaseName')->willReturnCallback(
            fn (int $client): string => 'tenant_'.str_pad((string) $client, 6, '0', STR_PAD_LEFT)
        );

        return new ApprovedTransportTargetRegistry($tenants);
    }

    private function row(string $plan, array $overrides = []): object
    {
        $state = array_replace_recursive([
            'client_id' => 42,
            'organization_id' => 77,
            'plan_code' => $plan,
            'effective_access_state' => 'paid_active',
            'accessible_apps' => ['finance', 'inventory'],
            'integration_capabilities' => ['connection_activation_delivery_entitled' => true],
        ], $overrides);

        return (object) [
            'organization_id' => 77,
            'state_payload' => json_encode($state, JSON_THROW_ON_ERROR),
        ];
    }
}
