<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: Purchasing (PO + GRN) and Suppliers.
 * POs do NOT move stock. GRNs post stock IN via StockLedgerService.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        // SolaStock's OWN suppliers table — deliberately NOT named `suppliers`,
        // because Finance (SolaBooks) owns a `suppliers` table with a different
        // schema in the shared tenant DB. SolaStock must be installable with or
        // without Finance, so it keeps an independent supplier lifecycle. Optional
        // cross-app linking is a separate mapping table, never a shared schema.
        Schema::hasTable('inventory_suppliers') or Schema::create('inventory_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('central_supplier_ref')->nullable(); // optional Finance link (id), not a FK
            $table->string('code');
            $table->string('name');
            $table->json('contact')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
        });

        Schema::hasTable('inventory_purchase_orders') or Schema::create('inventory_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('po_number');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->enum('status', ['draft', 'approved', 'partially_received', 'received', 'closed', 'cancelled'])->default('draft');
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'po_number']);
            // Explicit short name — the auto-generated name
            // (inventory_purchase_orders_organization_id_supplier_id_status_index)
            // exceeds MySQL's 64-char identifier limit and broke fresh provisioning.
            $table->index(['organization_id', 'supplier_id', 'status'], 'inv_po_org_sup_status_idx');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('supplier_id')->references('id')->on('inventory_suppliers')->nullOnDelete();
        });

        Schema::hasTable('purchase_order_lines') or Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('ordered_qty', 18, 4);
            $table->decimal('received_qty', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('tax_code')->nullable();
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'purchase_order_id'], 'pol_org_po_idx');
            $table->foreign('purchase_order_id')->references('id')->on('inventory_purchase_orders')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('goods_receipts') or Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('grn_number');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->date('receipt_date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->string('posted_guard_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'grn_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('purchase_order_id')->references('id')->on('inventory_purchase_orders')->nullOnDelete();
        });

        Schema::hasTable('goods_receipt_lines') or Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('received_qty', 18, 4);
            $table->decimal('accepted_qty', 18, 4);
            $table->decimal('rejected_qty', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'goods_receipt_id'], 'grl_org_grn_idx');
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('inventory_purchase_orders');
        Schema::dropIfExists('inventory_suppliers'); // SolaStock's own table; NEVER Finance's `suppliers`
    }
};
