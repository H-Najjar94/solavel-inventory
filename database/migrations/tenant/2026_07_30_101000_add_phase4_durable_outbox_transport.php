<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_outbox_events', function (Blueprint $table): void {
            foreach ([
                'contract_version' => fn () => $table->string('contract_version', 40)->nullable(),
                'payload_hash' => fn () => $table->char('payload_hash', 64)->nullable(),
                'workflow_key' => fn () => $table->string('workflow_key', 80)->nullable(),
                'ordering_key' => fn () => $table->string('ordering_key', 191)->nullable(),
                'depends_on_event_uuid' => fn () => $table->string('depends_on_event_uuid', 64)->nullable(),
                'lease_owner' => fn () => $table->string('lease_owner', 120)->nullable(),
                'lease_token' => fn () => $table->uuid('lease_token')->nullable(),
                'claimed_at' => fn () => $table->dateTime('claimed_at')->nullable(),
                'lease_expires_at' => fn () => $table->dateTime('lease_expires_at')->nullable(),
                'failure_category' => fn () => $table->string('failure_category', 48)->nullable(),
                'failure_code' => fn () => $table->string('failure_code', 96)->nullable(),
                'first_failed_at' => fn () => $table->dateTime('first_failed_at')->nullable(),
                'last_failed_at' => fn () => $table->dateTime('last_failed_at')->nullable(),
                'safe_error' => fn () => $table->string('safe_error', 500)->nullable(),
                'transport_eligible_at' => fn () => $table->dateTime('transport_eligible_at')->nullable(),
                'state_version' => fn () => $table->unsignedBigInteger('state_version')->default(0),
            ] as $column => $definition) {
                if (! Schema::hasColumn('integration_outbox_events', $column)) {
                    $definition();
                }
            }
        });
        Schema::table('integration_outbox_events', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'status', 'next_attempt_at', 'transport_eligible_at'],
                'ioe_transport_due_idx'
            );
            $table->index(['lease_expires_at', 'status'], 'ioe_expired_lease_idx');
            $table->index(['organization_id', 'ordering_key', 'id'], 'ioe_ordering_idx');
            $table->index(['depends_on_event_uuid', 'status'], 'ioe_dependency_idx');
        });

        if (! Schema::hasTable('integration_outbox_transition_audits')) {
            Schema::create('integration_outbox_transition_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('event_id');
                $table->string('event_uuid', 64);
                $table->string('from_status', 40);
                $table->string('to_status', 40);
                $table->unsignedBigInteger('state_version');
                $table->char('lease_token_hash', 64)->nullable();
                $table->string('reason_code', 96);
                $table->string('actor_type', 32);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('safe_metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['organization_id', 'event_id', 'state_version'], 'iota_event_state_idx');
                $table->index(['organization_id', 'to_status', 'created_at'], 'iota_status_created_idx');
            });
        }

        if (! Schema::hasTable('integration_dead_letter_reviews')) {
            Schema::create('integration_dead_letter_reviews', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('event_id');
                $table->string('event_uuid', 64);
                $table->string('action', 40);
                $table->string('required_recovery_action', 191)->nullable();
                $table->string('before_status', 40);
                $table->string('after_status', 40);
                $table->unsignedBigInteger('reviewer_user_id');
                $table->string('review_note', 500)->nullable();
                $table->timestamp('reviewed_at')->useCurrent();
                $table->char('event_payload_hash', 64);

                $table->index(['organization_id', 'event_id', 'reviewed_at'], 'idlr_event_review_idx');
            });
        }

        if (! Schema::hasTable('integration_transport_worker_heartbeats')) {
            Schema::create('integration_transport_worker_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->string('worker_id', 120)->unique();
                $table->string('queue_name', 80);
                $table->string('state', 24);
                $table->unsignedInteger('processed_count')->default(0);
                $table->dateTime('started_at');
                $table->dateTime('last_seen_at');
                $table->dateTime('stopped_at')->nullable();
                $table->string('served_commit', 40)->nullable();
                $table->timestamps();
                $table->index(['state', 'last_seen_at'], 'itwh_state_seen_idx');
            });
        }
    }

    public function down(): void
    {
        // Permanent transport and review evidence; intentionally additive.
    }
};
