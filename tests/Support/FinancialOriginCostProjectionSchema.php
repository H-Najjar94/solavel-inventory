<?php
// Setup-only mirror of Finance187 at 3d0ff294; persisted Finance accounting projection is an explicit seam.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void {
        if (! Schema::connection('tenant')->hasTable('finance_document_positions')) {
            Schema::connection('tenant')->create('finance_document_positions', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('organization_id'); $table->uuid('request_uuid');
                $table->uuid('position_uuid'); $table->string('side', 16); $table->string('source_document_type', 32);
                $table->unsignedBigInteger('source_document_id'); $table->unsignedBigInteger('source_journal_id');
                $table->unsignedBigInteger('source_document_line_id'); $table->unsignedBigInteger('inventory_item_id');
                $table->unsignedBigInteger('entered_unit_id'); $table->decimal('quantity', 20, 8);
                $table->decimal('matched_quantity', 20, 8)->default(0);
                $table->decimal('booked_net_base', 24, 6); $table->decimal('booked_acquisition_base', 24, 6);
                $table->unsignedBigInteger('holding_account_id'); $table->unsignedBigInteger('destination_account_id');
                $table->string('state', 32)->default('open'); $table->longText('snapshot'); $table->timestamps();
                $table->unique(['organization_id', 'position_uuid'], 'financial_origin_position_uuid');
                $table->unique(['organization_id', 'source_document_type', 'source_document_id', 'source_journal_id', 'source_document_line_id'], 'financial_origin_position_line');
            });
        }
        if (! Schema::connection('tenant')->hasTable('finance_document_matches')) {
            Schema::connection('tenant')->create('finance_document_matches', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('organization_id'); $table->uuid('request_uuid');
                $table->uuid('operation_uuid'); $table->uuid('position_uuid'); $table->uuid('physical_mapping_uuid');
                $table->unsignedBigInteger('physical_document_id'); $table->unsignedBigInteger('physical_line_id');
                $table->unsignedBigInteger('source_document_line_id'); $table->unsignedBigInteger('physical_journal_id');
                $table->decimal('quantity', 20, 8); $table->decimal('base_quantity', 20, 8);
                $table->decimal('unit_conversion_factor', 24, 12); $table->char('unit_conversion_hash', 64);
                $table->decimal('booked_base', 24, 6); $table->decimal('physical_cost_base', 24, 6)->nullable();
                $table->string('state', 40)->default('journal_pending');
                $table->unsignedBigInteger('journal_entry_id')->nullable(); $table->unsignedBigInteger('reversal_journal_id')->nullable();
                $table->longText('snapshot'); $table->longText('value_plan')->nullable(); $table->char('plan_fingerprint', 64)->nullable();
                $table->uuid('reversal_operation_uuid')->nullable(); $table->unsignedBigInteger('reverse_actor_id')->nullable();
                $table->unsignedBigInteger('reverse_central_actor_id')->nullable(); $table->string('closure_permission', 64)->nullable();
                $table->string('reverse_state', 32)->nullable(); $table->longText('reversal_snapshot')->nullable();
                $table->string('closure_state', 32)->nullable(); $table->longText('closure_snapshot')->nullable();
                $table->uuid('release_operation_uuid')->nullable(); $table->string('release_state', 32)->nullable();
                $table->longText('release_snapshot')->nullable();
                $table->unsignedInteger('attempts')->default(0); $table->string('last_error', 191)->nullable();
                $table->timestamp('next_attempt_at')->nullable(); $table->timestamps();
                $table->unique(['organization_id', 'operation_uuid'], 'financial_origin_match_operation');
                $table->unique(['organization_id', 'position_uuid', 'physical_mapping_uuid', 'physical_line_id'], 'financial_origin_match_line');
            });
        }
    }
 public function down():void {} };
