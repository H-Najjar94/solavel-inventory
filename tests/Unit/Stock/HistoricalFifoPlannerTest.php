<?php

namespace Tests\Unit\Stock;

use App\Services\Stock\Historical\HistoricalFifoPlanner;
use PHPUnit\Framework\TestCase;

final class HistoricalFifoPlannerTest extends TestCase
{
    private function event(string $id, string $date, string $kind, string $quantity, array $extra = []): array
    {
        return array_merge(['source_id' => $id, 'date' => $date, 'sequence' => 1, 'stock_item_id' => 1,
            'warehouse_id' => 1, 'kind' => $kind, 'quantity' => $quantity], $extra);
    }

    private function opening(array $layers = []): array
    {
        return ['1:1' => ['business_date' => '2024-01-01', 'evidence_reference' => 'reviewed-inventory-count',
            'hash' => str_repeat('a', 64), 'reviewed_by' => 337, 'layers' => $layers]];
    }

    public function test_missing_opening_is_not_assumed_zero_even_with_later_receipts(): void
    {
        $plan = (new HistoricalFifoPlanner)->plan([
            $this->event('purchase', '2024-02-01', 'receipt', '10', ['acquisition_unit_cost' => '3']),
            $this->event('sale', '2024-02-02', 'out', '1'),
        ], []);
        $this->assertSame('opening_evidence_missing', $plan['blocked_items']['1:1']);
        $this->assertSame(['blocked', 'blocked'], array_column($plan['events'], 'status'));
    }

    public function test_paid_bonus_and_free_quantities_consume_proven_cost_and_reprice_later_dependents(): void
    {
        $events = [
            $this->event('old-receipt', '2024-01-02', 'receipt', '5', ['acquisition_unit_cost' => '2']),
            $this->event('new-receipt', '2024-01-04', 'receipt', '5', ['acquisition_unit_cost' => '4']),
            $this->event('paid', '2024-01-03', 'out', '3', ['previous_posted_cost' => '6']),
            $this->event('bonus', '2024-01-03', 'out', '1', ['sequence' => 2]),
            $this->event('free', '2024-01-05', 'out', '3', ['previous_posted_cost' => '6']),
        ];
        $planner = new HistoricalFifoPlanner;
        $plan = $planner->plan($events, $this->opening());
        $this->assertSame([], $plan['blocked_items']);
        $byId = array_column($plan['events'], null, 'source_id');
        $this->assertSame('2.00', $byId['bonus']['reconstructed_cost']);
        $this->assertSame('10.00', $byId['free']['reconstructed_cost']);
        $this->assertSame('4.00', $byId['free']['cogs_delta']);
        $this->assertSame('3.0000', $plan['layers']['1:1'][1]['remaining']);
        $this->assertSame($plan, $planner->plan(array_reverse($events), $this->opening()));
    }

    public function test_return_requires_evidenced_original_cost_and_disposition_and_preserves_it(): void
    {
        $events = [
            $this->event('sale', '2024-01-02', 'out', '2'),
            $this->event('return', '2024-01-03', 'return', '1', ['original_sale_source_id' => 'sale', 'return_disposition_evidence' => 'resalable-inspection']),
            $this->event('resale', '2024-01-04', 'out', '1'),
        ];
        $plan = (new HistoricalFifoPlanner)->plan($events, $this->opening([['quantity' => '2', 'unit_cost' => '3']]));
        $this->assertSame('3.00', $plan['events'][1]['reconstructed_cost']);
        $this->assertSame('-3.00', $plan['events'][1]['cogs_delta']);
        $this->assertSame('3.00', $plan['events'][2]['reconstructed_cost']);
        unset($events[1]['return_disposition_evidence']);
        $blocked = (new HistoricalFifoPlanner)->plan($events, $this->opening([['quantity' => '2', 'unit_cost' => '3']]));
        $this->assertSame('return_origin_or_disposition_unproven', $blocked['blocked_items']['1:1']);
        $this->assertSame('blocked', $blocked['events'][2]['status']);
    }

    public function test_later_purchase_cannot_fund_earlier_outbound(): void
    {
        $plan = (new HistoricalFifoPlanner)->plan([
            $this->event('sale', '2024-01-02', 'out', '1'),
            $this->event('purchase', '2024-01-03', 'receipt', '10', ['acquisition_unit_cost' => '5']),
        ], $this->opening());
        $this->assertSame('historical_origin_quantity_insufficient', $plan['blocked_items']['1:1']);
        $this->assertSame('blocked', $plan['events'][1]['status']);
    }

    public function test_duplicate_source_identity_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new HistoricalFifoPlanner)->plan([$this->event('duplicate', '2024-01-02', 'out', '1'), $this->event('duplicate', '2024-01-03', 'out', '1')], $this->opening());
    }
}
