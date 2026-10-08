<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_sales_orders')
            && ! Schema::hasColumn('inventory_sales_orders', 'integration_currency_code')) {
            Schema::table('inventory_sales_orders', function (Blueprint $table): void {
                $table->char('integration_currency_code', 3)->nullable()->after('status');
            });
        }
        if (Schema::hasTable('inventory_purchase_orders')
            && ! Schema::hasColumn('inventory_purchase_orders', 'integration_currency_code')) {
            Schema::table('inventory_purchase_orders', function (Blueprint $table): void {
                $table->char('integration_currency_code', 3)->nullable()->after('currency_code');
            });
        }

        if (! Schema::hasTable('integration_document_lifecycle_mappings')) {
            Schema::create('integration_document_lifecycle_mappings', function (Blueprint $table): void {
                $table->id();
                $table->uuid('mapping_uuid')->unique();
                $table->uuid('organization_mapping_uuid');
                $table->unsignedBigInteger('central_client_id');
                $table->unsignedBigInteger('central_organization_id');
                $table->string('tenant_database_identity', 191);
                $table->unsignedBigInteger('finance_organization_id');
                $table->unsignedBigInteger('solastock_organization_id');
                $table->string('source_application', 24);
                $table->string('source_document_type', 48);
                $table->string('source_document_id', 80);
                $table->string('destination_application', 24)->nullable();
                $table->string('destination_document_type', 48)->nullable();
                $table->string('destination_document_id', 80)->nullable();
                $table->string('document_version', 40)->default('phase3.v1');
                $table->uuid('parent_mapping_uuid')->nullable();
                $table->uuid('original_mapping_uuid')->nullable();
                $table->uuid('reversal_mapping_uuid')->nullable();
                $table->string('lifecycle_status', 40);
                $table->decimal('ordered_qty', 20, 4)->default(0);
                $table->decimal('reserved_qty', 20, 4)->default(0);
                $table->decimal('received_qty', 20, 4)->default(0);
                $table->decimal('billed_qty', 20, 4)->default(0);
                $table->decimal('shipped_qty', 20, 4)->default(0);
                $table->decimal('invoiced_qty', 20, 4)->default(0);
                $table->decimal('returned_qty', 20, 4)->default(0);
                $table->char('transaction_currency_code', 3)->nullable();
                $table->char('base_currency_code', 3);
                $table->decimal('exchange_rate', 24, 12)->nullable();
                $table->date('exchange_rate_date')->nullable();
                $table->decimal('transaction_subtotal', 24, 6)->nullable();
                $table->decimal('transaction_tax', 24, 6)->nullable();
                $table->decimal('transaction_total', 24, 6)->nullable();
                $table->decimal('base_subtotal', 24, 6)->nullable();
                $table->decimal('base_tax', 24, 6)->nullable();
                $table->decimal('base_total', 24, 6)->nullable();
                $table->decimal('inventory_valuation_effect', 24, 6)->nullable();
                $table->string('accounting_source_key', 191)->nullable();
                $table->unsignedBigInteger('finance_journal_id')->nullable();
                $table->string('matching_state', 40)->default('unmatched');
                $table->string('conflict_code', 80)->nullable();
                $table->json('error_state')->nullable();
                $table->json('safe_metadata')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_mapping_uuid', 'source_application', 'source_document_type', 'source_document_id'],
                    'idlm_source_identity_uniq'
                );
                $table->unique(
                    ['organization_mapping_uuid', 'destination_application', 'destination_document_type', 'destination_document_id'],
                    'idlm_destination_identity_uniq'
                );
                $table->index(
                    ['organization_mapping_uuid', 'source_document_type', 'lifecycle_status'],
                    'idlm_org_type_status_idx'
                );
                $table->index(['parent_mapping_uuid', 'matching_state'], 'idlm_parent_match_idx');
                $table->index(['original_mapping_uuid', 'reversal_mapping_uuid'], 'idlm_reversal_idx');
            });
        }

        if (! Schema::hasTable('integration_document_lifecycle_audits')) {
            Schema::create('integration_document_lifecycle_audits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('organization_mapping_uuid');
                $table->uuid('mapping_uuid');
                $table->string('action', 60);
                $table->char('before_hash', 64)->nullable();
                $table->char('after_hash', 64);
                $table->json('safe_metadata')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(
                    ['organization_mapping_uuid', 'created_at'],
                    'idla_org_created_idx'
                );
                $table->index(['mapping_uuid', 'created_at'], 'idla_mapping_created_idx');
            });
        }

        if (! Schema::hasTable('integration_document_lifecycle_links')) {
            Schema::create('integration_document_lifecycle_links', function (Blueprint $table): void {
                $table->id();
                $table->uuid('organization_mapping_uuid');
                $table->uuid('from_mapping_uuid');
                $table->uuid('to_mapping_uuid');
                $table->string('relationship_type', 48);
                $table->decimal('quantity', 20, 4)->default(0);
                $table->json('safe_metadata')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_mapping_uuid', 'from_mapping_uuid', 'to_mapping_uuid', 'relationship_type'],
                    'idll_identity_uniq'
                );
                $table->index(
                    ['organization_mapping_uuid', 'from_mapping_uuid', 'relationship_type'],
                    'idll_from_relationship_idx'
                );
                $table->index(
                    ['organization_mapping_uuid', 'to_mapping_uuid', 'relationship_type'],
                    'idll_to_relationship_idx'
                );
            });
        }
    }

    public function down(): void
    {
        // Permanent additive workflow identity and audit evidence.
    }
};
