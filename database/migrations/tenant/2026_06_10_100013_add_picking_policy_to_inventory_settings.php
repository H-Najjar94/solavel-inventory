<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: add the picking policy setting (manual | fifo | fefo).
 * Default 'manual' — the engine never auto-allocates; FEFO is a SUGGESTION layer
 * for expiry-tracked items unless a tenant opts into fifo/fefo. Additive.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        if (Schema::hasTable('inventory_settings') && ! Schema::hasColumn('inventory_settings', 'picking_policy')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->string('picking_policy')->default('manual')->after('allow_negative_stock');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_settings', 'picking_policy')) {
            Schema::table('inventory_settings', fn (Blueprint $t) => $t->dropColumn('picking_policy'));
        }
    }
};
