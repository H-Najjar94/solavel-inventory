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
            ->whereIn('event_type', [
                'sales_order.confirmed', 'stock_reserved', 'stock_reservation_released',
                'pick_list.picked', 'pack.packed', 'transfer.posted',
            ])->update([
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
