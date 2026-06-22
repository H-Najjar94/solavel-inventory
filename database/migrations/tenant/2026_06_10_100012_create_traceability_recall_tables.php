<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration: make lot/serial/expiry traceability first-class and add the
 * recall workspace. Purely ADDITIVE over the existing lots / serial_numbers /
 * stock_ledger tables (those shipped earlier; we don't rewrite them).
 *
 *   items.tracks_expiry         — require expiry capture on IN for this item
 *   lots                        — status, source document refs, recall/expiry state, notes
 *   serial_numbers              — richer lifecycle status, source/ship/return refs, warranty/owner placeholders
 *   stock_ledger.expiry_date    — denormalized expiry carried on each movement
 *   recalls (+ lines, + actions)— recall case workspace
 *
 * Only the stock engine + approved services write the canonical stock tables;
 * traceability columns here are written by approved services during posting.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        // ── items: optional expiry tracking flag ──
        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'tracks_expiry')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('tracks_expiry')->default(false)->after('tracking_type');
                $table->unsignedInteger('shelf_life_days')->nullable()->after('tracks_expiry');
            });
        }

        // ── lots: lifecycle + provenance + recall/expiry state ──
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'received_date')) {
                $table->date('received_date')->nullable()->after('expiry_date');
            }
            if (! Schema::hasColumn('lots', 'status')) {
                // active | expired | quarantined | consumed | recalled
                $table->string('status')->default('active')->after('received_date');
            }
            if (! Schema::hasColumn('lots', 'source_type')) {
                $table->string('source_type')->nullable()->after('supplier_id');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->unsignedBigInteger('source_line_id')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('lots', 'recall_id')) {
                $table->unsignedBigInteger('recall_id')->nullable()->after('source_line_id');
            }
            if (! Schema::hasColumn('lots', 'notes')) {
                $table->text('notes')->nullable()->after('recall_id');
            }
        });
        $this->addIndexIfMissing('lots', ['organization_id', 'status'], 'lots_org_status_idx');

        // ── serial_numbers: full lifecycle + refs + placeholders ──
        // The original enum (pending,in_stock,reserved,sold,returned,scrapped) is
        // widened to a string so the richer lifecycle states are storable. Existing
        // rows keep their value; the SerialNumber model maps legacy → canonical.
        if ($this->columnIsEnum('serial_numbers', 'status')) {
            DB::connection($this->getConnection())->statement(
                "ALTER TABLE serial_numbers MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'available'"
            );
        }
        Schema::table('serial_numbers', function (Blueprint $table) {
            if (! Schema::hasColumn('serial_numbers', 'source_type')) {
                $table->string('source_type')->nullable()->after('bin_id');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->unsignedBigInteger('source_line_id')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('serial_numbers', 'shipment_id')) {
                $table->unsignedBigInteger('shipment_id')->nullable()->after('source_line_id');
                $table->unsignedBigInteger('sales_return_id')->nullable()->after('shipment_id');
            }
            if (! Schema::hasColumn('serial_numbers', 'recall_id')) {
                $table->unsignedBigInteger('recall_id')->nullable()->after('sales_return_id');
            }
            if (! Schema::hasColumn('serial_numbers', 'warranty_until')) {
                $table->date('warranty_until')->nullable()->after('recall_id'); // placeholder
                $table->string('owner_ref')->nullable()->after('warranty_until'); // customer placeholder
            }
            if (! Schema::hasColumn('serial_numbers', 'notes')) {
                $table->text('notes')->nullable()->after('owner_ref');
            }
        });

        // ── stock_ledger: carry expiry on each movement for fast trace/expiry reads ──
        if (! Schema::hasColumn('stock_ledger', 'expiry_date')) {
            Schema::table('stock_ledger', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('serial_id');
            });
        }

        // ── recalls ──
        Schema::hasTable('recalls') or Schema::create('recalls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('recall_number');
            $table->unsignedBigInteger('item_id');
            $table->string('scope')->default('lot'); // lot | serial
            $table->string('reason')->nullable();
            $table->string('status')->default('draft'); // draft | active | closed
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'recall_number']);
            $table->index(['organization_id', 'status']);
            $table->foreign('item_id')->references('id')->on('items');
        });

        Schema::hasTable('recall_lines') or Schema::create('recall_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('recall_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            // snapshot of impact at capture time (recomputed live on the detail page)
            $table->decimal('on_hand_qty', 18, 4)->default(0);
            $table->decimal('reserved_qty', 18, 4)->default(0);
            $table->decimal('shipped_qty', 18, 4)->default(0);
            $table->string('disposition')->nullable(); // quarantine | return | destroy | none
            $table->timestamps();
            $table->index(['organization_id', 'recall_id'], 'recall_lines_org_recall_idx');
            $table->foreign('recall_id')->references('id')->on('recalls')->cascadeOnDelete();
        });

        Schema::hasTable('recall_actions') or Schema::create('recall_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('recall_id');
            $table->string('action');
            $table->text('detail')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['organization_id', 'recall_id'], 'recall_actions_org_recall_idx');
            $table->foreign('recall_id')->references('id')->on('recalls')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_actions');
        Schema::dropIfExists('recall_lines');
        Schema::dropIfExists('recalls');

        if (Schema::hasColumn('stock_ledger', 'expiry_date')) {
            Schema::table('stock_ledger', fn (Blueprint $t) => $t->dropColumn('expiry_date'));
        }
        Schema::table('serial_numbers', function (Blueprint $table) {
            foreach (['source_type', 'source_id', 'source_line_id', 'shipment_id', 'sales_return_id', 'recall_id', 'warranty_until', 'owner_ref', 'notes'] as $c) {
                if (Schema::hasColumn('serial_numbers', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('lots', function (Blueprint $table) {
            foreach (['received_date', 'status', 'source_type', 'source_id', 'source_line_id', 'recall_id', 'notes'] as $c) {
                if (Schema::hasColumn('lots', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        if (Schema::hasColumn('items', 'tracks_expiry')) {
            Schema::table('items', fn (Blueprint $t) => $t->dropColumn(['tracks_expiry', 'shelf_life_days']));
        }
    }

    /** True if a column is currently an ENUM (so we know to widen it). */
    private function columnIsEnum(string $table, string $column): bool
    {
        $driver = DB::connection($this->getConnection())->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false; // sqlite stores enums as text already
        }
        $type = DB::connection($this->getConnection())->selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $type && strtolower($type->DATA_TYPE ?? '') === 'enum';
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        $driver = DB::connection($this->getConnection())->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));

            return;
        }
        $exists = DB::connection($this->getConnection())->selectOne(
            'SELECT 1 AS x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $name]
        );
        if (! $exists) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }
};
