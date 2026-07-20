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
        Schema::table('inventory_settings', function (Blueprint $table) {
            $table->unsignedInteger('expiry_warning_days')->default(30)->after('picking_policy');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_settings', fn (Blueprint $table) => $table->dropColumn('expiry_warning_days'));
    }
};
