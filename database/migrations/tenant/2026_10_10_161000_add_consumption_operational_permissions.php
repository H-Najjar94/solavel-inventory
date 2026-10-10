<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function getConnection(): ?string { return config('tenancy.tenant_connection','tenant'); }
    public function up(): void {
        $connection=$this->getConnection();
        if (!Schema::connection($connection)->hasTable('inventory_operational_role_sets')) return;
        foreach(config('inventory_operational_roles',[]) as$key=>$role) {
            $current=$role['permissions'];
            $previous=array_values(array_filter($current,fn($permission)=>!str_starts_with($permission,'inventory.consumption.')));
            // Upgrade only the exact prior built-in bundle. Never rewrite customer restrictions.
            DB::connection($connection)->table('inventory_operational_role_sets')->where('role_key',$key)->where('permissions',json_encode($previous))->update(['permissions'=>json_encode($current),'updated_at'=>now()]);
        }
    }
    public function down(): void {} // Preserve explicit grants and later customer edits.
};
