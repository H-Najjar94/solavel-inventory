<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-item relationships. One row per (item, supplier) holds the supplier's
 * purchasing terms; supplier_price_lists rows become its quantity/date price tiers.
 * Additive and re-runnable: existing tier rows (none in production on 2026-10-22)
 * are attached to a relationship created from their own values.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('item_suppliers')) {
            $schema->create('item_suppliers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('supplier_id');
                $table->string('status', 20)->default('approved');
                $table->string('supplier_sku', 100)->nullable();
                $table->string('supplier_item_name')->nullable();
                $table->text('supplier_item_description')->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->unsignedBigInteger('purchase_unit_id')->nullable();
                $table->decimal('purchase_unit_factor', 18, 8)->default(1);
                $table->decimal('minimum_order_qty', 24, 6)->nullable();
                $table->decimal('order_multiple', 24, 6)->nullable();
                $table->unsignedSmallInteger('lead_time_days')->nullable();
                $table->decimal('discount_percent', 9, 6)->nullable();
                $table->unsignedTinyInteger('price_scale')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'item_id', 'supplier_id'], 'item_suppliers_org_item_supplier_unique');
                $table->index(['organization_id', 'supplier_id'], 'item_suppliers_org_supplier_index');
                $table->index(['organization_id', 'supplier_sku'], 'item_suppliers_org_sku_index');
            });
        }

        if ($schema->hasTable('supplier_price_lists')) {
            if (! $schema->hasColumn('supplier_price_lists', 'item_supplier_id')) {
                $schema->table('supplier_price_lists', function (Blueprint $table) {
                    $table->unsignedBigInteger('item_supplier_id')->nullable()->after('supplier_id');
                    $table->index(['item_supplier_id', 'minimum_qty'], 'supplier_price_lists_relationship_tier_index');
                });
            }
            $this->widen('supplier_price_lists', 'unit_cost', false, null);
            $this->widen('supplier_price_lists', 'minimum_qty', false, '1');
            $this->attachExistingTiers();
        }
    }

    public function down(): void
    {
        // Additive release: old code ignores the new table and column. Never drop data.
    }

    private function widen(string $table, string $column, bool $nullable, ?string $default): void
    {
        $connection = DB::connection('tenant');
        $metadata = $connection->selectOne(
            'SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s, DATA_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getDatabaseName(), $table, $column]
        );
        if (! $metadata || strtolower((string) $metadata->t) !== 'decimal') {
            return;
        }
        if ((int) $metadata->s >= 6 && (int) $metadata->p - (int) $metadata->s >= 18) {
            return;
        }
        // Never narrow: keep at least the existing integer digits and scale.
        $scale = max(6, (int) $metadata->s);
        $precision = max(18, (int) $metadata->p - (int) $metadata->s) + $scale;
        $connection->statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` DECIMAL(%d,%d) %s%s',
            $table, $column, $precision, $scale,
            $nullable ? 'NULL' : 'NOT NULL',
            $default !== null ? ' DEFAULT '.$default : ''
        ));
    }

    private function attachExistingTiers(): void
    {
        $connection = DB::connection('tenant');
        $orphans = $connection->table('supplier_price_lists')->whereNull('item_supplier_id')
            ->orderBy('id')->get(['id', 'organization_id', 'item_id', 'supplier_id', 'supplier_sku', 'currency_code']);
        foreach ($orphans as $tier) {
            $relationship = $connection->table('item_suppliers')
                ->where('organization_id', $tier->organization_id)
                ->where('item_id', $tier->item_id)
                ->where('supplier_id', $tier->supplier_id)
                ->value('id');
            if (! $relationship) {
                $relationship = $connection->table('item_suppliers')->insertGetId([
                    'organization_id' => $tier->organization_id,
                    'item_id' => $tier->item_id,
                    'supplier_id' => $tier->supplier_id,
                    'status' => 'approved',
                    'supplier_sku' => $tier->supplier_sku,
                    'currency_code' => $tier->currency_code ? strtoupper((string) $tier->currency_code) : null,
                    'purchase_unit_factor' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $connection->table('supplier_price_lists')->where('id', $tier->id)
                ->update(['item_supplier_id' => $relationship]);
        }
    }
};
