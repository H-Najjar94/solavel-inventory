<?php

namespace App\Services\Integration;

use RuntimeException;

final class IntegrationSafetyHold
{
    public function deliveryEnabled(): bool
    {
        return (bool) config('integration_safety.solabooks_delivery_enabled', true);
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

    public function assertActivationAllowed(): void
    {
        $this->assertDeliveryEnabled();
    }
}
