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
            $table->decimal('tax_rate', 8, 4)->default(0)->after('tax_code');
            $table->decimal('tax_amount', 18, 2)->default(0)->after('tax_rate');
            $table->decimal('line_total', 18, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_amount', 'line_total']);
        });
    }
};
