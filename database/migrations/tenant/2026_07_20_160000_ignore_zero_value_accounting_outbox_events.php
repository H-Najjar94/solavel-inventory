<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        DB::connection($this->getConnection())->table('integration_outbox_events')
            ->whereIn('status', ['pending', 'failed'])
            ->whereIn('event_type', ['adjustment.posted', 'stock_count.posted'])
            ->whereRaw("ABS(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.total_inventory_value_change')) AS DECIMAL(20,4))) <= 0.00001")
            ->update([
                'status' => 'ignored',
                'next_attempt_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Historical delivery decisions are intentionally not reopened.
    }
};
