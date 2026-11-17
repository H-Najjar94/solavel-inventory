<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Models\Tenant\Unit;
use Illuminate\Validation\ValidationException;

/** Base quantities are immutable historical facts, not a display-unit preference.
 * Call under Item::save's existing row lock; movement/document writers take that same lock.
 */
final class ItemBaseUnitGuard
{
    private const REFERENCES = [
        'cost_layers', 'reservations', 'opening_stock_entry_lines', 'goods_receipt_lines',
        'stock_adjustment_lines', 'stock_count_lines', 'stock_transfer_lines', 'shipment_lines',
        'internal_consumption_lines', 'supplier_return_lines', 'sales_return_lines', 'purchase_order_lines',
        'sales_order_lines', 'purchasing_receiving_request_lines', 'pick_list_lines', 'purchase_valuation_holds',
        'unit_conversions', 'item_suppliers', 'supplier_price_lists',
    ];

    public function blockers(Item $item): array
    {
        $db = $item->getConnection();
        $schema = $db->getSchemaBuilder();
        $org = (int) $item->organization_id;
        $id = (int) $item->id;
        $reasons = [];
        foreach (['stock_ledger', 'stock_balances', 'cost_layers'] as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'item_id') || ! $schema->hasColumn($table, 'organization_id')) {
                return ['history_schema_unavailable'];
            }
        }
        if ($db->table('stock_ledger')->where('organization_id', $org)->where('item_id', $id)->exists()) {
            $reasons[] = 'stock_history';
        }
        if ($db->table('stock_balances')->where('organization_id', $org)->where('item_id', $id)->where(fn ($q) => $q->where('on_hand_qty', '<>', 0)->orWhere('reserved_qty', '<>', 0)->orWhere('total_value', '<>', 0))->exists()) {
            $reasons[] = 'stock_balance_or_value';
        }
        foreach (self::REFERENCES as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'item_id')) {
                continue;
            }
            if (! $schema->hasColumn($table, 'organization_id')) {
                $reasons[] = 'unscoped_'.$table;

                continue;
            }
            if ($db->table($table)->where('organization_id', $org)->where('item_id', $id)->exists()) {
                $reasons[] = $table;
            }
        }
        // Even a stock-pristine mapped item may already be invoiced in Finance.
        // There is no atomic cross-app unit-rescale protocol: preserve both identities.
        if ($schema->hasTable('integration_master_data_mappings') && $db->table('integration_master_data_mappings')->where('solastock_organization_id', $org)->where('entity_type', 'item')->where('solastock_record_id', (string) $id)->exists()) {
            $reasons[] = 'finance_catalog_mapping';
        }
        if ($schema->hasTable('integration_catalog_sync_states') && $db->table('integration_catalog_sync_states')->where('organization_id', $org)->where('entity_type', 'item')->where('source_id', $id)->exists()) {
            $reasons[] = 'finance_catalog_sync';
        }

        return array_values(array_unique($reasons));
    }

    public function assertChange(Item $current, ?int $target): void
    {
        if ((int) $current->base_unit_id === (int) $target) {
            return;
        }
        if ($target) {
            $unit = Unit::withoutGlobalScopes()->where('organization_id', $current->organization_id)->whereKey($target)->where('is_active', true)->whereNull('deleted_at')->first();
            if (! $unit) {
                throw ValidationException::withMessages(['base_unit_id' => __('inventory.base_unit.invalid')]);
            }
        } elseif ($current->item_type === 'inventory') {
            throw ValidationException::withMessages(['base_unit_id' => __('inventory.validation.unit_required_for_inventory')]);
        }
        $reasons = $this->blockers($current);
        if ($reasons) {
            throw ValidationException::withMessages(['base_unit_id' => __('inventory.base_unit.blocked'), 'base_unit_blockers' => $reasons]);
        }
    }

    public function preview(Item $item): array
    {
        $reasons = $this->blockers($item);

        return ['can_change_base_unit' => $reasons === [], 'blockers' => $reasons, 'message' => $reasons ? __('inventory.base_unit.blocked') : __('inventory.base_unit.pristine'), 'base_unit_id' => $item->base_unit_id, 'alternate_unit_action' => __('inventory.base_unit.add_purchase_unit'), 'alternate_unit_url' => '/settings?purchase_unit_item='.$item->id.'&to_unit_id='.$item->base_unit_id.'#unit-conversion', 'history_conversion_supported' => false];
    }
}
