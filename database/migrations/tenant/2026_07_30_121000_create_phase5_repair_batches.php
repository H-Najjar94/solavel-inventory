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
        if (! Schema::hasTable('integration_historical_repair_batches')) {
            Schema::create('integration_historical_repair_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('batch_identifier', 120)->unique();
                $table->string('application', 24);
                $table->string('tenant_database_identity', 191);
                $table->unsignedBigInteger('organization_id');
                $table->char('manifest_sha256', 64);
                $table->string('approval_identifier', 191);
                $table->string('snapshot_reference', 191);
                $table->string('repair_type', 80);
                $table->unsignedInteger('affected_records');
                $table->string('status', 32);
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_database_identity', 'organization_id', 'status'], 'ihrb_tenant_org_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Permanent applied-batch evidence; disable the executor to roll back.
    }
};
