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

    public function __construct(private CentralAppAccess $central) {}

    public static function covers(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
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
