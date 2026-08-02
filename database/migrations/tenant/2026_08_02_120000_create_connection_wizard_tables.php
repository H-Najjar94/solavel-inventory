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
        if (! Schema::hasTable('integration_connection_wizard_runs')) {
            Schema::create('integration_connection_wizard_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid')->unique();
                $table->uuid('organization_mapping_uuid');
                $table->unsignedBigInteger('central_client_id');
                $table->unsignedBigInteger('central_organization_id');
                $table->unsignedBigInteger('finance_organization_id');
                $table->unsignedBigInteger('solastock_organization_id');
                $table->string('state', 50)->default('setup_required');
                // DATETIME avoids MariaDB's legacy implicit
                // ON UPDATE CURRENT_TIMESTAMP behavior on the first TIMESTAMP
                // column. The approval cutoff must be immutable.
                $table->dateTime('cutoff_at');
                $table->string('snapshot_id', 120);
                $table->char('snapshot_hash', 64);
                $table->char('discovery_manifest_hash', 64);
                $table->char('discovery_before_image_hash', 64);
                $table->char('approval_payload_hash', 64)->nullable();
                $table->json('authority_choices')->nullable();
                $table->json('workflow_allowlist')->nullable();
                $table->json('comparison_totals')->nullable();
                $table->longText('snapshot_payload');
                $table->unsignedBigInteger('created_by_user_id');
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->string('invalidation_reason', 100)->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();

                $table->index(['organization_mapping_uuid', 'created_at'], 'icwr_mapping_created_idx');
                $table->index(['solastock_organization_id', 'state'], 'icwr_stock_state_idx');
            });
        }

        if (! Schema::hasTable('integration_connection_wizard_decisions')) {
            Schema::create('integration_connection_wizard_decisions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('decision_uuid')->unique();
                $table->uuid('run_uuid');
                $table->char('candidate_fingerprint', 64);
                $table->string('entity_type', 40);
                $table->string('action', 60);
                $table->json('solastock_record_ids')->nullable();
                $table->json('solabooks_record_ids')->nullable();
                $table->char('candidate_before_hash', 64);
                $table->json('safe_details')->nullable();
                $table->string('status', 30)->default('selected');
                $table->unsignedBigInteger('actor_user_id');
                $table->timestamps();

                $table->unique(['run_uuid', 'candidate_fingerprint'], 'icwd_run_candidate_uniq');
                $table->index(['run_uuid', 'entity_type', 'status'], 'icwd_run_type_status_idx');
            });
        }

        if (! Schema::hasTable('integration_connection_wizard_audits')) {
            Schema::create('integration_connection_wizard_audits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid');
                $table->uuid('decision_uuid')->nullable();
                $table->string('action', 60);
                $table->char('before_hash', 64)->nullable();
                $table->char('after_hash', 64);
                $table->json('safe_metadata')->nullable();
                $table->unsignedBigInteger('actor_user_id');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['run_uuid', 'created_at'], 'icwa_run_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Connection evidence is permanent. Disable readers to roll back code.
    }
};
