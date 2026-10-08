<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\Item;
use App\Models\Tenant\LandedCost;
use App\Models\Tenant\LandedCostComponent;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\Warehouse;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\LandedCostService;
use App\Services\Stock\IntegrityChecker;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Standalone SolaStock landed costs: allocation math, FIFO/AVG revaluation of
 * the on-hand share, the consumed share through the cost-adjustment disposition
 * rules, truthful refusals, exact reversal, and no Finance event.
 */
class LandedCostTest extends TestCase
{
    use TenantAware;

    private function receive(Warehouse $warehouse, Item $item, string $qty, string $cost, string $number): GoodsReceipt
    {
        $service = app(GoodsReceiptService::class);
        $receipt = $service->createDraft(['grn_number' => $number, 'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString()],
            [['item_id' => $item->id, 'received_qty' => $qty, 'accepted_qty' => $qty, 'unit_cost' => $cost]]);

        return $service->post($receipt)->fresh('lines');
    }

    private function out(Warehouse $warehouse, Item $item, string $qty, string $sourceType, int $sourceId): void
    {
        app(StockLedgerService::class)->post([new StockMovement('out', $item->id, $warehouse->id, $qty, $sourceType, $sourceId)],
            'landed-cost-test-out:'.$sourceType.':'.$sourceId);
    }

    private function draft(array $lineIds, array $charges, string $method = 'quantity', array $attributes = []): LandedCost
    {
        return app(LandedCostService::class)->createDraft($attributes + ['allocation_method' => $method, 'currency_code' => 'JOD', 'exchange_rate' => '1'],
            $charges, $lineIds);
    }

    /**
     * Runs $call with the tenant query log on and returns the indexes of the
     * item, balance and layer locks and of the first non-locking data read.
     *
     * @return array{items:int|false,balances:int|false,layers:int|false,first_plain:int|false}
     */
    private function lockOrder(\Closure $call): array
    {
        $db = DB::connection('tenant');
        $db->flushQueryLog();
        $db->enableQueryLog();
        try {
            $call();
        } finally {
            $log = array_map(fn ($q) => strtolower($q['query']), $db->getQueryLog());
            $db->disableQueryLog();
        }
        $find = fn (string $table) => collect($log)->search(fn ($q) => str_contains($q, 'from `'.$table.'`') && str_contains($q, 'for update'));

        return [
            'items' => $find('items'),
            'balances' => $find('stock_balances'),
            'layers' => $find('cost_layers'),
            // A plain SELECT fixes the REPEATABLE READ view; schema probes do not read rows.
            'first_plain' => collect($log)->search(fn ($q) => str_starts_with(ltrim($q), 'select')
                && ! str_contains($q, 'for update') && ! str_contains($q, 'information_schema')),
        ];
    }

    private function assertLocksPrecedeFirstPlainRead(array $order): void
    {
        foreach (['items', 'balances', 'layers', 'first_plain'] as $key) {
            $this->assertIsInt($order[$key], $key);
        }
        $this->assertLessThan($order['first_plain'], $order['items']);
        $this->assertLessThan($order['first_plain'], $order['balances']);
        $this->assertLessThan($order['first_plain'], $order['layers']);
    }

    private function message(\Closure $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }
        $this->fail('A ValidationException was expected.');
    }

    #[Test]
    public function allocation_by_value_quantity_and_weight_sums_exactly_with_largest_remainder(): void
    {
        $this->assertSame(['a' => '33.34', 'b' => '33.33', 'c' => '33.33'], LandedCostService::allocate('100.00', ['a' => '1', 'b' => '1', 'c' => '1']));
        $this->assertSame([1 => '40.00', 2 => '60.00'], LandedCostService::allocate('100.00', [1 => '40.00', 2 => '60.00']));
        // 0.05 over 3/3/1 units: exact 0.02142… / 0.02142… / 0.00714…; the remainders go to the largest fractions.
        $this->assertSame([1 => '0.02', 2 => '0.02', 3 => '0.01'], LandedCostService::allocate('0.05', [1 => '3', 2 => '3', 3 => '1']));
        $this->assertSame([7 => '12.50', 8 => '37.50'], LandedCostService::allocate('50.00', [7 => '2.5000', 8 => '7.5000']));
        // Finance convention: 1 base = rate transaction units, so base = amount ÷ rate.
        $this->assertSame('141.04', app(LandedCostService::class)->toBase('100.0000', '0.709'));
        $this->assertSame('100.00', app(LandedCostService::class)->toBase('70.0000', '0.7'));
        $this->expectException(ValidationException::class);
        LandedCostService::allocate('10.00', [1 => '0', 2 => '0']);
    }

    #[Test]
    public function fifo_landed_cost_revalues_the_on_hand_layer_and_sends_the_sold_share_to_cogs_without_any_finance_event(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-FIFO-WH']);
        $item = F::fifoItem(['sku' => 'LC-FIFO']);
        $receipt = $this->receive($warehouse, $item, '10', '4', 'LC-FIFO-GRN');
        $this->out($warehouse, $item, '4', Shipment::class, 91001);

        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'freight', 'amount' => '15'], ['charge_type' => 'insurance', 'amount' => '5']]);
        $this->assertSame('20.00', (string) $doc->total_base_amount);
        $preview = app(LandedCostService::class)->preview($doc);
        $this->assertTrue($preview['ready']);
        $this->assertSame(['inventory_asset' => '12.00', 'cogs' => '8.00', 'adjustment_loss' => '0.00'], $preview['totals']);

        $posted = app(LandedCostService::class)->post($doc);
        app(LandedCostService::class)->post($doc->fresh()); // idempotent

        $this->assertSame('posted', $posted->status);
        $this->assertSame('12.00', (string) $posted->inventory_base_amount);
        $this->assertSame('8.00', (string) $posted->consumed_base_amount);
        $components = LandedCostComponent::query()->where('landed_cost_id', $doc->id)->orderBy('id')->get();
        $this->assertSame(['inventory_asset', 'cogs'], $components->pluck('destination_role')->all());
        $this->assertSame(['12.00', '8.00'], $components->pluck('posted_base_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(Shipment::class, $components[1]->destination_source_type);
        $layer = CostLayer::query()->where('item_id', $item->id)->sole();
        $this->assertSame('6.0000', (string) $layer->unit_cost);
        $this->assertSame('6.0000', (string) $layer->remaining_qty);
        $balance = StockBalance::query()->where('item_id', $item->id)->sole();
        $this->assertSame('36.00', (string) $balance->total_value);
        $this->assertSame('6.0000', (string) $balance->average_cost);
        $this->assertSame(0, IntegrationOutboxEvent::query()->where('event_type', 'like', 'landed_cost.%')->count());
        $this->assertTrue(app(IntegrityChecker::class)->check('tenant', TenantTestManager::ORG_A)['ok']);
    }

    #[Test]
    public function average_cost_landed_cost_splits_through_later_outflows_and_a_write_off_goes_to_adjustment_loss(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-AVG-WH']);
        $item = F::averageItem(['sku' => 'LC-AVG']);
        $receipt = $this->receive($warehouse, $item, '10', '5', 'LC-AVG-GRN');
        $this->out($warehouse, $item, '2', StockAdjustment::class, 91002);

        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'duty', 'amount' => '10']], 'value');
        app(LandedCostService::class)->post($doc);

        $components = LandedCostComponent::query()->where('landed_cost_id', $doc->id)->get()->keyBy('destination_role');
        $this->assertSame('2.00', (string) $components['adjustment_loss']->posted_base_amount);
        $this->assertSame('8.00', (string) $components['inventory_asset']->posted_base_amount);
        $balance = StockBalance::query()->where('item_id', $item->id)->sole();
        $this->assertSame('8.0000', (string) $balance->on_hand_qty);
        $this->assertSame('48.00', (string) $balance->total_value);
        $this->assertSame('6.0000', (string) $balance->average_cost);
        $this->assertTrue(app(IntegrityChecker::class)->check('tenant', TenantTestManager::ORG_A)['ok']);
    }

    #[Test]
    public function foreign_currency_costs_convert_at_the_document_rate_and_allocate_by_value_and_weight(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-FX-WH']);
        $light = F::fifoItem(['sku' => 'LC-LIGHT', 'weight' => '1.0000']);
        $heavy = F::fifoItem(['sku' => 'LC-HEAVY', 'weight' => '6.0000']);
        $a = $this->receive($warehouse, $light, '10', '4', 'LC-FX-A');
        $b = $this->receive($warehouse, $heavy, '5', '12', 'LC-FX-B');
        $lines = [$a->lines->sole()->id, $b->lines->sole()->id];

        $byValue = $this->draft($lines, [['charge_type' => 'freight', 'amount' => '70']], 'value', ['currency_code' => 'USD', 'exchange_rate' => '0.7']);
        $this->assertSame('USD', $byValue->currency_code);
        $this->assertSame('100.00', (string) $byValue->total_base_amount);
        $this->assertSame(['40.00', '60.00'], $byValue->lines->pluck('allocated_base_amount')->map(fn ($v) => (string) $v)->all());

        // Weight basis: 10 × 1 = 10 vs 5 × 6 = 30.
        $byWeight = $this->draft($lines, [['charge_type' => 'freight', 'amount' => '70']], 'weight', ['currency_code' => 'USD', 'exchange_rate' => '0.7']);
        $this->assertSame(['25.00', '75.00'], $byWeight->lines->pluck('allocated_base_amount')->map(fn ($v) => (string) $v)->all());
        app(LandedCostService::class)->post($byWeight);
        $this->assertSame('6.5000', (string) CostLayer::query()->where('item_id', $light->id)->value('unit_cost'));
        $this->assertSame('27.0000', (string) CostLayer::query()->where('item_id', $heavy->id)->value('unit_cost'));

        $noWeight = F::fifoItem(['sku' => 'LC-NOWEIGHT']);
        $c = $this->receive($warehouse, $noWeight, '1', '1', 'LC-FX-C');
        $message = $this->message(fn () => $this->draft([$c->lines->sole()->id], [['charge_type' => 'freight', 'amount' => '1']], 'weight'));
        $this->assertSame(__('inventory.landed_cost.weight_missing', ['item' => 'LC-NOWEIGHT']), $message);
    }

    #[Test]
    public function transferred_or_unclassified_dispositions_are_refused_truthfully_and_nothing_changes(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-REF-WH']);
        $moved = F::fifoItem(['sku' => 'LC-MOVED']);
        $receipt = $this->receive($warehouse, $moved, '10', '4', 'LC-REF-GRN');
        $this->out($warehouse, $moved, '3', StockTransfer::class, 91003);
        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'freight', 'amount' => '10']]);

        $this->assertSame(__('inventory.landed_cost.disposition_transfer'), $this->message(fn () => app(LandedCostService::class)->post($doc)));
        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame(0, LandedCostComponent::query()->where('landed_cost_id', $doc->id)->count());
        $this->assertSame('4.0000', (string) CostLayer::query()->where('item_id', $moved->id)->value('unit_cost'));
        $this->assertFalse(app(LandedCostService::class)->preview($doc)['ready']);

        $odd = F::fifoItem(['sku' => 'LC-ODD']);
        $other = $this->receive($warehouse, $odd, '2', '4', 'LC-ODD-GRN');
        $this->out($warehouse, $odd, '1', self::class, 91004);
        $second = $this->draft([$other->lines->sole()->id], [['charge_type' => 'other', 'amount' => '2']]);
        $this->assertSame(__('inventory.landed_cost.disposition_unsupported'), $this->message(fn () => app(LandedCostService::class)->post($second)));

        $standard = F::item(['sku' => 'LC-STD', 'costing_method' => 'standard']);
        $this->assertNotSame('', $this->message(fn () => $this->draft([$this->receive($warehouse, $standard, '1', '1', 'LC-STD-GRN')->lines->sole()->id],
            [['charge_type' => 'freight', 'amount' => '1']])));
    }

    #[Test]
    public function reversal_is_the_exact_inverse_and_is_refused_once_revalued_stock_has_moved(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-REV-WH']);
        $item = F::fifoItem(['sku' => 'LC-REV']);
        $receipt = $this->receive($warehouse, $item, '10', '4', 'LC-REV-GRN');
        $this->out($warehouse, $item, '4', Shipment::class, 91005);
        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'freight', 'amount' => '20']]);
        app(LandedCostService::class)->post($doc);

        $reversal = app(LandedCostService::class)->reverse($doc, 'Freight billed to the wrong receipt');
        $again = app(LandedCostService::class)->reverse($doc->fresh(), 'Duplicate click');

        $this->assertSame($reversal->id, $again->id);
        $this->assertSame('landed_cost', $reversal->source_type);
        $this->assertSame($doc->id, (int) $reversal->source_id);
        $this->assertSame(1, InventoryReversal::query()->where('source_type', 'landed_cost')->count());
        $this->assertSame('reversed', $doc->fresh()->status);
        $this->assertSame($reversal->id, (int) $doc->fresh()->reversal_id);
        $this->assertSame('4.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('unit_cost'));
        $this->assertSame('24.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
        $this->assertSame(0, IntegrationOutboxEvent::query()->where('event_type', 'like', 'landed_cost.%')->count());
        $this->assertTrue(app(IntegrityChecker::class)->check('tenant', TenantTestManager::ORG_A)['ok']);

        $later = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'duty', 'amount' => '6']]);
        app(LandedCostService::class)->post($later);
        $this->out($warehouse, $item, '1', Shipment::class, 91006);
        $this->assertSame(__('inventory.landed_cost.reverse_moved'), $this->message(fn () => app(LandedCostService::class)->reverse($later, 'Too late')));
        $this->assertSame('posted', $later->fresh()->status);
    }

    #[Test]
    public function posting_locks_the_stock_before_any_plain_read_so_the_plan_sees_a_shipment_committed_just_before(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-LOCK-WH']);
        $item = F::fifoItem(['sku' => 'LC-LOCK']);
        $receipt = $this->receive($warehouse, $item, '10', '4', 'LC-LOCK-GRN');
        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'freight', 'amount' => '20']]);
        $this->assertSame(['inventory_asset' => '20.00', 'cogs' => '0.00', 'adjustment_loss' => '0.00'],
            app(LandedCostService::class)->preview($doc)['totals']);

        // Sequenced stand-in for the race: this shipment commits after the draft
        // was saved and immediately before the post asks for the item lock.
        $this->out($warehouse, $item, '4', Shipment::class, 91011);
        $order = $this->lockOrder(fn () => app(LandedCostService::class)->post($doc));

        // Every stock lock precedes the first non-locking read, so the read view
        // the planner uses is taken after any committed movement of these items.
        $this->assertLocksPrecedeFirstPlainRead($order);
        $components = LandedCostComponent::query()->where('landed_cost_id', $doc->id)->get()->keyBy('destination_role');
        $this->assertSame('12.00', (string) $components['inventory_asset']->posted_base_amount);
        $this->assertSame('8.00', (string) $components['cogs']->posted_base_amount);
        $this->assertSame('6.0000', (string) data_get($components['inventory_asset']->provenance, 'layer_remaining_at_post'));
        $layer = CostLayer::query()->where('item_id', $item->id)->sole();
        $this->assertSame('6.0000', (string) $layer->unit_cost);
        $this->assertSame('6.0000', (string) $layer->remaining_qty);
        $this->assertSame('36.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
        $this->assertTrue(app(IntegrityChecker::class)->check('tenant', TenantTestManager::ORG_A)['ok']);

        // Still exactly reversible: nothing moved after the post.
        app(LandedCostService::class)->reverse($doc->fresh(), 'Wrong receipt');
        $this->assertSame('4.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('unit_cost'));
        $this->assertSame('24.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
    }

    #[Test]
    public function a_reversal_after_the_revalued_stock_moved_is_refused_after_taking_the_stock_locks_and_changes_nothing(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LC-MOVED-WH']);
        $item = F::fifoItem(['sku' => 'LC-MOVED']);
        $receipt = $this->receive($warehouse, $item, '10', '4', 'LC-MOVED-GRN');
        $doc = $this->draft([$receipt->lines->sole()->id], [['charge_type' => 'duty', 'amount' => '10']]);
        app(LandedCostService::class)->post($doc);
        $this->assertSame('5.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('unit_cost'));

        // A write-off committed after the post (and just before the reversal's lock).
        $this->out($warehouse, $item, '1', StockAdjustment::class, 91012);
        $message = null;
        $order = $this->lockOrder(function () use ($doc, &$message) {
            $message = $this->message(fn () => app(LandedCostService::class)->reverse($doc->fresh(), 'Too late'));
        });

        $this->assertSame(__('inventory.landed_cost.reverse_moved'), $message);
        $this->assertLocksPrecedeFirstPlainRead($order);
        $this->assertSame('posted', $doc->fresh()->status);
        $this->assertNull($doc->fresh()->reversal_id);
        $this->assertSame(0, InventoryReversal::query()->where('source_type', 'landed_cost')->where('source_id', $doc->id)->count());
        $layer = CostLayer::query()->where('item_id', $item->id)->sole();
        $this->assertSame('5.0000', (string) $layer->unit_cost);
        $this->assertSame('9.0000', (string) $layer->remaining_qty);
        $this->assertSame('45.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
    }

    #[Test]
    public function routes_require_the_inventory_valuation_permission_and_the_owner_connection_permission(): void
    {
        foreach (['store', 'update', 'post', 'reverse', 'receipt-lines'] as $name) {
            $this->assertContains('perm:inventory.manage_adjustments', Route::getRoutes()->getByName('api.v1.landed-costs.'.$name)->gatherMiddleware());
        }
        foreach (['index', 'show', 'connection'] as $name) {
            $this->assertContains('perm:inventory.view_stock', Route::getRoutes()->getByName('api.v1.landed-costs.'.$name)->gatherMiddleware());
        }
        $enable = Route::getRoutes()->getByName('api.v1.landed-costs.connection.enable')->gatherMiddleware();
        $this->assertContains('perm:inventory.integration.connection_manage', $enable);
        // The clearing binding is an accountant decision (segregation of duties).
        $this->assertContains('perm:inventory.integration.accounting_review', $enable);
    }

    #[Test]
    public function english_and_arabic_strings_are_in_parity(): void
    {
        $en = require lang_path('en/inventory.php');
        $ar = require lang_path('ar/inventory.php');
        $this->assertSame(array_keys($en['landed_cost']), array_keys($ar['landed_cost']));
        foreach ($en['landed_cost'] as $key => $text) {
            $this->assertNotSame($text, $ar['landed_cost'][$key], $key);
        }
        $js = file_get_contents(resource_path('js/solastock/i18n/landedCosts.js'));
        [$enJs, $arJs] = explode('export const ar', $js, 2);
        preg_match_all('/^    "([^"]+)":/m', $enJs, $enKeys);
        preg_match_all('/^    "([^"]+)":/m', $arJs, $arKeys);
        $this->assertNotEmpty($enKeys[1]);
        $this->assertSame($enKeys[1], $arKeys[1]);
    }

    #[Test]
    public function landed_cost_events_and_clearing_role_are_registered_in_the_shared_contract(): void
    {
        $this->assertSame('LandedCost', \App\Services\Integration\IntegrationEvents::aggregateType('landed_cost.posted'));
        $this->assertSame('InventoryReversal', \App\Services\Integration\IntegrationEvents::aggregateType('landed_cost.reversed'));
        $this->assertTrue(\App\Services\Integration\IntegrationEvents::postsJournalForPayload('landed_cost.posted',
            ['total_inventory_value_change' => '0.00', 'landed_cost' => ['total_base_amount' => '8.00']]));
        $this->assertFalse(\App\Services\Integration\IntegrationEvents::postsJournalForPayload('landed_cost.posted',
            ['total_inventory_value_change' => '0.00', 'landed_cost' => ['total_base_amount' => '0.00']]));
        $this->assertSame(['asset', 'liability'], \App\Services\Integration\AccountRolePolicy::ROLE_TYPES['landed_cost_clearing']);
        $this->assertSame(['adjustment_loss', 'cogs', 'inventory_asset', 'landed_cost_clearing'],
            \App\Services\Integration\AccountRolePolicy::forOperations(['landed_cost.posted', 'landed_cost.reversed']));
        $this->assertNull(\App\Services\Integration\AccountRolePolicy::workflowTemplate('landed_cost.posted'));
        $this->assertNotContains('landed_cost.posted', config('integration_connection_wizard.allowed_workflows'),
            'New connections keep their reviewed default scope; landed costs are enabled explicitly.');
    }
}
