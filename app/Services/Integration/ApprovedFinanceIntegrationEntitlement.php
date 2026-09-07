<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Entitlements\EntitlementAccessDecision;
use App\Services\Entitlements\EntitlementsCache;
use RuntimeException;

/** Commercial authorization only; mapping, delivery holds and actor permissions remain independent. */
final class ApprovedFinanceIntegrationEntitlement
{
    public function __construct(
        private readonly EntitlementsCache $entitlements,
        private readonly FinanceInventoryCapability $capability,
        private readonly EntitlementAccessDecision $decisions,
    ) {}

    public function assertApproved(IntegrationOrganizationMapping $mapping): void
    {
        $clientId = (int) $mapping->central_client_id;
        if (! $this->capability->allows($clientId, (int) $mapping->central_organization_id)) {
            throw new RuntimeException('Finance and Stock integration capability is not authorized for this organization.');
        }
        foreach (['finance', 'inventory'] as $slug) {
            $snapshot = $this->entitlements->getProjectSnapshot($clientId, $slug);
            if (($snapshot['accessible'] ?? false) !== true
                || ($snapshot['commercially_entitled'] ?? false) !== true
                || $this->decisions->decide($snapshot, '')['reason'] !== EntitlementAccessDecision::DENY_NOT_IN_PLAN) {
                throw new RuntimeException('Finance and Stock require current commercial access for delivery.');
            }
        }
    }
}
