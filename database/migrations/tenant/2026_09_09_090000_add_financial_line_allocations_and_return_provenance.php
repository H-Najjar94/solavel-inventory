<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        if (! Schema::hasTable('integration_financial_line_allocations')) {
            Schema::create('integration_financial_line_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('allocation_uuid')->unique();
                $table->uuid('organization_mapping_uuid');
                $table->unsignedBigInteger('central_client_id');
                $table->unsignedBigInteger('central_organization_id');
                $table->string('tenant_database_identity', 191);
                $table->unsignedBigInteger('finance_organization_id');
                $table->unsignedBigInteger('solastock_organization_id');
                $table->uuid('source_document_mapping_uuid');
                $table->string('source_document_type', 48);
                $table->string('source_document_id', 80);
                $table->unsignedBigInteger('source_line_id');
                $table->string('destination_document_type', 48);
                $table->unsignedBigInteger('destination_document_id');
                $table->unsignedBigInteger('destination_line_id');
                $table->string('allocation_kind', 24); // bill|invoice|supplier_credit|customer_credit
                $table->decimal('entered_quantity', 24, 8);
                $table->unsignedBigInteger('entered_unit_id')->nullable();
                $table->decimal('base_quantity', 24, 8);
                $table->unsignedBigInteger('base_unit_id')->nullable();
                $table->decimal('destination_quantity', 24, 8);
                $table->unsignedBigInteger('destination_unit_id')->nullable();
                $table->unsignedBigInteger('unit_conversion_id')->nullable();
                $table->decimal('unit_conversion_factor', 24, 12)->default(1);
                $table->string('unit_conversion_version', 40)->nullable();
                $table->char('unit_conversion_hash', 64)->nullable();
                $table->unsignedTinyInteger('unit_conversion_precision')->default(4);
                $table->string('unit_conversion_rounding_mode', 24)->default('half_up');
                $table->decimal('source_unit_price', 24, 8);
                $table->decimal('destination_unit_price', 24, 8);
                $table->decimal('source_gross', 24, 8);
                $table->decimal('destination_gross', 24, 8);
                $table->decimal('line_discount_allocated', 24, 8)->default(0);
                $table->decimal('document_discount_allocated', 24, 8)->default(0);
                $table->decimal('source_net', 24, 8);
                $table->decimal('destination_net', 24, 8);
                $table->decimal('price_difference', 24, 8)->default(0);
                $table->char('currency_code', 3);
                $table->char('base_currency_code', 3);
                $table->decimal('exchange_rate', 24, 12);
                $table->string('state', 24)->default('draft_reserved');
                $table->string('destination_revision', 80);
                $table->char('source_fingerprint', 64);
                $table->char('destination_fingerprint', 64);
                $table->string('idempotency_key', 191);
                $table->timestamp('reserved_until')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->json('safe_metadata')->nullable();
                $table->timestamps();

                $table->unique(['organization_mapping_uuid', 'idempotency_key'], 'ifla_org_idem_uniq');
                $table->index([
                    'organization_mapping_uuid', 'destination_document_type', 'destination_document_id',
                    'destination_line_id', 'source_document_mapping_uuid', 'source_line_id',
                ], 'ifla_destination_source_line_idx');
                $table->index([
                    'organization_mapping_uuid', 'source_document_mapping_uuid', 'source_line_id', 'state',
                ], 'ifla_source_available_idx');
                $table->index([
                    'organization_mapping_uuid', 'destination_document_type', 'destination_document_id', 'state',
                ], 'ifla_destination_state_idx');
            });
        }

        if (Schema::hasTable('sales_order_lines')) {
            Schema::table('sales_order_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales_order_lines', 'entered_qty')) $table->decimal('entered_qty', 18, 4)->nullable()->after('ordered_qty');
                if (! Schema::hasColumn('sales_order_lines', 'entered_unit_id')) $table->unsignedBigInteger('entered_unit_id')->nullable()->after('entered_qty');
                if (! Schema::hasColumn('sales_order_lines', 'base_unit_id')) $table->unsignedBigInteger('base_unit_id')->nullable()->after('entered_unit_id');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_id')) $table->unsignedBigInteger('unit_conversion_id')->nullable()->after('base_unit_id');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_factor')) $table->decimal('unit_conversion_factor', 18, 8)->nullable()->after('unit_conversion_id');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_version')) $table->string('unit_conversion_version', 64)->nullable()->after('unit_conversion_factor');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_hash')) $table->char('unit_conversion_hash', 64)->nullable()->after('unit_conversion_version');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_precision')) $table->unsignedTinyInteger('unit_conversion_precision')->nullable()->after('unit_conversion_hash');
                if (! Schema::hasColumn('sales_order_lines', 'unit_conversion_rounding_mode')) $table->string('unit_conversion_rounding_mode', 16)->nullable()->after('unit_conversion_precision');
            });
        }

        if (Schema::hasTable('sales_return_lines')) {
            Schema::table('sales_return_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales_return_lines', 'source_shipment_line_id')) {
                    $table->unsignedBigInteger('source_shipment_line_id')->nullable()->after('sales_return_id');
                }
                if (! Schema::hasColumn('sales_return_lines', 'source_stock_ledger_id')) {
                    $table->unsignedBigInteger('source_stock_ledger_id')->nullable()->after('source_shipment_line_id');
                }
            });
            if (! $this->indexExists('sales_return_lines', 'srl_org_source_provenance_idx')) {
                Schema::table('sales_return_lines', fn (Blueprint $table) => $table->index(
                    ['organization_id', 'source_shipment_line_id', 'source_stock_ledger_id'],
                    'srl_org_source_provenance_idx'
                ));
            }
        }
        if (Schema::hasTable('sales_returns')) {
            Schema::table('sales_returns', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales_returns', 'reversal_id')) {
                    $table->unsignedBigInteger('reversal_id')->nullable()->after('posted_guard_key');
                }
                if (! Schema::hasColumn('sales_returns', 'reversed_by')) {
                    $table->unsignedBigInteger('reversed_by')->nullable()->after('reversal_id');
                }
                if (! Schema::hasColumn('sales_returns', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable()->after('reversed_by');
                }
            });
            if (! $this->indexExists('sales_returns', 'sr_org_reversal_idx')) {
                Schema::table('sales_returns', fn (Blueprint $table) => $table->index(
                    ['organization_id', 'reversal_id'], 'sr_org_reversal_idx'
                ));
            }
        }
    }

    public function down(): void
    {
        // Allocation and provenance records are permanent accounting evidence.
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection($this->getConnection());
        $database = $connection->getDatabaseName();

        return $connection->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
