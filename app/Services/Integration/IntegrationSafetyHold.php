<?php

namespace App\Services\Integration;

use RuntimeException;

final class IntegrationSafetyHold
{
    public function deliveryEnabled(): bool
    {
        return (bool) config('integration_safety.solabooks_delivery_enabled', true);
    }

    public function deliveryEnabledFor(int $organizationId, ?string $database = null): bool
    {
        if ($this->deliveryEnabled()) {
            return true;
        }
        $scope = (array) config('integration_safety.phase6a_uat', []);
        $database ??= (string) config('database.connections.tenant.database', '');

        return ($scope['enabled'] ?? false) === true
            && (int) ($scope['organization_id'] ?? 0) > 0
            && (int) ($scope['organization_id'] ?? 0) === $organizationId
            && is_string($scope['tenant_database'] ?? null)
            && hash_equals((string) $scope['tenant_database'], $database);
    }

    public function workerEnabledFor(int $organizationId, ?string $database = null): bool
    {
        return (bool) config('integration_transport.worker_enabled', false)
            || $this->deliveryEnabledFor($organizationId, $database);
    }

    public function reason(): string
    {
        return (string) config('integration_safety.reason', 'currency_contract_maintenance');
    }

    public function message(): string
    {
        return __('inventory.integration.safety_hold');
    }

    public function assertDeliveryEnabled(): void
    {
        if (! $this->deliveryEnabled()) {
            throw new RuntimeException($this->message());
        }
    }

    public function assertDeliveryEnabledFor(int $organizationId, ?string $database = null): void
    {
        if (! $this->deliveryEnabledFor($organizationId, $database)) {
            throw new RuntimeException($this->message());
        }
    }

    public function assertUatDatabaseEnabled(string $database): void
    {
        $scope = (array) config('integration_safety.phase6a_uat', []);
        if ($this->deliveryEnabled()) {
            return;
        }
        if (($scope['enabled'] ?? false) !== true
            || ! is_string($scope['tenant_database'] ?? null)
            || ! hash_equals((string) $scope['tenant_database'], $database)
            || (int) ($scope['organization_id'] ?? 0) <= 0) {
            throw new RuntimeException($this->message());
        }
    }

    public function assertActivationAllowed(): void
    {
        $this->assertDeliveryEnabled();
    }
}
