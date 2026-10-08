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
        foreach (['purchase_order_lines', 'goods_receipt_lines', 'opening_stock_entry_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'entered_qty')) {
                    $t->decimal('entered_qty', 18, 4)->nullable()->after($this->quantityColumn($table));
                }
                if (! Schema::hasColumn($table, 'entered_unit_id')) {
                    $t->unsignedBigInteger('entered_unit_id')->nullable()->after('entered_qty');
                }
                if (! Schema::hasColumn($table, 'unit_conversion_factor')) {
                    $t->decimal('unit_conversion_factor', 18, 8)->nullable()->after('entered_unit_id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_order_lines', 'goods_receipt_lines', 'opening_stock_entry_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['unit_conversion_factor', 'entered_unit_id', 'entered_qty'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }

    private function quantityColumn(string $table): string
    {
        return match ($table) {
            'purchase_order_lines' => 'ordered_qty',
            'goods_receipt_lines' => 'accepted_qty',
            default => 'quantity',
        };
    }
};
