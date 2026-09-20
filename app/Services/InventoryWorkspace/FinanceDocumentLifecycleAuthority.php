<?php

namespace App\Services\InventoryWorkspace;

use App\Services\Access\CentralAppAccess;

/**
 * Scoped integration capability: the Stock-side follow-through of an accounting
 * operation SolaCount has already authorized (post, delete draft, void/unpost).
 *
 * These actions carry no selection of their own. They only move allocation or
 * cost-adjustment rows that a SolaStock-authorized reviewer already reserved or
 * prepared for one exact financial document and fingerprint, inside the verified
 * organization connection. They are therefore governed by the acting member's
 * current SolaCount access, not by SolaStock app access or connection management.
 *
 * Browsing Stock sources, reserving quantities and preparing a cost review remain
 * SolaStock operations under their native permissions.
 */
final class FinanceDocumentLifecycleAuthority
{
    public const SCOPE = 'finance_document_lifecycle';

    public const ACTIONS = [
        'finance-allocations.commit',
        'finance-allocations.release',
        'finance-allocations.reverse',
        'finance-allocations.cost-adjustment.apply',
        'finance-allocations.cost-adjustment.reverse',
    ];

    /**
     * Creating a catalog item is native SolaCount mini-inventory work. In a connected
     * organization the authoritative identity lives here, so SolaCount creates it through
     * the established signed saga (requirements → create → link) instead of a second,
     * disconnected catalog. SolaCount has already enforced `inventory.items.manage`; the
     * saga itself only accepts reviewed category/unit mappings and native item rules, and
     * opens no browsing, editing, stock or warehouse operation.
     */
    public const CATALOG_SCOPE = 'finance_catalog_item_creation';

    public const CATALOG_ACTIONS = [
        'items.migration-requirements',
        'items.migration-create',
        'items.migration-link',
    ];

    public function __construct(private CentralAppAccess $central) {}

    public static function covers(string $action): bool
    {
        return self::scopeFor($action) !== null;
    }

    public static function scopeFor(string $action): ?string
    {
        return in_array($action, self::ACTIONS, true) ? self::SCOPE
            : (in_array($action, self::CATALOG_ACTIONS, true) ? self::CATALOG_SCOPE : null);
    }

    /** Fresh Central authority; an indeterminate answer never permits the effect. */
    public function allows(object $actor, int $centralOrganizationId): bool
    {
        // SolaStock's Authenticatable is the Central registry user unless a mirror names one.
        $centralUserId = (int) ($actor->central_user_id ?? 0) ?: (int) ($actor->id ?? 0);
        $decision = $this->central->decision($centralUserId, $centralOrganizationId, 'finance');

        return ($decision['allowed'] ?? false) === true;
    }
}
