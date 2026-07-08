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
        if (Schema::hasTable('inventory_settings') && ! Schema::hasColumn('inventory_settings', 'adjustment_reason_codes')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->json('adjustment_reason_codes')->nullable()->after('approvals');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_settings', 'adjustment_reason_codes')) {
            Schema::table('inventory_settings', fn (Blueprint $table) => $table->dropColumn('adjustment_reason_codes'));
        }
    }
};
