<?php

use App\Models\Tenant\OpeningStockEntry;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;

/**
 * Phase 8 UAT helper: receive stock into SolaStock through its own StockLedgerService.
 * Isolated staging only (TEST_DATABASE_ENVIRONMENT=isolated_staging + tenant_99NNNN).
 *   php scripts/uat/receive-stock.php <tenant_db> <org> <item_id> <warehouse_id> <qty> <unit_cost>
 */
require __DIR__.'/../../vendor/autoload.php';
[$db, $org, $itemId, $whId, $qty, $cost] = [$argv[1] ?? '', (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), (string) ($argv[5] ?? ''), (string) ($argv[6] ?? '')];
if (getenv('TEST_DATABASE_ENVIRONMENT') !== 'isolated_staging' || ! preg_match('/^tenant_99\d{4}$/', $db) || $org <= 0 || $itemId <= 0 || $whId <= 0) {
    fwrite(STDERR, "REFUSING: isolated staging only.\n");
    exit(2);
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(TenantManager::class)->switchToDatabase($db);
app(OrganizationContext::class)->set($org);
app(StockLedgerService::class)->post(
    [new StockMovement('in', $itemId, $whId, $qty, OpeningStockEntry::class, $itemId * 100 + random_int(1, 99), unitCost: $cost)],
    'uat:receive:'.$itemId.':'.bin2hex(random_bytes(4))
);
echo json_encode(['ok' => true, 'item_id' => $itemId, 'qty' => $qty, 'unit_cost' => $cost]), "\n";
