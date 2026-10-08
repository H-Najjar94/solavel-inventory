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
        if (! Schema::connection($this->getConnection())->hasColumn('inventory_settings', 'default_purchase_tax_code')) {
            Schema::connection($this->getConnection())->table('inventory_settings', function (Blueprint $table) {
                $table->string('default_purchase_tax_code', 50)->nullable()->after('taxes');
                $table->string('default_sales_tax_code', 50)->nullable()->after('default_purchase_tax_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->getConnection())->hasColumn('inventory_settings', 'default_purchase_tax_code')) {
            Schema::connection($this->getConnection())->table('inventory_settings', function (Blueprint $table) {
                $table->dropColumn(['default_purchase_tax_code', 'default_sales_tax_code']);
            });
        }
    }
};
