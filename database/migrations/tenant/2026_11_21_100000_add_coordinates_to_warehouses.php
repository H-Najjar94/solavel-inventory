<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('warehouses')) {
            return;
        }
        if (! $schema->hasColumn('warehouses', 'latitude')) {
            $schema->table('warehouses', fn (Blueprint $t) => $t->decimal('latitude', 10, 7)->nullable());
        }
        if (! $schema->hasColumn('warehouses', 'longitude')) {
            $schema->table('warehouses', fn (Blueprint $t) => $t->decimal('longitude', 10, 7)->nullable());
        }
    }

    public function down(): void
    {
        // Additive only: coordinates are user data; never dropped automatically.
    }
};
