<?php

use Illuminate\Database\Migrations\Migration;
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
        $connection = $this->getConnection();
        if (! Schema::connection($connection)->hasTable('inventory_operational_role_sets')) {
            return;
        }

        $current = config('inventory_operational_roles.scoped_inventory_manager.permissions', []);
        $previous = array_values(array_diff($current, ['inventory.manage_purchase_orders']));
        DB::connection($connection)->table('inventory_operational_role_sets')
            ->where('role_key', 'scoped_inventory_manager')
            ->where('permissions', json_encode($previous))
            ->update(['permissions' => json_encode($current), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve later tenant edits and explicit denials.
    }
};
