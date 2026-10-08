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
        if (! Schema::hasTable('inventory_customers')) {
            Schema::create('inventory_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('code', 50);
                $table->string('name');
                $table->json('contact')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['organization_id', 'code'], 'inv_customers_org_code_uniq');
                $table->index(['organization_id', 'is_active'], 'inv_customers_org_active_idx');
            });
        }

        if (Schema::hasTable('inventory_sales_orders')) {
            Schema::table('inventory_sales_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_sales_orders', 'customer_id')) {
                    $table->unsignedBigInteger('customer_id')->nullable()->after('order_number');
                }
                if (! Schema::hasColumn('inventory_sales_orders', 'subtotal')) {
                    $table->decimal('subtotal', 18, 2)->default(0)->after('status');
                }
                if (! Schema::hasColumn('inventory_sales_orders', 'discount_total')) {
                    $table->decimal('discount_total', 18, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('inventory_sales_orders', 'tax_total')) {
                    $table->decimal('tax_total', 18, 2)->default(0)->after('discount_total');
                }
                if (! Schema::hasColumn('inventory_sales_orders', 'total')) {
                    $table->decimal('total', 18, 2)->default(0)->after('tax_total');
                }
            });
        }

        if (Schema::hasTable('sales_order_lines')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_order_lines', 'discount_rate')) {
                    $table->decimal('discount_rate', 8, 4)->default(0)->after('unit_price');
                }
                if (! Schema::hasColumn('sales_order_lines', 'discount_amount')) {
                    $table->decimal('discount_amount', 18, 2)->default(0)->after('discount_rate');
                }
                if (! Schema::hasColumn('sales_order_lines', 'tax_code')) {
                    $table->string('tax_code', 50)->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('sales_order_lines', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 4)->default(0)->after('tax_code');
                }
                if (! Schema::hasColumn('sales_order_lines', 'tax_amount')) {
                    $table->decimal('tax_amount', 18, 2)->default(0)->after('tax_rate');
                }
                if (! Schema::hasColumn('sales_order_lines', 'line_total')) {
                    $table->decimal('line_total', 18, 2)->default(0)->after('tax_amount');
                }
            });
        }

        if (Schema::hasTable('sales_returns')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_returns', 'customer_id')) {
                    $table->unsignedBigInteger('customer_id')->nullable()->after('shipment_id');
                }
                if (! Schema::hasColumn('sales_returns', 'authorized_at')) {
                    $table->dateTime('authorized_at')->nullable()->after('posted_guard_key');
                }
                if (! Schema::hasColumn('sales_returns', 'authorized_by')) {
                    $table->unsignedBigInteger('authorized_by')->nullable()->after('authorized_at');
                }
                if (! Schema::hasColumn('sales_returns', 'inspected_at')) {
                    $table->dateTime('inspected_at')->nullable()->after('authorized_by');
                }
                if (! Schema::hasColumn('sales_returns', 'inspected_by')) {
                    $table->unsignedBigInteger('inspected_by')->nullable()->after('inspected_at');
                }
                if (! Schema::hasColumn('sales_returns', 'inspection_notes')) {
                    $table->text('inspection_notes')->nullable()->after('inspected_by');
                }
            });
        }

        if (Schema::hasTable('sales_return_lines')) {
            Schema::table('sales_return_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_return_lines', 'inspection_status')) {
                    $table->string('inspection_status', 30)->default('pending')->after('condition');
                }
                if (! Schema::hasColumn('sales_return_lines', 'disposition')) {
                    $table->string('disposition', 30)->default('restock')->after('inspection_status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_return_lines')) {
            Schema::table('sales_return_lines', function (Blueprint $table) {
                foreach (['disposition', 'inspection_status'] as $column) {
                    if (Schema::hasColumn('sales_return_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('sales_returns')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                foreach (['inspection_notes', 'inspected_by', 'inspected_at', 'authorized_by', 'authorized_at', 'customer_id'] as $column) {
                    if (Schema::hasColumn('sales_returns', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('sales_order_lines')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                foreach (['line_total', 'tax_amount', 'tax_rate', 'tax_code', 'discount_amount', 'discount_rate'] as $column) {
                    if (Schema::hasColumn('sales_order_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('inventory_sales_orders')) {
            Schema::table('inventory_sales_orders', function (Blueprint $table) {
                foreach (['total', 'tax_total', 'discount_total', 'subtotal', 'customer_id'] as $column) {
                    if (Schema::hasColumn('inventory_sales_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('inventory_customers');
    }
};
