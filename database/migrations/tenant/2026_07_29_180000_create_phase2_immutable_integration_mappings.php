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
        if (! Schema::hasTable('integration_organization_mappings')) {
            Schema::create('integration_organization_mappings', function (Blueprint $table): void {
                $table->id();
                $table->uuid('mapping_uuid')->unique();
                $table->unsignedBigInteger('central_client_id');
                $table->unsignedBigInteger('central_organization_id');
                $table->string('tenant_database_identity', 191);
                $table->unsignedBigInteger('finance_organization_id');
                $table->unsignedBigInteger('solastock_organization_id');
                $table->string('integration', 40)->default('solabooks');
                $table->string('contract_version', 40)->default('solastock-journal.v2');
                $table->string('status', 40)->default('prepared_hold');
                $table->string('activation_state', 40)->default('maintenance_hold');
                $table->char('base_currency_code', 3);
                $table->timestamp('currency_verified_at')->nullable();
                $table->unsignedBigInteger('current_v2_signing_key_id')->nullable();
                $table->string('v2_key_scope_status', 40)->default('not_provisioned');
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('verified_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['central_client_id', 'central_organization_id', 'tenant_database_identity'], 'iom_client_central_tenant_uniq');
                $table->unique(['tenant_database_identity', 'finance_organization_id'], 'iom_tenant_finance_org_uniq');
                $table->unique(['tenant_database_identity', 'solastock_organization_id'], 'iom_tenant_stock_org_uniq');
                $table->index(['status', 'activation_state'], 'iom_status_activation_idx');
            });
        }

        if (! Schema::hasTable('integration_master_data_mappings')) {
            Schema::create('integration_master_data_mappings', function (Blueprint $table): void {
                $table->id();
                $table->uuid('mapping_uuid')->unique();
                $table->uuid('organization_mapping_uuid');
                $table->unsignedBigInteger('central_client_id');
                $table->unsignedBigInteger('central_organization_id');
                $table->unsignedBigInteger('finance_organization_id');
                $table->unsignedBigInteger('solastock_organization_id');
                $table->string('entity_type', 40);
                $table->string('solastock_record_id', 80)->nullable();
                $table->string('solabooks_record_id', 80)->nullable();
                $table->string('status', 40);
                $table->string('contract_source_version', 40)->default('phase2.v1');
                $table->string('discovery_method', 80)->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->string('conflict_code', 80)->nullable();
                $table->json('error_state')->nullable();
                $table->boolean('solastock_archived')->default(false);
                $table->boolean('solabooks_archived')->default(false);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['organization_mapping_uuid', 'entity_type', 'solastock_record_id'], 'imdm_org_type_stock_uniq');
                $table->unique(['organization_mapping_uuid', 'entity_type', 'solabooks_record_id'], 'imdm_org_type_books_uniq');
                $table->index(['organization_mapping_uuid', 'entity_type', 'status'], 'imdm_org_type_status_idx');
                $table->index(['central_client_id', 'central_organization_id'], 'imdm_client_org_idx');
            });
        }

        if (! Schema::hasTable('integration_mapping_discovery_runs')) {
            Schema::create('integration_mapping_discovery_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid')->unique();
                $table->uuid('organization_mapping_uuid');
                $table->string('tenant_database_identity', 191);
                $table->string('mode', 20)->default('read_only');
                $table->char('before_image_hash', 64);
                $table->char('approved_manifest_hash', 64)->nullable();
                $table->json('counts')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['organization_mapping_uuid', 'started_at'], 'imdr_org_started_idx');
            });
        }

        if (! Schema::hasTable('integration_mapping_discovery_results')) {
            Schema::create('integration_mapping_discovery_results', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid');
                $table->string('entity_type', 40);
                $table->string('classification', 60);
                $table->json('solastock_record_ids')->nullable();
                $table->json('solabooks_record_ids')->nullable();
                $table->char('fingerprint', 64);
                $table->json('safe_details')->nullable();
                $table->string('resolution_status', 40)->default('unresolved');
                $table->uuid('mapping_uuid')->nullable();
                $table->timestamps();
                $table->unique(['run_uuid', 'fingerprint'], 'imdr_result_run_fingerprint_uniq');
                $table->index(['run_uuid', 'entity_type', 'classification'], 'imdr_result_run_type_class_idx');
            });
        }

        if (! Schema::hasTable('integration_mapping_audits')) {
            Schema::create('integration_mapping_audits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('organization_mapping_uuid');
                $table->uuid('mapping_uuid')->nullable();
                $table->string('entity_type', 40);
                $table->string('action', 60);
                $table->char('before_hash', 64)->nullable();
                $table->char('after_hash', 64);
                $table->json('safe_metadata')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['organization_mapping_uuid', 'created_at'], 'ima_org_created_idx');
                $table->index(['mapping_uuid', 'created_at'], 'ima_mapping_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Permanent additive mapping evidence; disable readers to roll back.
    }
};
