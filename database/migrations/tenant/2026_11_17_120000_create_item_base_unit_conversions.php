<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('item_base_unit_conversions')) {
            $this->verify();

            return;
        }
        Schema::connection('tenant')->create('item_base_unit_conversions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('item_id');
            $t->uuid('request_uuid');
            $t->unsignedBigInteger('from_unit_id');
            $t->unsignedBigInteger('to_unit_id');
            $t->decimal('factor', 30, 12); // Diagnostic only: new quantity = old * numerator / denominator.
            $t->unsignedBigInteger('factor_numerator');
            $t->unsignedBigInteger('factor_denominator');
            $t->date('effective_date');
            $t->json('evidence');
            $t->unsignedBigInteger('prior_ledger_id');
            $t->char('revision_sha256', 64);
            $t->json('before_snapshot');
            $t->json('after_snapshot')->nullable();
            $t->json('ledger_ids')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('reason', 1000);
            $t->uuid('organization_mapping_uuid')->nullable();
            $t->string('state', 24)->default('ready');
            $t->json('finance_payload')->nullable();
            $t->json('finance_ack')->nullable();
            $t->unsignedInteger('delivery_attempts')->default(0);
            $t->string('last_error', 191)->nullable();
            $t->uuid('lease_uuid')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['organization_id', 'state', 'next_attempt_at'], 'ibuc_org_delivery');
            $t->unique(['organization_id', 'request_uuid'], 'ibuc_org_request_unique');
            $t->index(['organization_id', 'item_id', 'id'], 'ibuc_org_item_version');
            $t->foreign('item_id', 'ibuc_item_fk')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('from_unit_id', 'ibuc_from_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $t->foreign('to_unit_id', 'ibuc_to_unit_fk')->references('id')->on('units')->restrictOnDelete();
            $t->foreign('prior_ledger_id', 'ibuc_prior_ledger_fk')->references('id')->on('stock_ledger')->restrictOnDelete();
        });
        $db = DB::connection('tenant');
        $db->unprepared("CREATE TRIGGER ibuc_no_delete BEFORE DELETE ON item_base_unit_conversions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unit conversion evidence is immutable'");
        $economic = ['organization_id', 'item_id', 'request_uuid', 'from_unit_id', 'to_unit_id', 'factor', 'factor_numerator', 'factor_denominator', 'effective_date', 'evidence', 'prior_ledger_id', 'revision_sha256', 'before_snapshot', 'after_snapshot', 'ledger_ids', 'created_by', 'reason', 'created_at', 'organization_mapping_uuid', 'finance_payload'];
        $changed = implode(' OR ', array_map(fn ($c) => 'NOT (NEW.`'.$c.'` <=> OLD.`'.$c.'`)', $economic));
        $db->unprepared('CREATE TRIGGER ibuc_no_rewrite BEFORE UPDATE ON item_base_unit_conversions FOR EACH ROW BEGIN IF OLD.after_snapshot IS NOT NULL AND ('.$changed.") THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unit conversion evidence is immutable'; END IF; END");
    }

    private function verify(): void
    {
        $db = DB::connection('tenant');
        $rows = $db->select("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item_base_unit_conversions' ORDER BY ORDINAL_POSITION");
        $expected = [
            'id' => ['bigint(20) unsigned', 'NO'], 'organization_id' => ['bigint(20) unsigned', 'NO'], 'item_id' => ['bigint(20) unsigned', 'NO'], 'request_uuid' => ['char(36)', 'NO'],
            'from_unit_id' => ['bigint(20) unsigned', 'NO'], 'to_unit_id' => ['bigint(20) unsigned', 'NO'], 'factor' => ['decimal(30,12)', 'NO'], 'factor_numerator' => ['bigint(20) unsigned', 'NO'], 'factor_denominator' => ['bigint(20) unsigned', 'NO'], 'effective_date' => ['date', 'NO'], 'evidence' => ['longtext', 'NO'],
            'prior_ledger_id' => ['bigint(20) unsigned', 'NO'], 'revision_sha256' => ['char(64)', 'NO'], 'before_snapshot' => ['longtext', 'NO'], 'after_snapshot' => ['longtext', 'YES'], 'ledger_ids' => ['longtext', 'YES'], 'created_by' => ['bigint(20) unsigned', 'YES'], 'reason' => ['varchar(1000)', 'NO'],
            'organization_mapping_uuid' => ['char(36)', 'YES'], 'state' => ['varchar(24)', 'NO'], 'finance_payload' => ['longtext', 'YES'], 'finance_ack' => ['longtext', 'YES'], 'delivery_attempts' => ['int(10) unsigned', 'NO'], 'last_error' => ['varchar(191)', 'YES'], 'lease_uuid' => ['char(36)', 'YES'], 'lease_expires_at' => ['timestamp', 'YES'], 'next_attempt_at' => ['timestamp', 'YES'], 'created_at' => ['timestamp', 'NO'],
        ];
        $actual = [];
        foreach ($rows as $r) {
            $actual[$r->COLUMN_NAME] = [strtolower($r->COLUMN_TYPE), $r->IS_NULLABLE];
        }
        if ($actual !== $expected) {
            throw new RuntimeException('Stock unit conversion schema column drift.');
        }
        $indexes = [];
        foreach ($db->select("SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item_base_unit_conversions' ORDER BY INDEX_NAME,SEQ_IN_INDEX") as $r) {
            $indexes[$r->INDEX_NAME]['unique'] = ! (bool) $r->NON_UNIQUE;
            $indexes[$r->INDEX_NAME]['columns'][] = $r->COLUMN_NAME;
        }
        foreach (['PRIMARY' => [true, ['id']], 'ibuc_org_request_unique' => [true, ['organization_id', 'request_uuid']], 'ibuc_org_item_version' => [false, ['organization_id', 'item_id', 'id']], 'ibuc_org_delivery' => [false, ['organization_id', 'state', 'next_attempt_at']]] as $name => [$unique,$columns]) {
            if (($indexes[$name] ?? null) !== ['unique' => $unique, 'columns' => $columns]) {
                throw new RuntimeException('Stock unit conversion index drift: '.$name);
            }
        }
        $fk = [];
        foreach ($db->select("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item_base_unit_conversions' AND REFERENCED_TABLE_NAME IS NOT NULL") as $r) {
            $fk[$r->CONSTRAINT_NAME] = [$r->COLUMN_NAME, $r->REFERENCED_TABLE_NAME, $r->REFERENCED_COLUMN_NAME];
        }
        foreach (['ibuc_item_fk' => ['item_id', 'items', 'id'], 'ibuc_from_unit_fk' => ['from_unit_id', 'units', 'id'], 'ibuc_to_unit_fk' => ['to_unit_id', 'units', 'id'], 'ibuc_prior_ledger_fk' => ['prior_ledger_id', 'stock_ledger', 'id']] as $name => $fields) {
            if (($fk[$name] ?? null) !== $fields) {
                throw new RuntimeException('Stock unit conversion FK drift.');
            }
        }
        foreach ($db->select("SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='item_base_unit_conversions'") as $r) {
            if (! isset($fk[$r->CONSTRAINT_NAME]) || $r->UPDATE_RULE !== 'RESTRICT' || $r->DELETE_RULE !== 'RESTRICT') {
                throw new RuntimeException('Stock unit conversion FK rule drift.');
            }
        }
        $triggers = $db->select("SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='item_base_unit_conversions'");
        $names = [];
        foreach ($triggers as $t) {
            $names[$t->TRIGGER_NAME] = [$t->ACTION_TIMING, $t->EVENT_MANIPULATION];
            $body = strtolower(preg_replace('/[\s`]+/', '', $t->ACTION_STATEMENT));
            if ($t->TRIGGER_NAME === 'ibuc_no_delete' && ! str_contains($body, "signalsqlstate'45000'")) {
                throw new RuntimeException('Stock unit conversion delete guard drift.');
            }
            if ($t->TRIGGER_NAME === 'ibuc_no_rewrite') {
                foreach (['organization_id', 'item_id', 'request_uuid', 'from_unit_id', 'to_unit_id', 'factor', 'factor_numerator', 'factor_denominator', 'effective_date', 'evidence', 'prior_ledger_id', 'revision_sha256', 'before_snapshot', 'after_snapshot', 'ledger_ids', 'created_by', 'reason', 'created_at', 'organization_mapping_uuid', 'finance_payload'] as $column) {
                    if (! str_contains($body, 'new.'.$column.'<=>old.'.$column)) {
                        throw new RuntimeException('Stock unit conversion economic guard drift.');
                    }
                }
                if (! str_contains($body, 'old.after_snapshotisnotnull') || ! str_contains($body, "signalsqlstate'45000'")) {
                    throw new RuntimeException('Stock unit conversion update guard drift.');
                }
            }
        }
        if (($names['ibuc_no_delete'] ?? null) !== ['BEFORE', 'DELETE'] || ($names['ibuc_no_rewrite'] ?? null) !== ['BEFORE', 'UPDATE']) {
            throw new RuntimeException('Stock unit conversion immutable trigger drift.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Applied unit conversion evidence is forward-only.');
    }
};
