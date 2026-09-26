<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        $c = $this->getConnection();
        if (! Schema::connection($c)->hasTable('inventory_operational_role_sets')) {
            Schema::connection($c)->create('inventory_operational_role_sets', function (Blueprint $t) {
                $t->string('role_key', 80)->primary();
                $t->json('permissions');
                $t->timestamps();
            });
        }
        foreach (config('inventory_operational_roles') as $key => $role) {
            DB::connection($c)->table('inventory_operational_role_sets')->insertOrIgnore(['role_key' => $key, 'permissions' => json_encode($role['permissions']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    { /* Retain native definitions and customizations on code rollback. */
    }
};
