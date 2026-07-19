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
        if (Schema::hasTable('inventory_user_warehouses')) {
            return;
        }

        Schema::create('inventory_user_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'warehouse_id'], 'inventory_user_warehouses_org_user_wh_uniq');
            $table->index(['organization_id', 'warehouse_id'], 'inventory_user_warehouses_org_wh_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_user_warehouses');
    }
};
