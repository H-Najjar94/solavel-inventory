<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Entitlements\EntitlementsCache;
use RuntimeException;

/** Fail-closed commercial authorization for the Stock → Finance v2 worker. */
final class ApprovedFinanceIntegrationEntitlement
{
    private const APPROVED_SOURCES = ['advanced_bundle', 'enterprise_bundle'];

    public function __construct(private readonly EntitlementsCache $entitlements) {}

    public function assertApproved(IntegrationOrganizationMapping $mapping): void
    {
        $snapshot = $this->entitlements->getProjectSnapshot(
            (int) $mapping->central_client_id,
            'inventory',
        );

        if (! is_array($snapshot)
            || ($snapshot['accessible'] ?? false) !== true
            || ($snapshot['commercially_entitled'] ?? false) !== true
            || ! in_array((string) ($snapshot['entitlement_source'] ?? ''), self::APPROVED_SOURCES, true)) {
            throw new RuntimeException('Stock to Finance transport requires an explicit Advanced or Enterprise entitlement.');
        }
    }
}
