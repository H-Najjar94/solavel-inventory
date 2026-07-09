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
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'weight')) {
                $table->decimal('weight', 18, 4)->nullable()->after('reorder_qty');
            }
            if (! Schema::hasColumn('items', 'length')) {
                $table->decimal('length', 18, 4)->nullable()->after('weight');
            }
            if (! Schema::hasColumn('items', 'width')) {
                $table->decimal('width', 18, 4)->nullable()->after('length');
            }
            if (! Schema::hasColumn('items', 'height')) {
                $table->decimal('height', 18, 4)->nullable()->after('width');
            }
            if (! Schema::hasColumn('items', 'min_stock')) {
                $table->decimal('min_stock', 18, 4)->nullable()->after('height');
            }
            if (! Schema::hasColumn('items', 'max_stock')) {
                $table->decimal('max_stock', 18, 4)->nullable()->after('min_stock');
            }
            if (! Schema::hasColumn('items', 'safety_stock')) {
                $table->decimal('safety_stock', 18, 4)->nullable()->after('max_stock');
            }
        });

        if (! Schema::hasTable('supplier_price_lists')) {
            Schema::create('supplier_price_lists', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('supplier_id');
                $table->string('supplier_sku')->nullable();
                $table->decimal('unit_cost', 18, 4);
                $table->decimal('minimum_qty', 18, 4)->default(1);
                $table->string('currency_code', 3)->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['organization_id', 'item_id']);
                $table->index(['organization_id', 'supplier_id']);
            });
        }

        if (! Schema::hasTable('item_attachments')) {
            Schema::create('item_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('item_id');
                $table->string('name');
                $table->string('path');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->timestamps();

                $table->index(['organization_id', 'item_id']);
            });
        }

        if (! Schema::hasTable('warehouse_reorder_rules')) {
            Schema::create('warehouse_reorder_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->decimal('reorder_point', 18, 4)->nullable();
                $table->decimal('reorder_qty', 18, 4)->nullable();
                $table->decimal('min_stock', 18, 4)->nullable();
                $table->decimal('max_stock', 18, 4)->nullable();
                $table->decimal('safety_stock', 18, 4)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'item_id', 'warehouse_id'], 'warehouse_reorder_rules_org_item_wh_uniq');
            });
        }

        if (! Schema::hasTable('inventory_scheduled_reports')) {
            Schema::create('inventory_scheduled_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('report_key');
                $table->string('name');
                $table->json('filters')->nullable();
                $table->json('recipients')->nullable();
                $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('weekly');
                $table->string('format')->default('csv');
                $table->timestamp('next_run_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['organization_id', 'is_active', 'next_run_at'], 'scheduled_reports_org_active_next_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_scheduled_reports');
        Schema::dropIfExists('warehouse_reorder_rules');
        Schema::dropIfExists('item_attachments');
        Schema::dropIfExists('supplier_price_lists');

        Schema::table('items', function (Blueprint $table) {
            foreach (['weight', 'length', 'width', 'height', 'min_stock', 'max_stock', 'safety_stock'] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
