<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SolaStock-owned document materialising a SolaPOS retail sale (or return)
 * consumption. SolaStock is the writer of record: it posts the stock ledger,
 * applies canonical costing and emits the COGS/inventory-asset journal through
 * its existing SolaBooks bridge. Idempotent by (organization, source key).
 */
return new class extends Migration
{
    private function conn(): string
    {
        return (string) config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        if (Schema::connection($this->conn())->hasTable('pos_sale_consumptions')) {
            return;
        }
        Schema::connection($this->conn())->create('pos_sale_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('source_app', 20)->default('solapos');
            $table->string('source_key', 160);            // solapos idempotency key
            $table->string('event_type', 40);             // pos_sale.consumed | pos_return.restored
            $table->string('direction', 3);               // out | in
            $table->unsignedBigInteger('pos_order_id')->nullable();
            $table->unsignedBigInteger('pos_order_return_id')->nullable();
            $table->unsignedBigInteger('reverses_consumption_id')->nullable();
            $table->string('reference', 60)->nullable();  // POS order/return number
            $table->date('transaction_date');
            $table->string('status', 20)->default('posted');
            $table->unsignedInteger('contract_version')->default(1);
            $table->json('payload');
            $table->json('result')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source_key'], 'pos_sale_consumptions_org_source_unique');
            $table->index(['organization_id', 'pos_order_id'], 'pos_sale_consumptions_order_idx');
        });
        Schema::connection($this->conn())->create('pos_sale_consumption_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('pos_sale_consumption_id')->constrained('pos_sale_consumptions')->cascadeOnDelete();
            $table->unsignedBigInteger('pos_order_item_id')->nullable();
            $table->unsignedBigInteger('pos_allocation_id')->nullable();
            $table->unsignedBigInteger('pos_order_return_item_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('item_variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_cost', 18, 4)->nullable();
            $table->string('costing_method', 20)->nullable();
            $table->unsignedBigInteger('ledger_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->conn())->dropIfExists('pos_sale_consumption_lines');
        Schema::connection($this->conn())->dropIfExists('pos_sale_consumptions');
    }
};
