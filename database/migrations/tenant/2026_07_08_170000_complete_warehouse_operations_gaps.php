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
        Schema::table('goods_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipts', 'blind_receiving')) {
                $table->boolean('blind_receiving')->default(false)->after('receipt_date');
            }
            if (! Schema::hasColumn('goods_receipts', 'inspection_status')) {
                $table->string('inspection_status', 30)->default('not_required')->after('blind_receiving');
            }
            if (! Schema::hasColumn('goods_receipts', 'inspected_by')) {
                $table->unsignedBigInteger('inspected_by')->nullable()->after('posted_by');
            }
            if (! Schema::hasColumn('goods_receipts', 'inspected_at')) {
                $table->dateTime('inspected_at')->nullable()->after('inspected_by');
            }
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipt_lines', 'inspection_status')) {
                $table->string('inspection_status', 30)->default('accepted')->after('rejected_qty');
            }
            if (! Schema::hasColumn('goods_receipt_lines', 'disposition')) {
                $table->string('disposition', 30)->default('restock')->after('inspection_status');
            }
            if (! Schema::hasColumn('goods_receipt_lines', 'quarantine_qty')) {
                $table->decimal('quarantine_qty', 18, 4)->default(0)->after('disposition');
            }
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_transfers', 'shipped_by')) {
                $table->unsignedBigInteger('shipped_by')->nullable()->after('posted_by');
            }
            if (! Schema::hasColumn('stock_transfers', 'shipped_at')) {
                $table->dateTime('shipped_at')->nullable()->after('shipped_by');
            }
            if (! Schema::hasColumn('stock_transfers', 'received_by')) {
                $table->unsignedBigInteger('received_by')->nullable()->after('shipped_at');
            }
            if (! Schema::hasColumn('stock_transfers', 'received_at')) {
                $table->dateTime('received_at')->nullable()->after('received_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            foreach (['received_at', 'received_by', 'shipped_at', 'shipped_by'] as $column) {
                if (Schema::hasColumn('stock_transfers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            foreach (['quarantine_qty', 'disposition', 'inspection_status'] as $column) {
                if (Schema::hasColumn('goods_receipt_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            foreach (['inspected_at', 'inspected_by', 'inspection_status', 'blind_receiving'] as $column) {
                if (Schema::hasColumn('goods_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
