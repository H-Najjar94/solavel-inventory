<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function getConnection(): ?string { return config('tenancy.tenant_connection', 'tenant'); }
    public function up(): void {
        if (! Schema::hasTable('supplier_returns')) Schema::create('supplier_returns', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('return_uuid'); $t->string('return_number');
            $t->unsignedBigInteger('goods_receipt_id'); $t->unsignedBigInteger('supplier_id'); $t->unsignedBigInteger('warehouse_id');
            $t->date('return_date'); $t->text('reason'); $t->text('notes')->nullable(); $t->string('status', 32)->default('draft');
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('posted_by')->nullable(); $t->timestamp('posted_at')->nullable();
            $t->unsignedBigInteger('reversal_id')->nullable();$t->unsignedBigInteger('reversed_by')->nullable(); $t->timestamp('reversed_at')->nullable(); $t->timestamps();
            $t->unique(['organization_id','return_uuid'], 'supplier_return_uuid_unique');
            $t->unique(['organization_id','return_number'], 'supplier_return_number_unique');
            $t->index(['organization_id','goods_receipt_id','status'], 'supplier_return_source_index');
        });
        if (! Schema::hasTable('supplier_return_lines')) Schema::create('supplier_return_lines', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->unsignedBigInteger('supplier_return_id'); $t->unsignedBigInteger('goods_receipt_line_id');
            $t->unsignedBigInteger('source_stock_ledger_id');
            $t->unsignedBigInteger('item_id'); $t->unsignedBigInteger('warehouse_id'); $t->unsignedBigInteger('entered_unit_id');
            $t->decimal('entered_qty',18,4); $t->decimal('quantity',18,4); $t->decimal('unit_conversion_factor',20,8);
            $t->unsignedBigInteger('base_unit_id'); $t->unsignedBigInteger('unit_conversion_id')->nullable();
            $t->string('unit_conversion_version'); $t->char('unit_conversion_hash',64);
            $t->unsignedInteger('unit_conversion_precision'); $t->string('unit_conversion_rounding_mode');
            foreach (['variant_id','bin_id','lot_id','serial_id'] as $column) $t->unsignedBigInteger($column)->nullable();
            $t->decimal('actual_return_cost_base',24,6)->nullable(); $t->decimal('unit_cost',20,6)->nullable(); $t->timestamps();
            $t->unique(['supplier_return_id','source_stock_ledger_id'], 'supplier_return_line_source_unique');
            $t->index(['organization_id','goods_receipt_line_id'], 'supplier_return_line_source_index');
        });
    }
    public function down(): void {} // Audit, physical provenance and financial links survive code rollback.
};
