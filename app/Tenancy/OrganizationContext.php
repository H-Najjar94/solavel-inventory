<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Holds the currently-active organization id for the request/job lifecycle.
 *
 * This is a singleton (bound in AppServiceProvider). It is the single source of
 * truth that the BelongsToOrganization global scope and StockLedgerService read
 * to scope and stamp every row. There is deliberately no "guess the org from the
 * model" fallback: if no organization is active, tenant-scoped work must fail
 * loudly rather than silently leak across tenants.
 *
 * Job safety: queued jobs do not inherit request state, so a job must call
 * OrganizationContext::set()/forget() (typically via TenantManager::useTenant())
 * explicitly. See App\Tenancy\Concerns\WithOrganizationContext for a helper.
 */
class OrganizationContext
{
    private ?int $organizationId = null;

    public function set(int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new RuntimeException('OrganizationContext::set() requires a positive organization id.');
        }

        $this->organizationId = $organizationId;
    }

    public function forget(): void
    {
        $this->organizationId = null;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    /** Returns the active org id or null (use when absence is acceptable). */
    public function id(): ?int
    {
        return $this->organizationId;
    }

    /** Returns the active org id or throws (use when absence is a bug). */
    public function idOrFail(): int
    {
        if ($this->organizationId === null) {
            throw new RuntimeException(
                'No active organization context. Tenant-scoped operations require an '
                .'organization to be set (web: resolve middleware; jobs/tests: '
                .'TenantManager::useTenant() or OrganizationContext::set()).'
            );
        }

        return $this->organizationId;
    }

    /**
     * Run a callback with a specific organization active, restoring the previous
     * context afterwards (even on exception). Useful in jobs and tests.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(int $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->set($organizationId);

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
