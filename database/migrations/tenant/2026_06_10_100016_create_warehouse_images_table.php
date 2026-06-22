<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: warehouse images (private media). Mirrors item_images —
 * org-first indexed, one primary per warehouse, sortable. Files themselves live
 * on the PRIVATE disk (storage/app/private), never the public disk; only the
 * `path` is stored here. Idempotent so a partial-failure re-run is safe.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::hasTable('warehouse_images') or Schema::create('warehouse_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'warehouse_id'], 'whimg_org_wh_idx');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_images');
    }
};
