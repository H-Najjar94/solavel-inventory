<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasTable('integration_connection_wizard_runs')) {
            return;
        }

        DB::connection($this->getConnection())->statement(
            'ALTER TABLE integration_connection_wizard_runs MODIFY cutoff_at DATETIME NOT NULL'
        );
    }

    public function down(): void
    {
        // Frozen approval cutoffs must never regain implicit update behavior.
    }
};
