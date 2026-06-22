<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the reporting layer: registry membership, unknown-report
 * rejection, export filename sanitization, and route registration. None of these
 * touch the database.
 */
class ReportRegistryTest extends TestCase
{
    #[Test]
    public function registry_contains_core_reports(): void
    {
        // 15 stock/purchasing reports + 4 sales-fulfillment + 4 traceability.
        $this->assertCount(23, InventoryReportService::REPORTS);
        foreach (['inventory-valuation', 'stock-movement', 'item-ledger', 'warehouse-stock',
            'low-stock', 'out-of-stock', 'count-variance', 'transfer'] as $k) {
            $this->assertTrue(InventoryReportService::exists($k), "missing report {$k}");
        }
    }

    #[Test]
    public function registry_contains_traceability_reports(): void
    {
        foreach (['lot-trace', 'serial-lifecycle', 'expiry-risk', 'recall-impact'] as $k) {
            $this->assertTrue(InventoryReportService::exists($k), "missing report {$k}");
        }
    }

    #[Test]
    public function unknown_report_is_rejected(): void
    {
        $this->assertFalse(InventoryReportService::exists('not-a-report'));
        $this->expectException(\InvalidArgumentException::class);
        app(InventoryReportService::class)->run('not-a-report', new ReportFilters);
    }

    #[Test]
    public function export_filename_is_sanitized(): void
    {
        $name = ReportExportService::safeFilename('../../etc/passwd', 'csv');
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringStartsWith('solastock-', $name);
        $this->assertStringEndsWith('.csv', $name);

        $clean = ReportExportService::safeFilename('inventory-valuation', 'csv');
        $this->assertStringContainsString('inventory-valuation', $clean);
    }

    #[Test]
    public function report_and_dashboard_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach (['api.v1.reports.index', 'api.v1.reports.show', 'api.v1.reports.export', 'api.v1.dashboard'] as $n) {
            $this->assertContains($n, $names, "missing route {$n}");
        }
    }

    #[Test]
    public function report_export_route_requires_export_permission(): void
    {
        $mw = Route::getRoutes()->getByName('api.v1.reports.export')->gatherMiddleware();
        $this->assertContains('perm:inventory.export_reports', $mw);
    }
}
