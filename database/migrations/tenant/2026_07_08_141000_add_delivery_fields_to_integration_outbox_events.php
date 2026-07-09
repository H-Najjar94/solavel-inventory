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
        Schema::table('integration_outbox_events', function (Blueprint $table) {
            if (! Schema::hasColumn('integration_outbox_events', 'correlation_id')) {
                $table->string('correlation_id')->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('integration_outbox_events', 'external_document_id')) {
                $table->string('external_document_id')->nullable()->after('correlation_id');
            }
            if (! Schema::hasColumn('integration_outbox_events', 'external_response')) {
                $table->json('external_response')->nullable()->after('external_document_id');
            }
            if (! Schema::hasColumn('integration_outbox_events', 'dead_lettered_at')) {
                $table->dateTime('dead_lettered_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration_outbox_events', function (Blueprint $table) {
            foreach (['correlation_id', 'external_document_id', 'external_response', 'dead_lettered_at'] as $column) {
                if (Schema::hasColumn('integration_outbox_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
