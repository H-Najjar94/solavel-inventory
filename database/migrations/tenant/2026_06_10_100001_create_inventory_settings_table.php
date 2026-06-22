<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration. Per-org inventory policy. Although each tenant has its own
 * database, every row still carries organization_id for provenance and as a
 * defense-in-depth scope key (matches the rest of the schema).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->enum('default_costing_method', ['average', 'fifo', 'standard'])->default('average');
            $table->boolean('allow_negative_stock')->default(false);
            // decimal tolerance used by the integrity checker for value comparisons
            $table->decimal('value_tolerance', 18, 4)->default(0.0100);
            $table->json('numbering')->nullable();
            $table->json('barcode')->nullable();
            $table->json('approvals')->nullable();
            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_settings');
    }
};
