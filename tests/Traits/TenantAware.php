<?php

namespace Tests\Traits;

use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Tests\Support\TenantTestManager;

/**
 * Helper for tenant-backed tests using the FIXED reserved-database model
 * (mirrors Finance's TenantAware): switch the `tenant` connection between the
 * pre-provisioned reserved DBs (tenant_990002 = A, tenant_990003 = B) and rely
 * on transaction rollback for cleanup. The suite never creates or drops DBs.
 */
trait TenantAware
{
    protected ?TenantTestManager $tenantTestManager = null;

    protected function setUpTenantAware(): void
    {
        $this->tenantTestManager = new TenantTestManager(
            app(TenantManager::class),
            app(OrganizationContext::class),
        );
    }

    /** Activate reserved tenant A (tenant_990002) and begin a test transaction. */
    protected function useTenantA(): string
    {
        $this->ensureManager();

        return $this->tenantTestManager->useTenant(TenantTestManager::ORG_A);
    }

    /** Activate reserved tenant B (tenant_990003) and begin a test transaction. */
    protected function useTenantB(): string
    {
        $this->ensureManager();

        return $this->tenantTestManager->useTenant(TenantTestManager::ORG_B);
    }

    /** Activate a reserved tenant by org id (ORG_A or ORG_B). */
    protected function useReservedTenant(int $organizationId): string
    {
        $this->ensureManager();

        return $this->tenantTestManager->useTenant($organizationId);
    }

    private function ensureManager(): void
    {
        if (! $this->tenantTestManager) {
            $this->setUpTenantAware();
        }
    }

    protected function tearDown(): void
    {
        // Roll back all test transactions (no DB drops). Even on failure.
        $this->tenantTestManager?->cleanup();
        $this->tenantTestManager = null;

        parent::tearDown();
    }
}
