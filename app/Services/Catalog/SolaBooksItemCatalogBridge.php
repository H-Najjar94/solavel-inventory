<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use RuntimeException;

/** Compatibility boundary: automatic cross-application writes are permanently contained. */
class SolaBooksItemCatalogBridge
{
    public function sync(Item $item, ?string $previousSku = null): bool
    {
        // Stock persists its own record. Finance projections require an explicit
        // owner-side command; an item observer must never write Finance tables.
        return false;
    }

    public function importFromSolaBooks(object $source): bool
    {
        throw new RuntimeException('catalog_owner_command_required: automatic catalog import is disabled. Review explicit identity mappings.');
    }

    public function reconcileOrganization(int $stockOrganizationId): array
    {
        throw new RuntimeException('catalog_owner_command_required: bidirectional catalog reconciliation is disabled. Existing records and mappings are retained.');
    }
}
