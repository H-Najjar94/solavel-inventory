<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock landed-cost documents (freight, duty, insurance applied to posted
 * receipt lines). Additive only; Finance's own landed_cost* tables are untouched.
 */
return new class extends Migration {
    public function getConnection(): ?string { return config('tenancy.tenant_connection', 'tenant'); }

    public function up(): void
    {
        $s = Schema::connection('tenant');
        if (! $s->hasTable('stock_landed_costs')) {
            $s->create('stock_landed_costs', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->string('landed_cost_number', 80);
                $t->date('landed_cost_date');
                $t->string('status', 20)->default('draft');
                $t->string('allocation_method', 20)->default('value');
                $t->char('currency_code', 3);
                $t->char('base_currency_code', 3)->nullable();
                // Finance convention: 1 base unit = exchange_rate transaction units.
                $t->decimal('exchange_rate', 24, 12)->default(1);
                $t->string('supplier_reference', 120)->nullable();
                $t->text('notes')->nullable();
                $t->decimal('total_amount', 20, 4)->default(0);
                $t->decimal('total_base_amount', 20, 2)->default(0);
                $t->decimal('inventory_base_amount', 20, 2)->default(0);
                $t->decimal('consumed_base_amount', 20, 2)->default(0);
                $t->unsignedBigInteger('ledger_watermark')->nullable();
                $t->string('posted_guard_key', 191)->nullable();
                $t->unsignedBigInteger('posted_by')->nullable();
                $t->dateTime('posted_at')->nullable();
                $t->uuid('event_uuid')->nullable();
                $t->unsignedBigInteger('reversal_id')->nullable();
                $t->unsignedBigInteger('reversed_by')->nullable();
                $t->dateTime('reversed_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['organization_id', 'landed_cost_number'], 'slc_org_number_unique');
                $t->unique('posted_guard_key', 'slc_posted_guard_unique');
                $t->index(['organization_id', 'status'], 'slc_org_status_index');
            });
        }
        if (! $s->hasTable('stock_landed_cost_charges')) {
            $s->create('stock_landed_cost_charges', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('landed_cost_id');
                $t->string('charge_type', 20);
                $t->string('description', 255)->nullable();
                $t->decimal('amount', 20, 4);
                $t->decimal('base_amount', 20, 2);
                $t->timestamps();
                $t->index(['organization_id', 'landed_cost_id'], 'slcc_document_index');
            });
        }
        if (! $s->hasTable('stock_landed_cost_lines')) {
            $s->create('stock_landed_cost_lines', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('landed_cost_id');
                $t->unsignedBigInteger('goods_receipt_id');
                $t->unsignedBigInteger('goods_receipt_line_id');
                $t->unsignedBigInteger('item_id');
                $t->unsignedBigInteger('warehouse_id');
                $t->decimal('quantity', 18, 4);
                $t->decimal('receipt_value', 20, 2);
                $t->decimal('weight', 18, 4)->nullable();
                $t->decimal('allocated_base_amount', 20, 2)->default(0);
                $t->decimal('inventory_base_amount', 20, 2)->default(0);
                $t->decimal('consumed_base_amount', 20, 2)->default(0);
                $t->timestamps();
                $t->unique(['landed_cost_id', 'goods_receipt_line_id'], 'slcl_document_line_unique');
                $t->index(['organization_id', 'goods_receipt_line_id'], 'slcl_receipt_line_index');
            });
        }
        if (! $s->hasTable('stock_landed_cost_components')) {
            $s->create('stock_landed_cost_components', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('landed_cost_id');
                $t->unsignedBigInteger('landed_cost_line_id');
                $t->uuid('allocation_uuid');
                $t->unsignedBigInteger('stock_ledger_id');
                $t->unsignedBigInteger('item_id');
                $t->unsignedBigInteger('warehouse_id');
                $t->string('destination_role', 64);
                $t->string('destination_source_type')->nullable();
                $t->unsignedBigInteger('destination_source_id')->nullable();
                $t->decimal('base_quantity', 24, 8);
                $t->decimal('exact_base_amount', 24, 8);
                $t->decimal('posted_base_amount', 20, 2);
                $t->json('provenance');
                $t->timestamps();
                $t->unique(['landed_cost_id', 'allocation_uuid', 'stock_ledger_id', 'destination_role'], 'slcp_component_unique');
                $t->index(['organization_id', 'item_id', 'warehouse_id'], 'slcp_stock_scope_index');
            });
        }
    }

    public function down(): void { /* permanent valuation evidence */ }
};
