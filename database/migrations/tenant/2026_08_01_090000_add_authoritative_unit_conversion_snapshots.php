<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $columns = [
                    'entered_qty' => fn () => $blueprint->decimal('entered_qty', 18, 4)->nullable(),
                    'entered_unit_id' => fn () => $blueprint->unsignedBigInteger('entered_unit_id')->nullable(),
                    'base_unit_id' => fn () => $blueprint->unsignedBigInteger('base_unit_id')->nullable(),
                    'unit_conversion_id' => fn () => $blueprint->unsignedBigInteger('unit_conversion_id')->nullable(),
                    'unit_conversion_factor' => fn () => $blueprint->decimal('unit_conversion_factor', 18, 8)->nullable(),
                    'unit_conversion_version' => fn () => $blueprint->string('unit_conversion_version', 64)->nullable(),
                    'unit_conversion_hash' => fn () => $blueprint->char('unit_conversion_hash', 64)->nullable(),
                    'unit_conversion_precision' => fn () => $blueprint->unsignedTinyInteger('unit_conversion_precision')->nullable(),
                    'unit_conversion_rounding_mode' => fn () => $blueprint->string('unit_conversion_rounding_mode', 16)->nullable(),
                ];
                foreach ($columns as $column => $add) {
                    if (! Schema::hasColumn($table, $column)) {
                        $add();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $columns = $this->snapshotColumns();
                if (in_array($table, ['purchase_order_lines', 'goods_receipt_lines', 'opening_stock_entry_lines'], true)) {
                    $columns = array_values(array_diff($columns, ['entered_qty', 'entered_unit_id', 'unit_conversion_factor']));
                }
                foreach (array_reverse($columns) as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'purchase_order_lines', 'goods_receipt_lines', 'opening_stock_entry_lines',
            'shipment_lines', 'sales_return_lines', 'stock_transfer_lines', 'stock_adjustment_lines',
        ];
    }

    /** @return list<string> */
    private function snapshotColumns(): array
    {
        return [
            'entered_qty', 'entered_unit_id', 'base_unit_id', 'unit_conversion_id',
            'unit_conversion_factor', 'unit_conversion_version', 'unit_conversion_hash',
            'unit_conversion_precision', 'unit_conversion_rounding_mode',
        ];
    }
};
