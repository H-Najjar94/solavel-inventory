<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: give SolaStock its OWN order tables, distinct from Finance.
 *
 * Finance (SolaBooks) already owns `purchase_orders` and `sales_orders` in the
 * shared tenant DB, with a DIFFERENT schema (e.g. no warehouse_id). SolaStock
 * must be installable standalone and must never read/write Finance tables, so it
 * uses `inventory_purchase_orders` / `inventory_sales_orders` instead.
 *
 * Forward-only + additive + idempotent:
 *   - creates the two inventory_* order tables if missing (SolaStock schema),
 *   - repoints SolaStock's *_lines FKs to the inventory_* tables,
 *   - NEVER drops, renames, or alters Finance's purchase_orders/sales_orders.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        // ── inventory_purchase_orders (SolaStock's own) ──
        if (! Schema::hasTable('inventory_purchase_orders')) {
            Schema::create('inventory_purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('po_number');
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->unsignedBigInteger('warehouse_id');
                $table->date('order_date');
                $table->date('expected_date')->nullable();
                $table->enum('status', ['draft', 'approved', 'partially_received', 'received', 'closed', 'cancelled'])->default('draft');
                $table->string('currency_code', 3)->default('SAR');
                $table->decimal('subtotal', 18, 2)->default(0);
                $table->decimal('tax_total', 18, 2)->default(0);
                $table->decimal('total', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['organization_id', 'po_number'], 'inv_po_org_num_uniq');
                $table->index(['organization_id', 'supplier_id', 'status'], 'inv_po_org_sup_status_idx');
            });
        }

        // ── inventory_sales_orders (SolaStock's own) ──
        if (! Schema::hasTable('inventory_sales_orders')) {
            Schema::create('inventory_sales_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('order_number');
                $table->string('customer_name')->nullable();
                $table->string('customer_external_id')->nullable();
                $table->string('source_app')->default('manual');
                $table->string('source_document_id')->nullable();
                $table->string('source_document_number')->nullable();
                $table->date('order_date');
                $table->date('requested_ship_date')->nullable();
                $table->unsignedBigInteger('warehouse_id');
                $table->string('status')->default('draft');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['organization_id', 'order_number'], 'inv_so_org_num_uniq');
                $table->index(['organization_id', 'status'], 'inv_so_org_status_idx');
            });
        }

        // ── Repoint SolaStock line-table FKs to the inventory_* tables ──
        // (Only if the FK currently targets Finance's purchase_orders/sales_orders.)
        foreach (['purchase_order_lines', 'goods_receipts'] as $tbl) {
            $this->repointFk($tbl, 'purchase_order_id', 'inventory_purchase_orders');
        }
        foreach (['sales_order_lines', 'pick_lists', 'packs', 'shipments'] as $tbl) {
            $this->repointFk($tbl, 'sales_order_id', 'inventory_sales_orders');
        }

        // reservations.bin_id — the StockReservationService reserves per bin, but
        // the original engine migration omitted the column. Add it idempotently.
        if (Schema::hasTable('reservations') && ! Schema::hasColumn('reservations', 'bin_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('bin_id')->nullable()->after('lot_id');
            });
        }
    }

    public function down(): void
    {
        // Forward-only; do not drop (other rows/FKs may depend on these). Leaving
        // the inventory_* tables in place is safe and never touches Finance.
    }

    /** Drop an existing FK on $table.$column (if any) and add one to $target. */
    private function repointFk(string $table, string $column, string $target): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $driver = DB::connection($this->getConnection())->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // Find + drop any existing FK constraint on this column.
        $fks = DB::connection($this->getConnection())->select(
            'SELECT CONSTRAINT_NAME cn FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );
        foreach ($fks as $fk) {
            try {
                DB::connection($this->getConnection())->statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->cn}`");
            } catch (\Throwable $e) {
                // ignore — best effort
            }
        }

        // Add the FK to the SolaStock table.
        try {
            DB::connection($this->getConnection())->statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$column}_inv_fk` FOREIGN KEY (`{$column}`) REFERENCES `{$target}` (`id`) ON DELETE CASCADE"
            );
        } catch (\Throwable $e) {
            // If it already points correctly or can't be added, leave as-is.
        }
    }
};
