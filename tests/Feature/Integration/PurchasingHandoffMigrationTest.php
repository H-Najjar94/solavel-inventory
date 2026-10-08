<?php

namespace Tests\Feature\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class PurchasingHandoffMigrationTest extends TestCase
{
    use TenantAware;

    public function test_interrupted_first_table_migration_resumes_without_recreating_or_losing_existing_facts(): void
    {
        $this->useTenantA();
        // MariaDB DDL commits implicitly; end the fixture transaction before testing recovery.
        while (DB::connection('tenant')->transactionLevel() > 0) {
            DB::connection('tenant')->rollBack();
        }
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('purchasing_document_outbox');
        $schema->dropIfExists('purchasing_receiving_request_lines');
        $schema->table('goods_receipts', fn (Blueprint $t) => $t->dropColumn('receiving_request_id'));
        $schema->table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn('receiving_request_line_id'));
        $uuid = (string) Str::uuid();
        $id = DB::connection('tenant')->table('purchasing_receiving_requests')->insertGetId([
            'organization_id' => TenantTestManager::ORG_A, 'request_uuid' => $uuid,
            'organization_mapping_uuid' => (string) Str::uuid(), 'finance_organization_id' => 14,
            'source_bill_id' => 901, 'source_bill_number' => 'PRESERVED-901', 'source_revision' => str_repeat('a', 64),
            'supplier_id' => 7, 'currency_code' => 'EUR', 'status' => 'pending',
            'source_payload' => json_encode(['known_fact' => 'keep exactly']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $original = DB::connection('tenant')->table('purchasing_receiving_requests')->where('id', $id)->first();
        try {
            $migration = require database_path('migrations/tenant/2026_10_06_160000_create_purchasing_handoffs.php');
            $migration->up();
            $migration->up();
            $this->assertEquals($original, DB::connection('tenant')->table('purchasing_receiving_requests')->where('id', $id)->first());
            foreach (['purchasing_receiving_requests', 'purchasing_receiving_request_lines', 'purchasing_document_outbox'] as $table) {
                $this->assertTrue($schema->hasTable($table));
            }
            $this->assertTrue($schema->hasColumn('goods_receipts', 'receiving_request_id'));
            $this->assertTrue($schema->hasColumn('goods_receipt_lines', 'receiving_request_line_id'));
            $this->assertTrue($schema->hasIndex('purchasing_receiving_requests', 'prr_org_bill_unique'));
            $this->assertTrue($schema->hasIndex('purchasing_receiving_request_lines', 'prr_line_source_unique'));
            $this->assertTrue($schema->hasIndex('purchasing_document_outbox', 'purchasing_document_outbox_source_key_unique'));
            $this->assertTrue($schema->hasIndex('goods_receipts', 'goods_receipts_receiving_request_id_index'));
            $this->assertTrue($schema->hasIndex('goods_receipt_lines', 'goods_receipt_lines_receiving_request_line_id_index'));
        } finally {
            DB::connection('tenant')->table('purchasing_receiving_requests')->where('request_uuid', $uuid)->delete();
        }
    }
}
