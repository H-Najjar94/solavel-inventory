<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryScheduledReport;
use App\Services\Access\WarehouseAccessService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ScheduledReportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private ScheduledReportRunner $scheduledReports,
        private WarehouseAccessService $warehouseAccess,
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
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return $this->error('unknown_format', "Unknown export format: {$format}", 422);
        }

        $filters = ReportFilters::fromRequest($request);
        if ($filters->warehouseId !== null) {
            $this->warehouseAccess->assertAllowed($filters->warehouseId);
        }
        $result = $this->reports->run($report, $filters);

        return match ($format) {
            'xlsx' => $this->export->xlsx($result),
            'pdf' => $this->export->pdf($result),
            default => $this->export->csv($result),
        };
    }

    public function schedules(): JsonResponse
    {
        return $this->success([
            'schedules' => InventoryScheduledReport::query()->orderByDesc('created_at')->get(),
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $schedule = InventoryScheduledReport::query()->create($this->validatedSchedule($request));

        return $this->success($schedule->fresh(), 201);
    }

    public function updateSchedule(Request $request, InventoryScheduledReport $schedule): JsonResponse
    {
        $schedule->fill($this->validatedSchedule($request))->save();

        return $this->success($schedule->fresh());
    }

    public function runSchedule(InventoryScheduledReport $schedule): JsonResponse
    {
        return $this->success($this->scheduledReports->run($schedule));
    }

    private function validatedSchedule(Request $request): array
    {
        $data = $request->validate([
            'report_key' => ['required', 'string', Rule::in(array_keys(InventoryReportService::REPORTS))],
            'name' => ['required', 'string', 'max:191'],
            'filters' => ['nullable', 'array'],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['required', 'email'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'next_run_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        $data['next_run_at'] = $data['next_run_at'] ?? $this->scheduledReports->nextRunAt($data['frequency']);
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
