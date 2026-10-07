<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('finance_document_reverse_generations')) {
            Schema::connection('tenant')->create('finance_document_reverse_generations', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('organization_id'); $table->uuid('operation_uuid');
                $table->unsignedInteger('generation'); $table->uuid('reversal_operation_uuid')->unique();
                $table->uuid('request_uuid'); $table->char('source_revision', 64);
                $table->unsignedBigInteger('source_journal_id'); $table->unsignedBigInteger('original_match_journal_id');
                $table->unsignedBigInteger('actor_id'); $table->unsignedBigInteger('central_actor_id');
                $table->string('closure_permission', 16); $table->string('state', 32)->default('prepared');
                $table->longText('snapshot'); $table->longText('reverse_quote')->nullable();
                $table->char('plan_fingerprint', 64)->nullable(); $table->unsignedBigInteger('inverse_journal_id')->nullable();
                $table->uuid('release_operation_uuid')->nullable(); $table->longText('release_snapshot')->nullable();
                $table->timestamp('released_at')->nullable(); $table->timestamps();
                $table->unique(['organization_id', 'operation_uuid', 'generation'], 'fin_origin_reverse_generation_unique');
                $table->index(['organization_id', 'request_uuid'], 'fin_origin_reverse_request_idx');
            });
        }
        if (Schema::connection('tenant')->hasTable('finance_document_matches')
            && !Schema::connection('tenant')->hasColumn('finance_document_matches', 'reversal_generation')) {
            Schema::connection('tenant')->table('finance_document_matches', function (Blueprint $table) {
                $table->unsignedInteger('reversal_generation')->nullable();
            });
        }
    }
    public function down(): void
    {
        // Generation snapshots and acknowledged hold releases are protected audit history.
    }
};
