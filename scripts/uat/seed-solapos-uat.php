<?php

use App\Models\Tenant\Item;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Warehouse;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;

/**
 * Phase 8 UAT seed for the SolaPOS ↔ SolaStock pilot verification.
 * REFUSES to run unless TEST_DATABASE_ENVIRONMENT=isolated_staging and the
 * target database matches /^tenant_99\d{4}$/ (isolated staging tenants only).
 *
 *   php scripts/uat/seed-solapos-uat.php <tenant_db> <organization_id> [run-tag]
 * A run-tag suffixes the SKUs/warehouse code so a walk-through can be repeated
 * (the SolaStock stock ledger is append-only by design — fixtures are never deleted).
 *
 * Creates (idempotently by SKU/code) a warehouse and four items with opening
 * stock through StockLedgerService (real cost layers), so the SolaPOS costing
 * proof can consume the actual FIFO layer / average:
 *   UAT-MILK  average  50 @ 0.7000
 *   UAT-RICE  fifo     layer1 20 @ 1.0000, layer2 30 @ 1.5000 (list cost 1.5000)
 *   UAT-COLA  average  100 @ 0.2500
 *   UAT-SOAP  fifo     10 @ 2.0000
 * Prints JSON with the ids for the POS side.
 */
require __DIR__.'/../../vendor/autoload.php';
$db = $argv[1] ?? '';
$org = (int) ($argv[2] ?? 0);
$tag = preg_replace('/[^A-Za-z0-9]/', '', (string) ($argv[3] ?? ''));
$sfx = $tag !== '' ? '-'.strtoupper($tag) : '';
if (getenv('TEST_DATABASE_ENVIRONMENT') !== 'isolated_staging' || ! preg_match('/^tenant_99\d{4}$/', $db) || $org <= 0) {
    fwrite(STDERR, "REFUSING: isolated staging only (TEST_DATABASE_ENVIRONMENT=isolated_staging, tenant_99NNNN, org id).\n");
    exit(2);
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenants = app(TenantManager::class);
$tenants->switchToDatabase($db);
app(OrganizationContext::class)->set($org);
$ledger = app(StockLedgerService::class);

$wh = Warehouse::query()->withoutGlobalScopes()->where('organization_id', $org)->where('code', 'UAT-POS'.$sfx)->first()
    ?: Warehouse::create(['organization_id' => $org, 'code' => 'UAT-POS'.$sfx, 'name' => 'UAT POS Store'.$sfx, 'type' => 'warehouse', 'is_active' => true]);
$defs = [
    ['UAT-MILK', 'UAT Milk 1L', 'average', [['50.000', '0.7000']]],
    ['UAT-RICE', 'UAT Rice 1kg', 'fifo', [['20.000', '1.0000'], ['30.000', '1.5000']]],
    ['UAT-COLA', 'UAT Cola 330ml', 'average', [['100.000', '0.2500']]],
    ['UAT-SOAP', 'UAT Soap', 'fifo', [['10.000', '2.0000']]],
];
$out = ['database' => $db, 'organization_id' => $org, 'warehouse_id' => $wh->id, 'items' => []];
foreach ($defs as [$base, $name, $method, $layers]) {
    $sku = $base.$sfx;
    $item = Item::query()->withoutGlobalScopes()->where('organization_id', $org)->where('sku', $sku)->first();
    $created = false;
    if (! $item) {
        $item = Item::create(['organization_id' => $org, 'sku' => $sku, 'name' => $name, 'item_type' => 'inventory', 'tracking_type' => 'none', 'costing_method' => $method, 'is_active' => true, 'purchase_price' => end($layers)[1], 'sales_price' => '2.0000']);
        $created = true;
        $n = 0;
        foreach ($layers as [$qty, $cost]) {
            $n++;
            $ledger->post([new StockMovement('in', $item->id, $wh->id, $qty, OpeningStockEntry::class, $item->id * 10 + $n, unitCost: $cost)], "uat:opening:{$sku}:{$n}");
        }
    }
    $bal = StockBalance::query()->withoutGlobalScopes()->where('item_id', $item->id)->where('warehouse_id', $wh->id)->first();
    $out['items'][$base] = ['item_id' => $item->id, 'sku' => $sku, 'costing_method' => $method, 'created' => $created, 'on_hand' => $bal?->on_hand_qty, 'layers' => $layers];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
