<?php

namespace Tests\Feature\Traceability;

use App\Models\Tenant\Lot;
use App\Models\Tenant\Recall;
use App\Models\Tenant\SerialNumber;
use App\Services\Reports\InventoryReportService;
use App\Services\Traceability\SerialService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the traceability + recall foundation: serial count/duplicate
 * validation (pure), lot expiry/quarantine policy rules (pure model logic),
 * status enums, route registration, and report registry membership. None of
 * these touch the database.
 */
class TraceabilityFoundationTest extends TestCase
{
    private function serialService(): SerialService
    {
        // validateList is pure and never touches the context/DB.
        return new SerialService(new OrganizationContext);
    }

    #[Test]
    public function serial_count_must_match_quantity(): void
    {
        $r = $this->serialService()->validateList(['A1', 'A2'], 3);
        $this->assertNotEmpty($r['errors']);
        $this->assertStringContainsString('must equal quantity', implode(' ', $r['errors']));

        $ok = $this->serialService()->validateList(['A1', 'A2', 'A3'], 3);
        $this->assertSame([], $ok['errors']);
        $this->assertCount(3, $ok['serials']);
    }

    #[Test]
    public function duplicate_serials_are_flagged_and_removed(): void
    {
        $r = $this->serialService()->validateList(['SN1', 'sn1', ' SN2 ', 'SN2'], 2);
        // case-insensitive + trimmed de-dup leaves SN1, SN2.
        $this->assertCount(2, $r['serials']);
        $this->assertContains('SN1', $r['serials']);
        $this->assertContains('SN2', $r['serials']);
        $this->assertNotEmpty(array_filter($r['errors'], fn ($e) => str_contains($e, 'Duplicate')));
    }

    #[Test]
    public function blank_serials_are_ignored(): void
    {
        $r = $this->serialService()->validateList(['', '  ', 'X1'], 1);
        $this->assertSame(['X1'], $r['serials']);
        $this->assertSame([], $r['errors']);
    }

    #[Test]
    public function expired_lot_is_not_shippable(): void
    {
        $lot = (new Lot)->forceFill(['lot_code' => 'L1', 'status' => 'active', 'expiry_date' => now()->subDay()->toDateString()]);
        $this->assertTrue($lot->isExpired());
        $this->assertSame('expired', $lot->effectiveStatus());
        $this->assertFalse($lot->isShippable());
    }

    #[Test]
    public function quarantined_and_recalled_lots_are_not_shippable(): void
    {
        $q = (new Lot)->forceFill(['lot_code' => 'L2', 'status' => 'quarantined']);
        $this->assertFalse($q->isShippable());
        $this->assertSame('quarantined', $q->effectiveStatus());

        $r = (new Lot)->forceFill(['lot_code' => 'L3', 'status' => 'recalled']);
        $this->assertFalse($r->isShippable());
        $this->assertSame('recalled', $r->effectiveStatus());
    }

    #[Test]
    public function active_unexpired_lot_is_shippable(): void
    {
        $lot = (new Lot)->forceFill(['lot_code' => 'L4', 'status' => 'active', 'expiry_date' => now()->addYear()->toDateString()]);
        $this->assertTrue($lot->isShippable());
        $this->assertSame('active', $lot->effectiveStatus());
    }

    #[Test]
    public function serial_legacy_status_maps_to_canonical(): void
    {
        $s = (new SerialNumber)->forceFill(['serial' => 'X', 'status' => 'in_stock']);
        $this->assertSame('available', $s->lifecycleStatus());
        $this->assertTrue($s->isAvailable());

        $sold = (new SerialNumber)->forceFill(['serial' => 'Y', 'status' => 'sold']);
        $this->assertSame('shipped', $sold->lifecycleStatus());
    }

    #[Test]
    public function status_enums_are_defined(): void
    {
        $this->assertSame(['active', 'expired', 'quarantined', 'consumed', 'recalled'], Lot::STATUSES);
        $this->assertSame(['draft', 'active', 'closed'], Recall::STATUSES);
        $this->assertContains('available', SerialNumber::STATUSES);
        $this->assertContains('retired', SerialNumber::STATUSES);
    }

    #[Test]
    public function traceability_and_recall_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach ([
            'api.v1.lots.index', 'api.v1.lots.show', 'api.v1.lots.movements', 'api.v1.lots.availability', 'api.v1.lots.status',
            'api.v1.serials.index', 'api.v1.serials.show', 'api.v1.serials.lifecycle', 'api.v1.serials.availability', 'api.v1.serials.status',
            'api.v1.trace.validate-serials', 'api.v1.trace.validate-lot', 'api.v1.trace.expiry-risk',
            'api.v1.recalls.index', 'api.v1.recalls.store', 'api.v1.recalls.activate', 'api.v1.recalls.close', 'api.v1.recalls.impact',
        ] as $n) {
            $this->assertContains($n, $names, "missing route {$n}");
        }
    }

    #[Test]
    public function traceability_write_routes_require_manage_permissions(): void
    {
        $expected = [
            'api.v1.lots.status' => 'perm:inventory.manage_lots',
            'api.v1.serials.status' => 'perm:inventory.manage_serials',
            'api.v1.recalls.store' => 'perm:inventory.manage_recalls',
            'api.v1.recalls.activate' => 'perm:inventory.manage_recalls',
        ];
        foreach ($expected as $name => $perm) {
            $mw = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains($perm, $mw, "Route {$name} must be gated by {$perm}");
        }
    }

    #[Test]
    public function traceability_permissions_are_registered(): void
    {
        $perms = config('inventory_permissions.permissions');
        foreach ([
            'inventory.view_traceability', 'inventory.manage_lots', 'inventory.manage_serials',
            'inventory.manage_recalls', 'inventory.override_quarantine', 'inventory.override_expired_lot',
        ] as $p) {
            $this->assertArrayHasKey($p, $perms, "missing permission {$p}");
        }
        $viewer = config('inventory_permissions.roles.inventory_viewer');
        $this->assertContains('inventory.view_traceability', $viewer);
        $this->assertNotContains('inventory.manage_recalls', $viewer);
    }

    #[Test]
    public function traceability_reports_are_in_the_registry(): void
    {
        foreach (['lot-trace', 'serial-lifecycle', 'expiry-risk', 'recall-impact'] as $k) {
            $this->assertTrue(InventoryReportService::exists($k), "missing report {$k}");
        }
    }
}
