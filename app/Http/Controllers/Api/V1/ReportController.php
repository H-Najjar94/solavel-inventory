<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin reports controller. All query logic lives in InventoryReportService; this
 * just validates the report key, builds filters, and returns JSON or an export.
 * No report mutates stock.
 */
class ReportController extends ApiController
{
    public function __construct(
        private InventoryReportService $reports,
        private ReportExportService $export,
    ) {}

    /** List available reports (for the selector cards). */
    public function index(): JsonResponse
    {
        return $this->success(['reports' => collect(InventoryReportService::REPORTS)
            ->map(fn ($title, $key) => ['key' => $key, 'title' => $title])->values()]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        if (! InventoryReportService::exists($report)) {
            return $this->error('unknown_report', "Unknown report: {$report}", 404);
        }

        return $this->success($this->reports->run($report, ReportFilters::fromRequest($request)));
    }

    public function exportReport(Request $request, string $report): Response
    {
        if (! InventoryReportService::exists($report)) {
            return $this->error('unknown_report', "Unknown report: {$report}", 404);
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (in_array($format, ['xlsx', 'pdf'], true)) {
            return $this->error('export_format_coming_soon', strtoupper($format).' export is coming soon. Use CSV for now.', 501);
        }
        if ($format !== 'csv') {
            return $this->error('unknown_format', "Unknown export format: {$format}", 422);
        }

        $result = $this->reports->run($report, ReportFilters::fromRequest($request));

        return $this->export->csv($result);
    }
}
