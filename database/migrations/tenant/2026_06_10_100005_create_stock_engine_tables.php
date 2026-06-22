<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: THE canonical stock engine.
 *
 *   stock_ledger        — append-only, immutable, single source of truth
 *   stock_balances      — derived projection (rebuildable from the ledger)
 *   cost_layers         — FIFO open cost layers
 *   reservations        — soft holds (reduce available without moving stock)
 *   inventory_audit_logs— append-only audit trail
 *
 * Quantities decimal(18,4); money decimal(18,2)/(18,4). Provenance + idempotency
 * are NOT NULL / unique where the rules demand. DB-level triggers block UPDATE/
 * DELETE on stock_ledger as defense in depth behind the Immutable trait.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();

            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 18, 4);   // always > 0; sign via direction
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->enum('costing_method', ['average', 'fifo', 'standard'])->default('average');
            $table->unsignedBigInteger('cost_layer_id')->nullable(); // FIFO link

            // Provenance — required (every movement traces to a document).
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();

            $table->dateTime('moved_at');   // business date
            $table->dateTime('posted_at');

            $table->string('idempotency_key');

            // Running snapshots for fast item-ledger reads.
            $table->decimal('balance_qty_after', 18, 4)->default(0);
            $table->decimal('balance_value_after', 18, 2)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['organization_id', 'item_id', 'variant_id', 'warehouse_id', 'moved_at'], 'ledger_org_item_wh_moved_idx');
            $table->index(['organization_id', 'warehouse_id', 'moved_at'], 'ledger_org_wh_moved_idx');
            $table->index(['source_type', 'source_id'], 'ledger_source_idx');
            $table->index('lot_id');
            $table->index('serial_id');
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();

            $table->decimal('on_hand_qty', 18, 4)->default(0);
            $table->decimal('reserved_qty', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->dateTime('last_movement_at')->nullable();
            $table->timestamps();

            // Coordinate uniqueness. MySQL treats NULLs as distinct in unique
            // indexes, so the engine normalizes NULL lot/bin to 0 via *_key cols.
            $table->unsignedBigInteger('lot_key')->storedAs('COALESCE(lot_id, 0)');
            $table->unsignedBigInteger('bin_key')->storedAs('COALESCE(bin_id, 0)');
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(variant_id, 0)');
            $table->unique(
                ['organization_id', 'item_id', 'variant_key', 'warehouse_id', 'lot_key', 'bin_key'],
                'balances_coord_uniq'
            );
            $table->index(['organization_id', 'warehouse_id'], 'balances_org_wh_idx');
            $table->index(['organization_id', 'item_id'], 'balances_org_item_idx');
        });

        Schema::create('cost_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->dateTime('received_at');
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('original_qty', 18, 4);
            $table->decimal('remaining_qty', 18, 4);
            $table->unsignedBigInteger('source_ledger_id')->nullable();
            $table->timestamps();

            // FIFO consumption order key.
            $table->index(['organization_id', 'item_id', 'warehouse_id', 'received_at', 'id'], 'layers_fifo_idx');
            $table->index(['organization_id', 'item_id', 'warehouse_id'], 'layers_org_item_wh_idx');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('bin_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->decimal('qty', 18, 4);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->enum('status', ['active', 'released', 'consumed'])->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'item_id', 'warehouse_id', 'status'], 'reservations_coord_status_idx');
            $table->index(['source_type', 'source_id'], 'reservations_source_idx');
        });

        Schema::create('inventory_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('document_ref')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'audit_org_entity_idx');
            $table->index(['organization_id', 'created_at'], 'audit_org_created_idx');
        });

        // DB-level immutability for stock_ledger (defense in depth).
        // Only attempt on MySQL/MariaDB (the production + test engine).
        $driver = DB::connection($connection)->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Idempotent: DROP IF EXISTS before CREATE so a partial-failure re-run
            // (or a DB where the trigger was created out-of-band) does not error
            // with "trigger already exists". CREATE TRIGGER has no IF NOT EXISTS.
            DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_update;');
            DB::connection($connection)->unprepared(
                'CREATE TRIGGER stock_ledger_no_update BEFORE UPDATE ON stock_ledger '
                ."FOR EACH ROW SIGNAL SQLSTATE '45000' "
                ."SET MESSAGE_TEXT = 'stock_ledger is append-only: UPDATE is not allowed';"
            );
            DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_delete;');
            DB::connection($connection)->unprepared(
                'CREATE TRIGGER stock_ledger_no_delete BEFORE DELETE ON stock_ledger '
                ."FOR EACH ROW SIGNAL SQLSTATE '45000' "
                ."SET MESSAGE_TEXT = 'stock_ledger is append-only: DELETE is not allowed';"
            );
        }
    }

    public function down(): void
    {
        $connection = $this->getConnection();
        $driver = DB::connection($connection)->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_update');
            DB::connection($connection)->unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_delete');
        }

        Schema::dropIfExists('inventory_audit_logs');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('cost_layers');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_ledger');
    }
};
