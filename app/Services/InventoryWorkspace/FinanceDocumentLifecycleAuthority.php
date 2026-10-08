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
        'purchasing.credit-receipt-restore.prepare', 'purchasing.credit-receipt-restore.apply',
        'purchasing.credit-receipt-restore.status', 'purchasing.credit-receipt-restore.release',
        'purchasing.credit-value.prepare', 'purchasing.credit-value.apply', 'purchasing.credit-value.status',
        'purchasing.credit-value.release', 'purchasing.credit-value.reverse',
        'purchasing.bill.context', 'purchasing.bill.receipt', 'purchasing.bill.reserve', 'purchasing.bill.cost-adjustment.prepare',
        'purchasing.request.upsert', 'purchasing.request.status', 'purchasing.request.cancel',
        'sales.request.upsert', 'sales.request.status', 'sales.request.cancel', 'sales.request.reduce-demand',
        'finance-allocations.review-status',
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
    /** Value-only matching of a genuine posted inventory Expense, never physical access. */
    public const FINANCIAL_ORIGIN_SETTLEMENT_ACTIONS = [
        'financial-origin.settlement.prepare', 'financial-origin.settlement.apply',
        'financial-origin.settlement.reverse', 'financial-origin.settlement.status',
        'financial-origin.settlement.release',
    ];

    /** Genuine native cash sales and inventory expenses: financial demand only. */
    public const FINANCIAL_ORIGIN_REQUEST_ACTIONS = [
        'financial-origin.request.upsert', 'financial-origin.request.cancel', 'financial-origin.request.status',
    ];

    /** Metadata only; does not confer warehouse access or permit actor zero. */
    public const FINANCIAL_ORIGIN_CAPABILITY_ACTIONS = ['financial-origin.capabilities'];

    public const CATALOG_SCOPE = 'finance_catalog_item_creation';

    public const CATALOG_ACTIONS = [
        'catalog-references.ensure',
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
        return (in_array($action, self::ACTIONS, true) || in_array($action, self::FINANCIAL_ORIGIN_SETTLEMENT_ACTIONS, true) || in_array($action, self::FINANCIAL_ORIGIN_REQUEST_ACTIONS, true) || in_array($action, self::FINANCIAL_ORIGIN_CAPABILITY_ACTIONS, true)) ? self::SCOPE
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
