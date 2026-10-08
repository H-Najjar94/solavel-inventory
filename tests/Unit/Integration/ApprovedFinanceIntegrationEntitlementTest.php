<?php

namespace Tests\Unit\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Entitlements\EntitlementAccessDecision;
use App\Services\Entitlements\EntitlementClock;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceInventoryCapability;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovedFinanceIntegrationEntitlementTest extends TestCase
{
    protected function tearDown(): void
    {
        EntitlementClock::setTestNow(null);
        parent::tearDown();
    }

    #[DataProvider('scenarios')]
    public function test_canonical_capability_and_paid_through_are_both_required(bool $capable, array $overrides, bool $expected): void
    {
        EntitlementClock::setTestNow(CarbonImmutable::parse('2026-09-07T00:00:00Z'));
        $snapshot = array_replace([
            'accessible' => true, 'commercially_entitled' => true,
            'subscription_status' => 'active', 'entitlement_source' => 'separate_subscription',
            'access_until' => '2026-10-01T00:00:00Z',
        ], $overrides);
        $cache = $this->createStub(EntitlementsCache::class);
        $cache->method('getProjectSnapshot')->willReturn($snapshot);
        $capability = $this->createMock(FinanceInventoryCapability::class);
        $capability->expects($this->once())->method('allows')->with(9001, 9002)->willReturn($capable);
        $guard = new ApprovedFinanceIntegrationEntitlement($cache, $capability, new EntitlementAccessDecision);
        $mapping = new IntegrationOrganizationMapping;
        $mapping->setRawAttributes(['central_client_id' => 9001, 'central_organization_id' => 9002]);
        if (! $expected) {
            $this->expectException(RuntimeException::class);
        }
        $guard->assertApproved($mapping);
        if ($expected) {
            $this->addToAssertionCount(1);
        }
    }

    public static function scenarios(): array
    {
        return [
            'qualifying separate subscriptions' => [true, [], true],
            'nonqualifying separate subscriptions' => [false, [], false],
            'advanced bundle' => [true, ['entitlement_source' => 'advanced_bundle'], true],
            'enterprise bundle' => [true, ['entitlement_source' => 'enterprise_bundle'], true],
            'bundle source does not override capability' => [false, ['entitlement_source' => 'advanced_bundle'], false],
            'expired' => [true, ['access_until' => '2026-09-06T23:59:59Z'], false],
            'revoked' => [true, ['revoked_at' => '2026-09-06T23:59:59Z'], false],
            'denied' => [true, ['accessible' => false], false],
            'stale but paid through' => [true, ['_snapshot' => ['beyond_max_stale' => true], 'valid_until' => '2026-08-01T00:00:00Z'], true],
        ];
    }

    public function test_current_production_snapshot_shape_does_not_require_redundant_access_booleans(): void
    {
        EntitlementClock::setTestNow(CarbonImmutable::parse('2026-09-07T00:00:00Z'));
        $snapshot = [
            'subscription_status' => 'active',
            'access_eligible' => true,
            'access_until' => '2026-10-01T00:00:00Z',
            'flags' => ['api_integration.enabled' => true],
        ];
        $cache = $this->createStub(EntitlementsCache::class);
        $cache->method('getProjectSnapshot')->willReturn($snapshot);
        $capability = $this->createMock(FinanceInventoryCapability::class);
        $capability->expects($this->once())->method('allows')->with(9001, 9002)->willReturn(true);
        $mapping = new IntegrationOrganizationMapping;
        $mapping->setRawAttributes(['central_client_id' => 9001, 'central_organization_id' => 9002]);

        (new ApprovedFinanceIntegrationEntitlement($cache, $capability, new EntitlementAccessDecision))->assertApproved($mapping);
        $this->addToAssertionCount(1);
    }
}
