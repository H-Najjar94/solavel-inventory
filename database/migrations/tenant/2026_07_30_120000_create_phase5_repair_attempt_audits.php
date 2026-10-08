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
        if (! Schema::hasTable('integration_historical_repair_attempt_audits')) {
            Schema::create('integration_historical_repair_attempt_audits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('attempt_uuid')->unique();
                $table->string('application', 24);
                $table->string('tenant_database_identity', 191);
                $table->unsignedBigInteger('organization_id');
                $table->string('batch_identifier', 120);
                $table->char('manifest_sha256', 64);
                $table->string('approval_identifier', 191);
                $table->string('snapshot_reference', 191);
                $table->string('outcome', 40);
                $table->string('safe_error_code', 120)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['tenant_database_identity', 'organization_id', 'created_at'], 'ihraa_tenant_org_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Permanent repair-attempt audit history; disable the command to roll back.
    }
};
