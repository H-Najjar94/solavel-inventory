<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: Sales Fulfillment (fulfillment documents only — SolaStock
 * does NOT own invoices/AR/accounting).
 *   sales_orders(+lines)  pick_lists(+lines)  packs(+lines)
 *   shipments(+lines)     sales_returns(+lines)
 *
 * Only shipment posting (OUT) and resellable return posting (IN) move stock,
 * via StockLedgerService. Reservation lives on stock_balances.reserved_qty.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::hasTable('inventory_sales_orders') or Schema::create('inventory_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('order_number');
            $table->string('customer_name')->nullable();
            $table->string('customer_external_id')->nullable();
            $table->string('source_app')->default('manual'); // manual | solabooks
            $table->string('source_document_id')->nullable();
            $table->string('source_document_number')->nullable();
            $table->date('order_date');
            $table->date('requested_ship_date')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            // draft|confirmed|partially_reserved|reserved|picking|partially_picked|
            // picked|packing|packed|partially_shipped|shipped|cancelled
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::hasTable('sales_order_lines') or Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('ordered_qty', 18, 4);
            $table->decimal('reserved_qty', 18, 4)->default(0);
            $table->decimal('picked_qty', 18, 4)->default(0);
            $table->decimal('packed_qty', 18, 4)->default(0);
            $table->decimal('shipped_qty', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
            $table->index(['organization_id', 'sales_order_id'], 'sol_org_so_idx');
            $table->foreign('sales_order_id')->references('id')->on('inventory_sales_orders')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('pick_lists') or Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('pick_number');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('picker_user_id')->nullable();
            $table->string('status')->default('draft'); // draft|picking|picked|cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'pick_number']);
            $table->foreign('sales_order_id')->references('id')->on('inventory_sales_orders');
        });

        Schema::hasTable('pick_list_lines') or Schema::create('pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('pick_list_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('reserved_qty', 18, 4)->default(0);
            $table->decimal('picked_qty', 18, 4)->default(0);
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
            $table->index(['organization_id', 'pick_list_id'], 'pll_org_pl_idx');
            $table->foreign('pick_list_id')->references('id')->on('pick_lists')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('packs') or Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('pack_number');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('pick_list_id')->nullable();
            $table->string('status')->default('draft'); // draft|packed|cancelled
            $table->unsignedInteger('package_count')->default(1);
            $table->decimal('package_weight', 18, 4)->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'pack_number']);
            $table->foreign('sales_order_id')->references('id')->on('inventory_sales_orders');
        });

        Schema::hasTable('pack_lines') or Schema::create('pack_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('pack_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->decimal('picked_qty', 18, 4)->default(0);
            $table->decimal('packed_qty', 18, 4)->default(0);
            $table->unsignedInteger('package_number')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'pack_id'], 'packl_org_p_idx');
            $table->foreign('pack_id')->references('id')->on('packs')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('shipments') or Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('shipment_number');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('pack_id')->nullable();
            $table->date('ship_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->default('draft'); // draft|posted|cancelled
            $table->string('posted_guard_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'shipment_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('sales_order_id')->references('id')->on('inventory_sales_orders');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::hasTable('shipment_lines') or Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'shipment_id'], 'shl_org_s_idx');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('sales_returns') or Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('return_number');
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->date('return_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status')->default('draft'); // draft|posted|cancelled
            $table->string('reason')->nullable();
            $table->string('posted_guard_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'return_number']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::hasTable('sales_return_lines') or Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->decimal('returned_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('condition')->default('resellable'); // resellable|damaged|quarantine
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'sales_return_id'], 'srl_org_r_idx');
            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('pack_lines');
        Schema::dropIfExists('packs');
        Schema::dropIfExists('pick_list_lines');
        Schema::dropIfExists('pick_lists');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('inventory_sales_orders');
    }
};
