<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: SolaBooks integration foundation.
 *   integration_outbox_events    — transactional outbox (events recorded on doc post)
 *   integration_account_mappings — SolaStock concept → SolaBooks account *refs*
 *   item_integration_mappings    — per-item SolaBooks links/overrides
 *   integration_settings         — per-org connection mode/state
 *
 * These hold REFERENCES to SolaBooks accounts/items; SolaStock never owns the GL.
 * Nothing here writes stock.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::hasTable('integration_settings') or Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('integration')->default('solabooks');
            // disconnected | connected_readonly | connected_pending_mapping | active | paused | error
            $table->string('mode')->default('disconnected');
            $table->unsignedBigInteger('solabooks_organization_id')->nullable();
            $table->boolean('require_mapping_before_post')->default(false);
            $table->dateTime('last_sync_at')->nullable();
            $table->string('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'integration']);
        });

        Schema::hasTable('integration_account_mappings') or Schema::create('integration_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('integration')->default('solabooks');
            // mapping_type: inventory_asset | cogs | adjustment_gain | adjustment_loss |
            // grni | landed_cost_clearing | transfer_clearing | opening_offset |
            // sales_returns | purchase_returns
            $table->string('mapping_type');
            $table->string('solabooks_account_id')->nullable();
            $table->string('account_code')->nullable();   // snapshot
            $table->string('account_name')->nullable();   // snapshot
            $table->string('status')->default('unmapped'); // unmapped | mapped | verified | error
            $table->dateTime('last_verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'integration', 'mapping_type'], 'iam_org_int_type_uniq');
        });

        Schema::hasTable('item_integration_mappings') or Schema::create('item_integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('integration')->default('solabooks');
            $table->unsignedBigInteger('item_id');
            $table->string('solabooks_item_id')->nullable();
            $table->string('income_account_ref')->nullable();
            $table->string('cogs_account_ref')->nullable();
            $table->string('inventory_asset_account_ref')->nullable();
            $table->string('tax_category')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('sync_status')->default('not_synced'); // not_synced | pending | synced | error
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'integration', 'item_id'], 'iim_org_int_item_uniq');
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
        });

        Schema::hasTable('integration_outbox_events') or Schema::create('integration_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('event_uuid', 64);
            $table->string('integration')->default('solabooks');
            $table->string('event_type');     // opening_stock.posted, adjustment.reversed, ...
            $table->string('aggregate_type');  // OpeningStockEntry, StockAdjustment, ...
            $table->unsignedBigInteger('aggregate_id');
            $table->string('aggregate_number')->nullable();
            $table->dateTime('occurred_at');
            $table->json('payload');
            // pending | processing | sent | failed | ignored
            $table->string('status')->default('pending');
            $table->string('mapping_status')->default('incomplete'); // complete | incomplete
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'integration', 'idempotency_key'], 'outbox_org_int_idem_uniq');
            $table->index(['organization_id', 'integration', 'status'], 'outbox_org_int_status_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
            $table->index(['organization_id', 'event_type'], 'outbox_org_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox_events');
        Schema::dropIfExists('item_integration_mappings');
        Schema::dropIfExists('integration_account_mappings');
        Schema::dropIfExists('integration_settings');
    }
};
