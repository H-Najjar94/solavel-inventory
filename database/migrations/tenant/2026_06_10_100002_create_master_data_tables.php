<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: master data (units, conversions, categories, brands, items,
 * variants, barcodes, images). All organization-first indexed; quantities/money
 * use decimal(18,4)/(18,2).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('code');
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->enum('kind', ['count', 'weight', 'volume', 'length'])->default('count');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id')->nullable(); // null = global conversion
            $table->unsignedBigInteger('from_unit_id');
            $table->unsignedBigInteger('to_unit_id');
            $table->decimal('factor', 18, 8); // 1 from_unit = factor * to_unit
            $table->timestamps();

            $table->unique(['organization_id', 'item_id', 'from_unit_id', 'to_unit_id'], 'uc_org_item_from_to_uniq');
            $table->index(['organization_id', 'item_id']);
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'parent_id']);
            $table->unique(['organization_id', 'parent_id', 'name'], 'item_categories_org_parent_name_uniq');
        });

        Schema::create('item_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('sku');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('item_type', ['inventory', 'non_inventory', 'service'])->default('inventory');
            $table->enum('tracking_type', ['none', 'lot', 'serial', 'lot_serial'])->default('none');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('base_unit_id')->nullable();
            // null → fall back to inventory_settings.default_costing_method
            $table->enum('costing_method', ['average', 'fifo', 'standard'])->nullable();
            $table->boolean('is_variant_parent')->default(false);
            $table->decimal('reorder_point', 18, 4)->nullable();
            $table->decimal('reorder_qty', 18, 4)->nullable();
            $table->boolean('enable_reorder_alert')->default(false);
            $table->unsignedBigInteger('preferred_supplier_id')->nullable();
            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('sales_price', 18, 4)->default(0);
            $table->string('tax_code')->nullable();
            // Finance account *refs* (opaque ids; SolaStock never owns the COA)
            $table->string('inventory_account_ref')->nullable();
            $table->string('cogs_account_ref')->nullable();
            $table->string('income_account_ref')->nullable();
            $table->string('purchase_account_ref')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'category_id']);

            $table->foreign('category_id')->references('id')->on('item_categories')->nullOnDelete();
            $table->foreign('brand_id')->references('id')->on('item_brands')->nullOnDelete();
            $table->foreign('base_unit_id')->references('id')->on('units')->nullOnDelete();
        });

        Schema::create('item_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->string('sku');
            $table->json('variant_attributes')->nullable();
            $table->string('barcode_primary')->nullable();
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->decimal('sales_price', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'item_id']);
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
        });

        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('barcode');
            $table->string('type')->nullable(); // EAN/UPC/internal
            $table->timestamps();

            $table->unique(['organization_id', 'barcode']);
            $table->index(['organization_id', 'item_id']);
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('item_variants')->nullOnDelete();
        });

        Schema::create('item_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'item_id']);
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('item_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_images');
        Schema::dropIfExists('item_barcodes');
        Schema::dropIfExists('item_variants');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_brands');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('units');
    }
};
