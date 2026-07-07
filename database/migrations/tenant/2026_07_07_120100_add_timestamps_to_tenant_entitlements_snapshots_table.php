<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_entitlements_snapshots')) {
            return;
        }

        Schema::table('tenant_entitlements_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_entitlements_snapshots', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('tenant_entitlements_snapshots', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_entitlements_snapshots')) {
            return;
        }

        Schema::table('tenant_entitlements_snapshots', function (Blueprint $table): void {
            foreach (['created_at', 'updated_at'] as $column) {
                if (Schema::hasColumn('tenant_entitlements_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
