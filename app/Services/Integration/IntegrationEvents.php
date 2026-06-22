<?php

namespace App\Services\Integration;

/**
 * Registry of SolaBooks integration event types and the document aggregate each
 * maps to, plus the suggested accounting hints (debit/credit mapping types).
 * SolaStock only SUGGESTS the accounting treatment — SolaBooks decides the final
 * journal entry. No GL logic here.
 */
final class IntegrationEvents
{
    public const INTEGRATION = 'solabooks';

    /** event_type => [aggregate_type, suggested_debit, suggested_credit] */
    public const TYPES = [
        'opening_stock.posted'   => ['OpeningStockEntry', 'inventory_asset', 'opening_offset'],
        'opening_stock.reversed' => ['OpeningStockEntry', 'opening_offset', 'inventory_asset'],
        'adjustment.posted'      => ['StockAdjustment', 'inventory_asset', 'adjustment_gain'], // direction-dependent; see builder
        'adjustment.reversed'    => ['StockAdjustment', 'adjustment_gain', 'inventory_asset'],
        'grn.posted'             => ['GoodsReceipt', 'inventory_asset', 'grni'],
        'transfer.posted'        => ['StockTransfer', 'inventory_asset', 'transfer_clearing'],
        'stock_count.posted'     => ['StockCount', 'inventory_asset', 'adjustment_gain'],

        // ── Sales fulfillment ──
        // Only shipment.posted / sales_return.posted move stock + carry COGS hints.
        'sales_order.confirmed'        => ['SalesOrder', null, null],
        'stock_reserved'               => ['SalesOrder', null, null],
        'stock_reservation_released'   => ['SalesOrder', null, null],
        'pick_list.picked'             => ['PickList', null, null],
        'pack.packed'                  => ['Pack', null, null],
        'shipment.posted'              => ['Shipment', 'cogs', 'inventory_asset'],
        'sales_return.posted'          => ['SalesReturn', 'inventory_asset', 'cogs'],
    ];

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function aggregateType(string $type): ?string
    {
        return self::TYPES[$type][0] ?? null;
    }

    public static function suggestedAccounts(string $type): array
    {
        $t = self::TYPES[$type] ?? [null, null, null];

        return ['suggested_debit_account_mapping' => $t[1], 'suggested_credit_account_mapping' => $t[2]];
    }

    /**
     * Deterministic idempotency key: integration event for a specific document
     * action. Re-posting/retrying never duplicates the event.
     */
    public static function idempotencyKey(string $eventType, string $aggregateType, int $aggregateId): string
    {
        return self::INTEGRATION.':'.$eventType.':'.class_basename($aggregateType).':'.$aggregateId;
    }
}
