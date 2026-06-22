<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: warehouses → zones → bins.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('code');
            $table->string('name');
            $table->enum('type', ['warehouse', 'retail', 'transit', 'virtual'])->default('warehouse');
            $table->json('address')->nullable();
            $table->decimal('max_capacity_units', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('keeper_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'code']);
            $table->index(['organization_id', 'warehouse_id']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });

        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('warehouse_id'); // denormalized for fast filter
            $table->unsignedBigInteger('zone_id');
            $table->string('code');
            $table->string('name')->nullable();
            $table->json('coords')->nullable(); // for 2D/3D map later
            $table->decimal('capacity', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['zone_id', 'code']);
            $table->index(['organization_id', 'warehouse_id', 'zone_id'], 'bins_org_wh_zone_idx');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('zone_id')->references('id')->on('warehouse_zones')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_zones');
        Schema::dropIfExists('warehouses');
    }
};
