<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string { return config('tenancy.tenant_connection', 'tenant'); }

    public function up(): void
    {
        if (Schema::hasTable('inventory_settings') && ! Schema::hasColumn('inventory_settings', 'taxes')) {
            Schema::table('inventory_settings', fn (Blueprint $table) => $table->json('taxes')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_settings', 'taxes')) {
            Schema::table('inventory_settings', fn (Blueprint $table) => $table->dropColumn('taxes'));
        }
    }
};
