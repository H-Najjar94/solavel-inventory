<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: Phase-1 stock documents.
 *   opening_stock_entries / _lines
 *   stock_adjustments / _lines   (ONE adjustment model only)
 *
 * Each header has status draft|posted|reversed|cancelled, a unique
 * posted_guard_key (idempotency for posting), and journal_ref (set later by
 * Finance integration — Phase 7). Lines carry full provenance for the ledger.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('opening_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('entry_number');
            $table->date('opening_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->enum('status', ['draft', 'posted', 'reversed', 'cancelled'])->default('draft');
            $table->decimal('total_value', 18, 2)->default(0);
            $table->string('posted_guard_key')->nullable()->unique();
            $table->string('journal_ref')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'entry_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::create('opening_stock_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('opening_stock_entry_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'opening_stock_entry_id'], 'osl_org_entry_idx');
            $table->foreign('opening_stock_entry_id')->references('id')->on('opening_stock_entries')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('adjustment_number');
            $table->date('adjustment_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('reason_code')->nullable();
            $table->enum('status', ['draft', 'posted', 'reversed', 'cancelled'])->default('draft');
            $table->decimal('total_increase_value', 18, 2)->default(0);
            $table->decimal('total_decrease_value', 18, 2)->default(0);
            $table->string('posted_guard_key')->nullable()->unique();
            $table->string('journal_ref')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'adjustment_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->enum('direction', ['increase', 'decrease']);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0); // used for increases
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->string('account_ref')->nullable(); // variance acct ref (Finance)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'stock_adjustment_id'], 'sal_org_adj_idx');
            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('opening_stock_entry_lines');
        Schema::dropIfExists('opening_stock_entries');
    }
};
