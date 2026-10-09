<?php
$source=getcwd();
if (!str_starts_with($source,'/tmp/stock-tests.') || !str_ends_with($source,'/source') || getenv('STOCK_PRIVATE_ORIGIN_SCHEMA')!=='1') throw new RuntimeException('Sealed private working copy required');
require $source.'/vendor/autoload.php';
$app=require $source.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $source.'/tests/Support/FinancialOriginSchemaFixture.php';
$original=config('database.connections.tenant.database');
try {
 foreach (['solastock_test_a','solastock_test_b'] as $database) {
  config(['database.connections.tenant.database'=>$database]);
  \Illuminate\Support\Facades\DB::purge('tenant');
  \Tests\Support\FinancialOriginSchemaFixture::install();
  fwrite(STDERR,'PRIVATE_ORIGIN_SCHEMA_READY='.$database.PHP_EOL);
 }
} finally {
 config(['database.connections.tenant.database'=>$original]);
 \Illuminate\Support\Facades\DB::purge('tenant');
}
