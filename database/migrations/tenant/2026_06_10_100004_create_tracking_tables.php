<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: lots (batch + expiry) and serial_numbers (first-class).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('lot_code');
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'item_id', 'lot_code'], 'lots_org_item_code_uniq');
            $table->index(['organization_id', 'item_id', 'expiry_date'], 'lots_org_item_expiry_idx');
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('item_variants')->nullOnDelete();
        });

        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('serial');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->enum('status', ['pending', 'in_stock', 'reserved', 'sold', 'returned', 'scrapped'])->default('pending');
            $table->unsignedBigInteger('warehouse_id')->nullable(); // current location
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'item_id', 'serial'], 'serials_org_item_serial_uniq');
            $table->index(['organization_id', 'item_id', 'status'], 'serials_org_item_status_idx');
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('item_variants')->nullOnDelete();
            $table->foreign('lot_id')->references('id')->on('lots')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('lots');
    }
};
