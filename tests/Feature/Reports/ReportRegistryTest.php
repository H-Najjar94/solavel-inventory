<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
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
    public function xls_and_printable_pdf_exports_are_available(): void
    {
        $report = [
            'key' => 'inventory-valuation',
            'title' => 'Inventory Valuation',
            'columns' => ['sku', 'name', 'total_value'],
            'summary' => ['total_value' => '10.00'],
            'rows' => [
                ['sku' => 'A-1', 'name' => 'Item A', 'total_value' => '10.00'],
            ],
        ];

        $export = app(ReportExportService::class);
        $xls = $export->xlsx($report);
        $pdf = $export->pdf($report);

        ob_start();
        $xls->sendContent();
        $xlsBody = ob_get_clean();
        ob_start();
        $pdf->sendContent();
        $pdfBody = ob_get_clean();

        $this->assertStringContainsString('application/vnd.ms-excel', $xls->headers->get('Content-Type'));
        $this->assertStringContainsString('<Workbook', $xlsBody);
        $this->assertStringContainsString('text/html', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('Inventory Valuation', $pdfBody);
        $this->assertStringContainsString('window.print', $pdfBody);
    }

    #[Test]
    public function arabic_exports_use_localized_headings_rtl_and_preserve_identifiers(): void
    {
        app()->setLocale('ar');
        $report = [
            'key' => 'inventory-valuation',
            'title' => InventoryReportService::title('inventory-valuation'),
            'columns' => ['sku', 'item', 'total_value'],
            'column_labels' => [
                'sku' => InventoryReportService::fieldLabel('sku'),
                'item' => InventoryReportService::fieldLabel('item'),
                'total_value' => InventoryReportService::fieldLabel('total_value'),
            ],
            'summary' => ['total_value' => '10.00'],
            'summary_labels' => ['total_value' => InventoryReportService::fieldLabel('total_value')],
            'rows' => [['sku' => 'AR-SKU-001', 'item' => 'صنف تجريبي', 'total_value' => '10.00']],
        ];

        $export = app(ReportExportService::class);
        $csv = $export->csv($report);
        $pdf = $export->pdf($report);

        ob_start();
        $csv->sendContent();
        $csvBody = ob_get_clean();
        ob_start();
        $pdf->sendContent();
        $pdfBody = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvBody);
        $this->assertStringContainsString('القيمة الإجمالية', $csvBody);
        $this->assertStringContainsString('AR-SKU-001', $csvBody);
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $pdfBody);
        $this->assertStringContainsString('Noto Sans Arabic', $pdfBody);
        $this->assertStringContainsString('القيمة الإجمالية', $pdfBody);
        $this->assertStringContainsString('AR-SKU-001', $pdfBody);
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

    #[Test]
    public function report_filters_reject_array_and_invalid_warehouse_identifiers(): void
    {
        foreach ([['warehouse_id' => [1, 2]], ['warehouse_id' => '1,2'], ['warehouse_id' => 0]] as $query) {
            try {
                ReportFilters::fromRequest(Request::create('/reports', 'GET', $query));
                $this->fail('Invalid warehouse report filter was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function report_columns_do_not_expose_user_facing_raw_ids(): void
    {
        $forbiddenColumns = [
            'warehouse_id',
            'source_type',
            'source_id',
            'supplier_id',
            'preferred_supplier_id',
            'purchase_order_id',
            'adjustment_id',
            'from_warehouse_id',
            'to_warehouse_id',
            'picker_user_id',
            'bin_id',
            'shipment_id',
            'sales_return_id',
        ];

        $source = file_get_contents(app_path('Services/Reports/InventoryReportService.php'));
        preg_match_all("/'columns'\\s*=>\\s*\\[([^\\]]*)\\]/", $source, $matches);

        $this->assertNotEmpty($matches[1], 'report service should declare visible columns');
        foreach ($matches[1] as $columnsSource) {
            foreach ($forbiddenColumns as $column) {
                $this->assertStringNotContainsString("'{$column}'", $columnsSource, "report columns expose {$column}");
                $this->assertStringNotContainsString('"'.$column.'"', $columnsSource, "report columns expose {$column}");
            }
        }
    }
}
