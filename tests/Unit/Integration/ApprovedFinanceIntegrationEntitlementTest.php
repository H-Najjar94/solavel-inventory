<?php

namespace Tests\Unit\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovedFinanceIntegrationEntitlementTest extends TestCase
{
    #[DataProvider('approvedSources')]
    public function test_worker_accepts_only_explicit_advanced_or_enterprise_sources(string $source): void
    {
        $guard = new ApprovedFinanceIntegrationEntitlement($this->cache([
            'accessible' => true,
            'commercially_entitled' => true,
            'entitlement_source' => $source,
        ]));

        $guard->assertApproved($this->mapping());
        $this->addToAssertionCount(1);
    }

    public static function approvedSources(): array
    {
        return [['advanced_bundle'], ['enterprise_bundle']];
    }

    #[DataProvider('rejectedSnapshots')]
    public function test_worker_rejects_premium_promotional_paid_and_unverified_access(?array $snapshot): void
    {
        $guard = new ApprovedFinanceIntegrationEntitlement($this->cache($snapshot));

        $this->expectException(RuntimeException::class);
        $guard->assertApproved($this->mapping());
    }

    public static function rejectedSnapshots(): array
    {
        return [
            [null],
            [['accessible' => true, 'commercially_entitled' => true, 'entitlement_source' => 'premium_stock_promotion']],
            [['accessible' => true, 'commercially_entitled' => true, 'entitlement_source' => 'manual']],
            [['accessible' => false, 'commercially_entitled' => true, 'entitlement_source' => 'advanced_bundle']],
            [['accessible' => true, 'commercially_entitled' => false, 'entitlement_source' => 'enterprise_bundle']],
        ];
    }

    private function cache(?array $snapshot): EntitlementsCache
    {
        $cache = $this->createMock(EntitlementsCache::class);
        $cache->expects($this->once())
            ->method('getProjectSnapshot')
            ->with(9001, 'inventory')
            ->willReturn($snapshot);

        return $cache;
    }

    private function mapping(): IntegrationOrganizationMapping
    {
        $mapping = new IntegrationOrganizationMapping;
        $mapping->setRawAttributes(['central_client_id' => 9001]);

        return $mapping;
    }
}
