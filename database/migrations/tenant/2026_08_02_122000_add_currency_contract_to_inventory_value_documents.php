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
        foreach (['opening_stock_entries', 'stock_adjustments', 'stock_counts'] as $tableName) {
            if (! Schema::connection($this->getConnection())->hasTable($tableName)) {
                continue;
            }
            Schema::connection($this->getConnection())->table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::connection($this->getConnection())->hasColumn($tableName, 'integration_currency_code')) {
                    $table->char('integration_currency_code', 3)->nullable();
                }
                if (! Schema::connection($this->getConnection())->hasColumn($tableName, 'integration_exchange_rate')) {
                    $table->decimal('integration_exchange_rate', 20, 10)->nullable();
                }
                if (! Schema::connection($this->getConnection())->hasColumn($tableName, 'integration_rate_date')) {
                    $table->date('integration_rate_date')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Currency evidence on operational documents is permanent.
    }
};
