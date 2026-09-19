<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string { return config('tenancy.tenant_connection', 'tenant'); }

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('integration_purchase_cost_adjustments')) {
            Schema::connection('tenant')->create('integration_purchase_cost_adjustments', function (Blueprint $t): void {
                $t->id(); $t->uuid('adjustment_uuid')->unique(); $t->uuid('organization_mapping_uuid');
                $t->unsignedBigInteger('organization_id'); $t->string('destination_document_type', 48);
                $t->unsignedBigInteger('destination_document_id'); $t->char('destination_fingerprint', 64);
                $t->char('currency_code', 3); $t->char('base_currency_code', 3); $t->decimal('exchange_rate', 24, 12);
                $t->unsignedTinyInteger('finance_money_scale'); $t->unsignedTinyInteger('stock_money_scale')->default(2);
                $t->decimal('exact_base_difference', 24, 8); $t->decimal('allocated_base_difference', 24, 8);
                $t->decimal('rounding_residual', 20, 6)->default(0); $t->decimal('rounding_bound', 20, 6);
                $t->string('state', 24)->default('prepared'); $t->string('idempotency_key', 191);
                $t->timestamp('applied_at')->nullable(); $t->timestamp('reversed_at')->nullable(); $t->json('safe_metadata')->nullable(); $t->timestamps();
                $t->unique(['organization_mapping_uuid', 'idempotency_key'], 'ipca_org_idem_uniq');
                $t->index(['organization_mapping_uuid', 'destination_document_type', 'destination_document_id'], 'ipca_destination_idx');
            });
        }
        if (! Schema::connection('tenant')->hasTable('integration_purchase_cost_adjustment_components')) {
            Schema::connection('tenant')->create('integration_purchase_cost_adjustment_components', function (Blueprint $t): void {
                $t->id(); $t->uuid('adjustment_uuid'); $t->uuid('allocation_uuid');
                $t->unsignedBigInteger('organization_id'); $t->unsignedBigInteger('stock_ledger_id')->nullable();
                $t->unsignedBigInteger('item_id'); $t->unsignedBigInteger('warehouse_id')->nullable();
                $t->string('destination_role', 64); $t->string('destination_source_type')->nullable(); $t->unsignedBigInteger('destination_source_id')->nullable();
                $t->decimal('base_quantity', 24, 8); $t->decimal('exact_base_amount', 24, 8); $t->decimal('posted_base_amount', 20, 6);
                $t->json('provenance'); $t->timestamps();
                $t->unique(['adjustment_uuid', 'allocation_uuid', 'stock_ledger_id', 'destination_role'], 'ipcac_component_uniq');
                $t->index(['organization_id', 'item_id', 'warehouse_id'], 'ipcac_stock_scope_idx');
            });
        }
    }

    public function down(): void { /* permanent accounting evidence */ }
};
