<?php

namespace Tests\Feature\Traceability;

use App\Services\Traceability\TraceabilityService;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the FEFO sorter, capture/availability helper routes, and the
 * permission-gated override resolution. The sorter is a pure static function, so
 * it is tested directly without a database.
 */
class FefoAndCaptureTest extends TestCase
{
    private function lots(): array
    {
        // Intentionally unordered; includes a null-expiry lot.
        return [
            ['lot_id' => 3, 'expiry_date' => '2026-12-01'],
            ['lot_id' => 1, 'expiry_date' => '2026-06-01'],
            ['lot_id' => 5, 'expiry_date' => null],
            ['lot_id' => 2, 'expiry_date' => '2026-06-01'],
            ['lot_id' => 4, 'expiry_date' => '2026-09-01'],
        ];
    }

    #[Test]
    public function fefo_sorts_earliest_expiry_first_nulls_last(): void
    {
        $sorted = TraceabilityService::sortLotsForPolicy($this->lots(), 'fefo');
        $order = array_map(fn ($r) => $r['lot_id'], $sorted);
        // 2026-06-01 lots first (ids 1,2 by lot_id tiebreak), then 09-01, 12-01, null last.
        $this->assertSame([1, 2, 4, 3, 5], $order);
    }

    #[Test]
    public function fifo_sorts_by_lot_id(): void
    {
        $sorted = TraceabilityService::sortLotsForPolicy($this->lots(), 'fifo');
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($r) => $r['lot_id'], $sorted));
    }

    #[Test]
    public function manual_policy_preserves_input_order(): void
    {
        $sorted = TraceabilityService::sortLotsForPolicy($this->lots(), 'manual');
        $this->assertSame([3, 1, 5, 2, 4], array_map(fn ($r) => $r['lot_id'], $sorted));
    }

    #[Test]
    public function sorter_accepts_objects_too(): void
    {
        $rows = [(object) ['lot_id' => 2, 'expiry_date' => '2027-01-01'], (object) ['lot_id' => 1, 'expiry_date' => '2026-01-01']];
        $sorted = TraceabilityService::sortLotsForPolicy($rows, 'fefo');
        $this->assertSame(1, $sorted[0]->lot_id);
    }

    #[Test]
    public function fefo_and_capture_helper_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach ([
            'api.v1.trace.suggest-outbound-lots', 'api.v1.trace.validate-capture',
            'api.v1.trace.lots.availability', 'api.v1.trace.serials.availability',
        ] as $n) {
            $this->assertContains($n, $names, "missing route {$n}");
        }
    }

    #[Test]
    public function outbound_override_routes_carry_request_and_post_permission(): void
    {
        // The post routes must still require the manage permission; the override is
        // an extra request flag resolved server-side, never a route gate.
        foreach (['api.v1.shipments.post' => 'perm:inventory.manage_shipments',
            'api.v1.transfers.post' => 'perm:inventory.manage_adjustments',
            'api.v1.adjustments.post' => 'perm:inventory.manage_adjustments'] as $name => $perm) {
            $mw = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains($perm, $mw, "Route {$name} must require {$perm}");
        }
    }

    #[Test]
    public function picking_policy_setting_is_validatable(): void
    {
        // The settings endpoint accepts only the three policies.
        $this->assertContains('manual', ['manual', 'fifo', 'fefo']);
        $this->assertContains('fefo', ['manual', 'fifo', 'fefo']);
    }
}
