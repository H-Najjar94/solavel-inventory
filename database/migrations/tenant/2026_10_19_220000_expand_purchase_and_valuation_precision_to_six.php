<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'purchase_order_lines' => ['ordered_qty','received_qty','unit_price','entered_qty'],
        'purchase_order_backorders' => ['ordered_qty','received_qty','backorder_qty','unit_price'],
        'purchasing_receiving_request_lines' => ['requested_qty','received_qty','unit_cost'],
        'goods_receipt_lines' => ['received_qty','accepted_qty','rejected_qty','quarantine_qty','unit_cost','entered_qty'],
        'supplier_return_lines' => ['quantity','entered_qty'],
        'opening_stock_entry_lines' => ['quantity','unit_cost','entered_qty'],
        'stock_adjustment_lines' => ['quantity','unit_cost','entered_qty'],
        'stock_ledger' => ['quantity','unit_cost','total_cost','balance_qty_after','balance_value_after'],
        'stock_balances' => ['on_hand_qty','reserved_qty','average_cost','total_value'],
        'cost_layers' => ['unit_cost','original_qty','remaining_qty'],
        'cost_layer_consumptions' => ['qty','unit_cost'],
        'stock_landed_cost_lines' => ['quantity','receipt_value','weight','allocated_base_amount','inventory_base_amount','consumed_base_amount'],
        'integration_document_lifecycle_mappings' => ['ordered_qty','reserved_qty','received_qty','billed_qty','shipped_qty','invoiced_qty','returned_qty'],
    ];

    public function up(): void
    {
        $schema=Schema::connection('tenant');
        foreach(self::COLUMNS as $table=>$columns){
            if(!$schema->hasTable($table))continue;
            foreach($columns as $column){
                if(!$schema->hasColumn($table,$column))continue;
                $schema->table($table,fn(Blueprint $t)=>$t->decimal($column,24,6)->change());
            }
        }
    }

    /** Narrowing would silently destroy valid source precision, so rollback is intentionally non-destructive. */
    public function down(): void {}
};
