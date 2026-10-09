<?php
namespace Tests\Support;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
final class FinancialOriginSchemaFixture
{
    public static function install(): void
    {
        \App\Tenancy\TenancySafetyGuard::assertTestingEnvironment();
        $db=DB::connection('tenant');
        if (env('TEST_DATABASE_ENVIRONMENT') !== 'isolated_staging' || !in_array($db->getDatabaseName(), ['solastock_test_a','solastock_test_b'],true)
            || !str_starts_with((string)env('TEST_DB_SOCKET'),'/tmp/stock-tests.') || !is_file('/qualification/host-net-namespace')
            || trim(file_get_contents('/qualification/host-net-namespace')) === readlink('/proc/self/ns/net') || $db->transactionLevel()!==0)
            throw new \RuntimeException('Origin schema setup requires sealed disposable SQL before any test transaction.');
        $s=$db->getSchemaBuilder();
        $previous = DB::getDefaultConnection(); DB::setDefaultConnection('tenant');
        try {
            // Only the base refund relation is a test projection. Every Cash/core contract field
            // and index is supplied by the exact pinned native Finance migrations.
            if (!$s->hasTable('refund_receipts')) $s->create('refund_receipts', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('organization_id')->nullable(); $t->string('status')->default('draft');
            });
            $finance = rtrim((string) env('FINANCIAL_ORIGIN_FINANCE_SOURCE', '/qualification/finance'), '/');
            foreach (['2026_10_07_187000_create_financial_origin_intents.php',
                '2026_10_07_192000_create_financial_origin_reverse_generations.php',
                '2026_10_08_194000_create_financial_origin_physical_operations.php',
                '2026_10_08_210000_add_cash_sale_source_to_refund_receipts.php',
                '2026_10_08_219000_create_cash_refund_demand_intents.php'] as $name) {
                $path = $finance.'/database/migrations/finance/'.$name;
                if (!is_file($path)) throw new \RuntimeException('Pinned Finance companion migration missing: '.$name); (require $path)->up();
            }
            (require base_path('database/migrations/tenant/2026_10_07_081000_create_purchase_valuation_holds.php'))->up();
            (require base_path('database/migrations/tenant/2026_10_07_188000_add_financial_origin_valuation_hold_identity.php'))->up();
            (require base_path('database/migrations/tenant/2026_10_08_219000_create_cash_refund_demand_holds.php'))->up();
        } finally { DB::setDefaultConnection($previous); }

        (require base_path('database/migrations/tenant/2026_10_07_186000_create_financial_origin_requests.php'))->up();
        foreach(['sales_receipts','expenses']as$table)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('customer_id')->nullable();$t->unsignedBigInteger('vendor_id')->nullable();});
        foreach(['sales_receipt_lines'=>'sales_receipt_id','expense_lines'=>'expense_id']as$table=>$parent)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t)use($parent){$t->id();$t->unsignedBigInteger($parent);$t->unsignedBigInteger('inventory_item_id');$t->decimal('qty',24,4);$t->string('unit')->nullable();$t->string('item_usage')->nullable();});
        if (!$s->hasTable('finance_document_requests')) throw new \RuntimeException('Native Finance schema not installed'); // Exact native187 above; never synthesize a weaker contract.
        if(!$s->hasColumn('journal_entries','source'))$s->table('journal_entries',fn(Blueprint $t)=>$t->string('source')->nullable());

        if (!$s->hasTable('finance_sales_requests')) $s->create('finance_sales_requests',function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->unsignedBigInteger('invoice_id');$t->unsignedBigInteger('invoice_journal_id')->nullable();$t->string('source_revision');$t->string('command');});
        if (!$s->hasColumn('journal_entries','source_type')) $s->table('journal_entries',function(Blueprint $t){$t->string('source_type')->nullable();$t->unsignedBigInteger('source_id')->nullable();});
    }
}
