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
        Schema::table('pack_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('lot_id')->nullable()->after('package_number');
            $table->unsignedBigInteger('serial_id')->nullable()->after('lot_id');
            $table->index(['organization_id', 'serial_id'], 'packl_org_serial_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pack_lines', function (Blueprint $table) {
            $table->dropIndex('packl_org_serial_idx');
            $table->dropColumn(['lot_id', 'serial_id']);
        });
    }
};
