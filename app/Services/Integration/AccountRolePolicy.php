<?php

namespace App\Services\Integration;

/** Shared, versioned Finance/Stock contract. Keep both copies byte-identical. */
final class AccountRolePolicy
{
    public const VERSION = 'inventory-account-roles.v1';
    public const ROLE_TYPES = ['inventory_asset' => ['asset'], 'cogs' => ['expense', 'cogs'], 'grni' => ['liability'], 'opening_offset' => ['equity'], 'adjustment_gain' => ['revenue', 'income'], 'adjustment_loss' => ['expense'], 'purchase_price_variance' => ['expense','cogs']];
    public const OPERATIONS = [
        'opening_stock.posted' => ['inventory_asset', 'opening_offset'],
        'opening_stock.reversed' => ['inventory_asset', 'opening_offset'],
        'adjustment.posted' => ['inventory_asset', 'adjustment_gain', 'adjustment_loss'],
        'adjustment.reversed' => ['inventory_asset', 'adjustment_gain', 'adjustment_loss'],
        'stock_count.posted' => ['inventory_asset', 'adjustment_gain', 'adjustment_loss'],
        'grn.posted' => ['inventory_asset', 'grni'],
        'grn.reversed' => ['inventory_asset', 'grni'],
        'shipment.posted' => ['cogs', 'inventory_asset'],
        'sales_return.posted' => ['inventory_asset', 'cogs'],
        'sales_return.reversed' => ['inventory_asset', 'cogs'],
        'purchase_cost_adjustment.posted' => ['inventory_asset','cogs','adjustment_loss','purchase_price_variance'],
        'purchase_cost_adjustment.reversed' => ['inventory_asset','cogs','adjustment_loss','purchase_price_variance'],
        'transfer.posted' => [], // Same organization: quantity movement, no journal.
        'purchase_order.approved' => [],
        'sales_order.confirmed' => [],
        'stock_reserved' => [],
        'stock_reservation_released' => [],
        'pick_list.picked' => [],
        'pack.packed' => [],
    ];

    public static function forOperations(array $operations): array
    {
        $roles = [];
        foreach ($operations as $operation) {
            if (! is_string($operation) || ! array_key_exists($operation, self::OPERATIONS)) {
                throw new \InvalidArgumentException('Unqualified inventory accounting operation');
            }
            $roles = array_merge($roles, self::OPERATIONS[$operation]);
        }
        $roles = array_values(array_unique($roles));
        sort($roles);
        return $roles;
    }

    public static function workflowTemplate(string $eventType): ?array
    {
        return match ($eventType) {
            'grn.posted' => [['inventory_asset', 'debit'], ['grni', 'credit']],
            'grn.reversed' => [['grni', 'debit'], ['inventory_asset', 'credit']],
            'shipment.posted' => [['cogs', 'debit'], ['inventory_asset', 'credit']],
            'sales_return.posted' => [['inventory_asset', 'debit'], ['cogs', 'credit']],
            'sales_return.reversed' => [['cogs', 'debit'], ['inventory_asset', 'credit']],
            default => null,
        };
    }
}
