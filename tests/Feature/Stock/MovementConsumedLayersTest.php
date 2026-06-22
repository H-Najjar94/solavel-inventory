<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockLedger;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\OpeningStockService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Tenancy\OrganizationContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Phase 1 — read-only "which FIFO layers did this movement consume?" visibility.
 * An OUT over two layers (10@$5, 10@$8) consuming 15 must report 10@$5 + 5@$8;
 * an inbound movement reports none. Org-scoped + permission-gated; no mutation.
 */
class MovementConsumedLayersTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false]
        );
    }

    /** Seed 10@$5 then 10@$8 and issue 15 OUT; return [outLedger, inLedger]. */
    private function seedAndConsume(): array
    {
        $wh = F::warehouse();
        $item = F::fifoItem();
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'CL-1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        $os->post($os->createDraft(['entry_number' => 'CL-2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '8.0000']]));

        app(StockLedgerService::class)->post([
            new StockMovement(direction: 'out', itemId: $item->id, warehouseId: $wh->id,
                quantity: '15.0000', sourceType: 'manual_test', sourceId: 1),
        ], 'consumed:out');

        $out = StockLedger::query()->where('item_id', $item->id)->where('direction', 'out')->firstOrFail();
        $in = StockLedger::query()->where('item_id', $item->id)->where('direction', 'in')->firstOrFail();

        return [$out, $in];
    }

    private function consumedLayers(StockLedger $ledger): array
    {
        return app(StockLedgerController::class)->consumedLayers($ledger)->getData(true)['data'];
    }

    #[Test]
    public function an_out_movement_reports_the_exact_fifo_layers_it_consumed(): void
    {
        $this->boot();
        [$out] = $this->seedAndConsume();

        $data = $this->consumedLayers($out);

        $this->assertSame('out', $data['direction']);
        $this->assertSame(2, $data['consumed_layer_count']);
        $this->assertSame('90.00', $data['consumed_total_value']); // 50 + 40

        $layers = $data['layers'];
        $this->assertSame(['10.0000', '5.0000'], [$layers[0]['qty'], $layers[0]['unit_cost']]);
        $this->assertSame('50.00', $layers[0]['total_value']);
        $this->assertSame(['5.0000', '8.0000'], [$layers[1]['qty'], $layers[1]['unit_cost']]);
        $this->assertSame('40.00', $layers[1]['total_value']);

        // Source-layer reference + dates are present.
        $this->assertNotNull($layers[0]['source_layer']);
        $this->assertArrayHasKey('received_at', $layers[0]['source_layer']);
        $this->assertNotNull($layers[0]['consumed_at']);
    }

    #[Test]
    public function an_inbound_movement_reports_no_consumed_layers(): void
    {
        $this->boot();
        [, $in] = $this->seedAndConsume();

        $data = $this->consumedLayers($in);

        $this->assertSame('in', $data['direction']);
        $this->assertSame(0, $data['consumed_layer_count']);
        $this->assertSame('0.00', $data['consumed_total_value']);
        $this->assertSame([], $data['layers']);
    }

    #[Test]
    public function consumed_layers_are_org_scoped(): void
    {
        $this->boot();
        [$out] = $this->seedAndConsume();
        $outId = $out->id;

        // Under ORG_B the OUT ledger row must not resolve at all (route binding
        // would 404) — isolation comes from the global OrganizationScope.
        app(OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->assertNull(
            StockLedger::query()->find($outId),
            'a movement from ORG_A must be invisible under ORG_B'
        );
    }

    #[Test]
    public function reading_consumed_layers_mutates_nothing(): void
    {
        $this->boot();
        [$out] = $this->seedAndConsume();

        $ledgerBefore = StockLedger::query()->count();
        $consBefore = \App\Models\Tenant\CostLayerConsumption::query()->count();

        $this->consumedLayers($out);

        $this->assertSame($ledgerBefore, StockLedger::query()->count(), 'read must not add ledger rows');
        $this->assertSame($consBefore, \App\Models\Tenant\CostLayerConsumption::query()->count(),
            'read must not add consumption rows');
    }

    #[Test]
    public function a_viewer_may_read_movements_but_cannot_mutate(): void
    {
        app(OrganizationContext::class)->set(660066); // non-demo, non-reserved org
        // Any unknown central role → least-privilege viewer.
        $perms = new class(app(OrganizationContext::class), 'some_unknown_role') extends InventoryPermissionService {
            public function __construct(OrganizationContext $ctx, private ?string $forced)
            {
                parent::__construct($ctx);
            }

            protected function fetchCentralRole(int $userId, int $orgId): ?string
            {
                return $this->forced;
            }
        };
        $user = (object) ['id' => 4242];

        // The consumed-layers route is gated by inventory.view_ledger — a viewer has it.
        $this->assertTrue($perms->can($user, 'inventory.view_ledger'), 'viewer can read the ledger');
        // But cannot perform any mutating inventory action.
        foreach (['inventory.manage_items', 'inventory.manage_adjustments', 'inventory.manage_shipments'] as $perm) {
            $this->assertFalse($perms->can($user, $perm), "viewer must NOT {$perm}");
        }

        app(OrganizationContext::class)->forget();
    }
}
