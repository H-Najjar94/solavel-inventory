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
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasColumn('inventory_settings', 'default_warehouse_id')) {
            $schema->table('inventory_settings', fn (Blueprint $table) => $table->unsignedBigInteger('default_warehouse_id')->nullable());
        }
    }

    public function down(): void
    {
        // Retain organization metadata across compatible release rollback.
    }
};
