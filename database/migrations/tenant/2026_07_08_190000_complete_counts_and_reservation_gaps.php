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
        if (Schema::hasTable('stock_counts')) {
            Schema::table('stock_counts', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_counts', 'blind_count')) {
                    $table->boolean('blind_count')->default(false)->after('count_type');
                }
                if (! Schema::hasColumn('stock_counts', 'scheduled_for')) {
                    $table->date('scheduled_for')->nullable()->after('zone_id');
                }
                if (! Schema::hasColumn('stock_counts', 'recurrence')) {
                    $table->string('recurrence', 20)->nullable()->after('scheduled_for');
                }
                if (! Schema::hasColumn('stock_counts', 'abc_class')) {
                    $table->string('abc_class', 1)->nullable()->after('recurrence');
                }
                if (! Schema::hasColumn('stock_counts', 'snapshot_at')) {
                    $table->dateTime('snapshot_at')->nullable()->after('abc_class');
                }
            });
        }

        if (Schema::hasTable('stock_count_lines')) {
            Schema::table('stock_count_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_count_lines', 'snapshot_qty')) {
                    $table->decimal('snapshot_qty', 18, 4)->nullable()->after('system_qty');
                }
            });
        }

        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('reservations', 'priority')) {
                    $table->unsignedSmallInteger('priority')->default(100)->after('qty');
                }
                if (! Schema::hasColumn('reservations', 'expired_at')) {
                    $table->dateTime('expired_at')->nullable()->after('expires_at');
                }
                if (! Schema::hasColumn('reservations', 'released_at')) {
                    $table->dateTime('released_at')->nullable()->after('expired_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reservations')) {
            Schema::table('reservations', function (Blueprint $table) {
                foreach (['released_at', 'expired_at', 'priority'] as $column) {
                    if (Schema::hasColumn('reservations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_count_lines') && Schema::hasColumn('stock_count_lines', 'snapshot_qty')) {
            Schema::table('stock_count_lines', fn (Blueprint $table) => $table->dropColumn('snapshot_qty'));
        }

        if (Schema::hasTable('stock_counts')) {
            Schema::table('stock_counts', function (Blueprint $table) {
                foreach (['snapshot_at', 'abc_class', 'recurrence', 'scheduled_for', 'blind_count'] as $column) {
                    if (Schema::hasColumn('stock_counts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
