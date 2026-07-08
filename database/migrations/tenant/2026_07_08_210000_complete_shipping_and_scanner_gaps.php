<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'carrier_service')) {
                $table->string('carrier_service')->nullable()->after('carrier');
            }
            if (! Schema::hasColumn('shipments', 'ship_to')) {
                $table->json('ship_to')->nullable()->after('tracking_number');
            }
            if (! Schema::hasColumn('shipments', 'package_weight')) {
                $table->decimal('package_weight', 18, 4)->nullable()->after('ship_to');
            }
            if (! Schema::hasColumn('shipments', 'package_length')) {
                $table->decimal('package_length', 18, 4)->nullable()->after('package_weight');
            }
            if (! Schema::hasColumn('shipments', 'package_width')) {
                $table->decimal('package_width', 18, 4)->nullable()->after('package_length');
            }
            if (! Schema::hasColumn('shipments', 'package_height')) {
                $table->decimal('package_height', 18, 4)->nullable()->after('package_width');
            }
            if (! Schema::hasColumn('shipments', 'rate_amount')) {
                $table->decimal('rate_amount', 18, 2)->nullable()->after('package_height');
            }
            if (! Schema::hasColumn('shipments', 'rate_currency')) {
                $table->string('rate_currency', 3)->nullable()->after('rate_amount');
            }
            if (! Schema::hasColumn('shipments', 'label_status')) {
                $table->string('label_status')->nullable()->after('rate_currency');
            }
            if (! Schema::hasColumn('shipments', 'label_number')) {
                $table->string('label_number')->nullable()->after('label_status');
            }
            if (! Schema::hasColumn('shipments', 'label_payload')) {
                $table->json('label_payload')->nullable()->after('label_number');
            }
            if (! Schema::hasColumn('shipments', 'label_generated_at')) {
                $table->dateTime('label_generated_at')->nullable()->after('label_payload');
            }
            if (! Schema::hasColumn('shipments', 'tracking_status')) {
                $table->string('tracking_status')->nullable()->after('label_generated_at');
            }
            if (! Schema::hasColumn('shipments', 'tracking_events')) {
                $table->json('tracking_events')->nullable()->after('tracking_status');
            }
            if (! Schema::hasColumn('shipments', 'warranty_months')) {
                $table->unsignedSmallInteger('warranty_months')->nullable()->after('tracking_events');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach ([
                'carrier_service',
                'ship_to',
                'package_weight',
                'package_length',
                'package_width',
                'package_height',
                'rate_amount',
                'rate_currency',
                'label_status',
                'label_number',
                'label_payload',
                'label_generated_at',
                'tracking_status',
                'tracking_events',
                'warranty_months',
            ] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
