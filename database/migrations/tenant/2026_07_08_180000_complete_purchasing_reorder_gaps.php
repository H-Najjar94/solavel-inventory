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
        if (! Schema::hasTable('purchase_order_backorders')) {
            Schema::create('purchase_order_backorders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('purchase_order_id');
                $table->unsignedBigInteger('purchase_order_line_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->decimal('ordered_qty', 18, 4);
                $table->decimal('received_qty', 18, 4)->default(0);
                $table->decimal('backorder_qty', 18, 4);
                $table->decimal('unit_price', 18, 4)->default(0);
                $table->date('expected_date')->nullable();
                $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'purchase_order_line_id'], 'po_backorders_org_line_uniq');
                $table->index(['organization_id', 'status', 'expected_date'], 'po_backorders_org_status_due_idx');
                $table->foreign('purchase_order_id')->references('id')->on('inventory_purchase_orders')->cascadeOnDelete();
                $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('items');
                $table->foreign('warehouse_id')->references('id')->on('warehouses');
                $table->foreign('supplier_id')->references('id')->on('inventory_suppliers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_backorders');
    }
};
