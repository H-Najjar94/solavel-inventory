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
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->string('tax_treatment', 20)->nullable()->after('tax_code');
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('tax_treatment', 20)->nullable()->after('tax_code');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', fn (Blueprint $table) => $table->dropColumn('tax_treatment'));
        Schema::table('sales_order_lines', fn (Blueprint $table) => $table->dropColumn('tax_treatment'));
    }
};
