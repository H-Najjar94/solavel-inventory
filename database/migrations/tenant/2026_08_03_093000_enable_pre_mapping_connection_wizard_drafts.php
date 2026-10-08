<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        $connection = $this->getConnection();
        if (! Schema::connection($connection)->hasTable('integration_connection_wizard_runs')) {
            return;
        }

        // Draft setup deliberately precedes immutable mapping and cutoff. These
        // columns become mandatory only when the draft enters cutoff_review.
        DB::connection($connection)->statement(
            'ALTER TABLE integration_connection_wizard_runs '
            .'MODIFY organization_mapping_uuid CHAR(36) NULL, '
            .'MODIFY cutoff_at DATETIME NULL'
        );

        $runColumns = collect([
            'tenant_database_identity', 'draft_version', 'lock_version', 'decisions_completed_at',
            'snapshot_frozen_at', 'cutoff_reviewed_at', 'owner_approved_by_user_id',
            'accountant_approved_by_user_id', 'discarded_by_user_id',
        ])->mapWithKeys(fn (string $column): array => [$column => Schema::connection($connection)
            ->hasColumn('integration_connection_wizard_runs', $column)]);
        Schema::connection($connection)->table('integration_connection_wizard_runs', function (Blueprint $table) use ($runColumns): void {
            if (! $runColumns['tenant_database_identity']) {
                $table->string('tenant_database_identity', 100)->nullable()->after('central_organization_id');
            }
            if (! $runColumns['draft_version']) {
                $table->unsignedInteger('draft_version')->default(1)->after('state');
            }
            if (! $runColumns['lock_version']) {
                $table->unsignedInteger('lock_version')->default(1)->after('draft_version');
            }
            if (! $runColumns['decisions_completed_at']) {
                $table->timestamp('decisions_completed_at')->nullable()->after('approved_at');
            }
            if (! $runColumns['snapshot_frozen_at']) {
                $table->timestamp('snapshot_frozen_at')->nullable()->after('decisions_completed_at');
            }
            if (! $runColumns['cutoff_reviewed_at']) {
                $table->timestamp('cutoff_reviewed_at')->nullable()->after('snapshot_frozen_at');
            }
            if (! $runColumns['owner_approved_by_user_id']) {
                $table->unsignedBigInteger('owner_approved_by_user_id')->nullable()->after('cutoff_reviewed_at');
                $table->timestamp('owner_approved_at')->nullable()->after('owner_approved_by_user_id');
                $table->char('owner_approval_hash', 64)->nullable()->after('owner_approved_at');
            }
            if (! $runColumns['accountant_approved_by_user_id']) {
                $table->unsignedBigInteger('accountant_approved_by_user_id')->nullable()->after('owner_approval_hash');
                $table->timestamp('accountant_approved_at')->nullable()->after('accountant_approved_by_user_id');
                $table->char('accountant_approval_hash', 64)->nullable()->after('accountant_approved_at');
            }
            if (! $runColumns['discarded_by_user_id']) {
                $table->unsignedBigInteger('discarded_by_user_id')->nullable()->after('accountant_approval_hash');
                $table->timestamp('discarded_at')->nullable()->after('discarded_by_user_id');
            }
        });
        DB::connection($connection)->table('integration_connection_wizard_runs')
            ->whereNull('tenant_database_identity')->update(['tenant_database_identity' => DB::connection($connection)->getDatabaseName()]);

        $decisionColumns = collect(['reviewer_role', 'decision_version', 'reviewed_by_user_id'])
            ->mapWithKeys(fn (string $column): array => [$column => Schema::connection($connection)
                ->hasColumn('integration_connection_wizard_decisions', $column)]);
        Schema::connection($connection)->table('integration_connection_wizard_decisions', function (Blueprint $table) use ($decisionColumns): void {
            if (! $decisionColumns['reviewer_role']) {
                $table->string('reviewer_role', 30)->default('owner')->after('entity_type');
            }
            if (! $decisionColumns['decision_version']) {
                $table->unsignedInteger('decision_version')->default(1)->after('reviewer_role');
            }
            if (! $decisionColumns['reviewed_by_user_id']) {
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->after('actor_user_id');
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            }
        });
    }

    public function down(): void
    {
        // Draft and approval evidence is permanent. Roll back readers, never evidence.
    }
};
