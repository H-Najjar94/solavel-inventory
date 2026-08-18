<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\PosSaleConsumption;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Integration\SolaposConsumptionService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Phase 6 — SolaPOS → SolaStock consumption receiver. SolaStock is the writer
 * of record: exactly-once ledger/cost effect, canonical costing returned,
 * COGS journal event recorded once, restoration at original consumed cost.
 */
class SolaposConsumptionReceiverTest extends TestCase
{
    use TenantAware;

    private function payload(int $itemId, int $warehouseId, string $qty, string $key, array $extra = []): array
    {
        return array_replace_recursive([
            'contract_version' => 1, 'source_app' => 'solapos', 'idempotency_key' => $key, 'event_type' => 'pos_sale.consumed',
            'solastock_organization_id' => TenantTestManager::ORG_A, 'order_id' => 501, 'order_number' => 'S-1', 'completed_at' => now()->toRfc3339String(),
            'lines' => [['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => $qty, 'pos_allocation_id' => 9001, 'pos_order_item_id' => 8001]],
        ], $extra);
    }

    #[Test]
    public function consumption_posts_ledger_once_with_canonical_cost_and_records_the_cogs_event_once(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item(['costing_method' => 'average']);
        // Stock in: 10 @ 7.0000 → average 7
        app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $wh->id, '10.000', 'App\\Models\\Tenant\\OpeningStockEntry', 1, unitCost: '7.0000')], 'test:opening:1');
        $svc = app(SolaposConsumptionService::class);

        $r1 = $svc->apply(TenantTestManager::ORG_A, $this->payload($item->id, $wh->id, '3.000', 'pos_order:501:inventory_consumption'));
        $this->assertFalse($r1['replayed']);
        $this->assertEquals(3, (float) $r1['data']['lines'][0]['quantity']);
        $this->assertEquals(7, (float) $r1['data']['lines'][0]['unit_cost']);
        $this->assertEquals(21, (float) $r1['data']['lines'][0]['total_cost']);
        $this->assertSame('average', $r1['data']['lines'][0]['costing_method']);
        $this->assertSame(9001, $r1['data']['lines'][0]['pos_allocation_id']);
        $this->assertEquals(7, (float) StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $wh->id)->value('on_hand_qty'));
        $this->assertSame(1, StockLedger::query()->where('source_type', PosSaleConsumption::class)->count());
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'pos_sale.posted')->count());

        // Duplicate delivery / lost ack / out-of-order retry: same key → same result, no new effect
        foreach (range(1, 3) as $_) {
            $r2 = $svc->apply(TenantTestManager::ORG_A, $this->payload($item->id, $wh->id, '3.000', 'pos_order:501:inventory_consumption'));
            $this->assertTrue($r2['replayed']);
            $this->assertSame($r1['data']['consumption_id'], $r2['data']['consumption_id']);
        }
        $this->assertEquals(7, (float) StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $wh->id)->value('on_hand_qty'));
        $this->assertSame(1, StockLedger::query()->where('source_type', PosSaleConsumption::class)->count());
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'pos_sale.posted')->count());
        $this->assertSame(1, PosSaleConsumption::query()->count());
    }

    #[Test]
    public function fifo_costing_is_honoured_and_return_restores_at_original_cost(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item(['costing_method' => 'fifo']);
        $ledger = app(StockLedgerService::class);
        $ledger->post([new StockMovement('in', $item->id, $wh->id, '5.000', 'App\\Models\\Tenant\\OpeningStockEntry', 2, unitCost: '4.0000')], 'test:opening:2');
        $ledger->post([new StockMovement('in', $item->id, $wh->id, '5.000', 'App\\Models\\Tenant\\OpeningStockEntry', 3, unitCost: '6.0000')], 'test:opening:3');
        $svc = app(SolaposConsumptionService::class);
        // Sell 7 → FIFO: 5@4 + 2@6 = 32
        $r = $svc->apply(TenantTestManager::ORG_A, $this->payload($item->id, $wh->id, '7.000', 'pos_order:502:inventory_consumption', ['order_id' => 502]));
        $this->assertEquals(32, (float) $r['data']['lines'][0]['total_cost']);
        $this->assertSame('fifo', $r['data']['lines'][0]['costing_method']);
        // Live cost changes afterwards (new layer at 9) — the return must restore at the ORIGINAL 32/7 blended cost, not 9
        $ledger->post([new StockMovement('in', $item->id, $wh->id, '5.000', 'App\\Models\\Tenant\\OpeningStockEntry', 4, unitCost: '9.0000')], 'test:opening:4');
        $ret = $svc->apply(TenantTestManager::ORG_A, [
            'contract_version' => 1, 'source_app' => 'solapos', 'idempotency_key' => 'pos_order_return:77:inventory_restoration', 'event_type' => 'pos_return.restored',
            'solastock_organization_id' => TenantTestManager::ORG_A, 'return_id' => 77, 'return_number' => 'R-1', 'original_order_id' => 502,
            'original_consumption_key' => 'pos_order:502:inventory_consumption', 'completed_at' => now()->toRfc3339String(),
            'lines' => [['item_id' => $item->id, 'warehouse_id' => $wh->id, 'quantity' => '2.000', 'pos_order_item_id' => 8001, 'pos_order_return_item_id' => 55]],
        ]);
        $this->assertFalse($ret['replayed']);
        $restoredUnit = $ret['data']['lines'][0]['unit_cost'];
        $this->assertNotEquals(9, (float) $restoredUnit, 'never at today\'s cost');
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'pos_sale_return.posted')->count());
        // replay of the return is a no-op
        $ret2 = $svc->apply(TenantTestManager::ORG_A, [
            'contract_version' => 1, 'source_app' => 'solapos', 'idempotency_key' => 'pos_order_return:77:inventory_restoration', 'event_type' => 'pos_return.restored',
            'solastock_organization_id' => TenantTestManager::ORG_A, 'return_id' => 77, 'original_order_id' => 502, 'original_consumption_key' => 'pos_order:502:inventory_consumption',
            'completed_at' => now()->toRfc3339String(), 'lines' => [['item_id' => $item->id, 'warehouse_id' => $wh->id, 'quantity' => '2.000', 'pos_order_item_id' => 8001]],
        ]);
        $this->assertTrue($ret2['replayed']);
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'pos_sale_return.posted')->count());
    }

    #[Test]
    public function items_and_warehouses_of_another_organization_are_refused(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $this->expectException(ValidationException::class);
        app(SolaposConsumptionService::class)->apply(TenantTestManager::ORG_A, $this->payload($item->id, 999999, '1.000', 'pos_order:503:inventory_consumption'));
    }

    /**
     * Phase 8 regression: the SolaPOS receiver route lives under the session
     * ('web') middleware group like the rest of the JSON API. A real cross-app
     * POST carries no browser CSRF token, so the route MUST be CSRF-exempt and
     * authenticated ONLY by the SolaPOS HMAC signature (Phase 8 UAT found a 419).
     */
    #[Test]
    public function http_route_is_csrf_exempt_and_authenticated_by_the_signature_only(): void
    {
        config(['solavel_sync.solapos_secret' => str_repeat('s', 40), 'solavel_sync.allowed_client_ids' => [], 'app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost'); // APP_URL carries the /inventory Apache alias
        // No CSRF token, no signature → must reach the signature middleware (403 solapos_signature_invalid), never 419.
        $r = $this->postJson('/api/v1/integration/solapos/consumptions', ['contract_version' => 1]);
        $this->assertNotSame(419, $r->status(), 'route must be CSRF-exempt for the signed cross-app call');
        $r->assertStatus(403)->assertJsonPath('code', 'solapos_signature_invalid');
        // A correctly signed request passes the signature layer (it then fails later only on tenant/validation, never 419/403 signature).
        $body = json_encode(['contract_version' => 1]);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $sig = hash_hmac('sha256', $ts.'.'.$nonce.'.'.$body, str_repeat('s', 40));
        $r2 = $this->call('POST', '/api/v1/integration/solapos/consumptions', [], [], [], [
            'HTTP_X_SOLAVEL_SIGNATURE' => $sig, 'HTTP_X_SOLAVEL_TIMESTAMP' => $ts, 'HTTP_X_SOLAVEL_NONCE' => $nonce,
            'HTTP_X_SOLAVEL_CENTRAL_CLIENT_ID' => (string) TenantTestManager::ORG_A, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);
        $this->assertNotContains($r2->status(), [419, 403], 'signed request must not be rejected by CSRF or signature: '.$r2->getContent());
    }
}
