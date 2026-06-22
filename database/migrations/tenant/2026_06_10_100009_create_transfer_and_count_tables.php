<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: Stock Transfers and Stock Counts.
 * Transfers post OUT(source)+IN(dest) via StockLedgerService.
 * Counts post variances by creating a StockAdjustment (single adjustment path).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::hasTable('stock_transfers') or Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('transfer_number');
            $table->date('transfer_date');
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->enum('status', ['draft', 'posted', 'in_transit', 'received', 'reversed', 'cancelled'])->default('draft');
            $table->string('posted_guard_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'transfer_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses');
        });

        Schema::hasTable('stock_transfer_lines') or Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('from_bin_id')->nullable();
            $table->unsignedBigInteger('to_bin_id')->nullable();
            $table->decimal('received_qty', 18, 4)->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'stock_transfer_id'], 'stl_org_t_idx');
            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('stock_counts') or Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('count_number');
            $table->enum('count_type', ['cycle', 'full'])->default('cycle');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->enum('status', ['draft', 'counting', 'review', 'posted', 'cancelled'])->default('draft');
            $table->string('posted_guard_key')->nullable()->unique();
            // The adjustment this count posted its variances through (provenance).
            $table->unsignedBigInteger('adjustment_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'count_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::hasTable('stock_count_lines') or Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('stock_count_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('system_qty', 18, 4)->default(0);
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('variance_qty', 18, 4)->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'stock_count_id'], 'scl_org_c_idx');
            $table->foreign('stock_count_id')->references('id')->on('stock_counts')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
