<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's explicit landed-cost opt-out. NULL (every existing row) means
 * "follow the plan": landed costs are available wherever the plan includes them.
 * Only an explicit administrator choice sets it, and nothing else clears it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasTable('inventory_settings') && ! $schema->hasColumn('inventory_settings', 'landed_costs_opted_out_at')) {
            $schema->table('inventory_settings', fn (Blueprint $table) => $table->timestamp('landed_costs_opted_out_at')->nullable());
        }
    }

    public function down(): void
    {
        // Retain an explicit organization choice across compatible release rollback.
    }
};
