<?php

namespace App\Services\Reports;

use App\Models\Tenant\InventoryScheduledReport;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Illuminate\Support\Facades\Mail;

class ScheduledReportRunner
{
    public function __construct(private InventoryReportService $reports) {}

    /**
     * Does the CURRENT org's plan include stock.reports? A scheduled report is the
     * same paid capability as the interactive report route (both gate stock.reports),
     * so the background runner must re-check it — §5: a background job must enforce
     * the feature. Dark-launch aware; unchanged when enforcement is off.
     */
    private function reportsEnabled(): bool
    {
        if (! (bool) config('inventory_entitlements.feature_enforcement', false)) {
            return true;
        }

        return app(InventoryCommercialEntitlementService::class)->checkFeature('stock.reports')['allowed'];
    }

    /** @return array{schedule:InventoryScheduledReport,result:array} */
    public function run(InventoryScheduledReport $schedule): array
    {
        // A de-entitled org keeps its schedule rows but the runner stops generating
        // and emailing — parity with losing the interactive report route. The
        // schedule is pushed to its next slot and marked skipped; no data is deleted.
        if (! $this->reportsEnabled()) {
            $schedule->forceFill([
                'last_run_at' => now(),
                'last_status' => 'skipped_not_in_plan',
                'next_run_at' => $this->nextRunAt($schedule->frequency),
            ])->save();

            return ['schedule' => $schedule->fresh(), 'result' => ['title' => '', 'rows' => [], 'summary' => []]];
        }

        $result = $this->reports->run($schedule->report_key, ReportFilters::fromArray((array) $schedule->filters));
        $recipients = array_values(array_filter((array) $schedule->recipients));
        $status = 'generated';
        $error = null;

        if ($recipients !== []) {
            try {
                $subject = 'SolaStock scheduled report: '.$result['title'];
                $body = $result['title']."\nRows: ".count($result['rows'] ?? [])."\nSummary: ".json_encode($result['summary'] ?? []);
                foreach ($recipients as $recipient) {
                    Mail::raw($body, fn ($message) => $message->to($recipient)->subject($subject));
                }
                $status = 'delivered';
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
            }
        }

        $schedule->forceFill([
            'last_run_at' => now(),
            'last_delivered_at' => $status === 'delivered' ? now() : null,
            'last_status' => $status,
            'last_error' => $error,
            'last_payload' => [
                'title' => $result['title'],
                'summary' => $result['summary'] ?? [],
                'row_count' => count($result['rows'] ?? []),
            ],
            'next_run_at' => $this->nextRunAt($schedule->frequency),
        ])->save();

        return ['schedule' => $schedule->fresh(), 'result' => $result];
    }

    /** @return array{checked:int,ran:int,failed:int,results:array<int,array{id:int,status:string,error:?string}>} */
    public function runDue(int $limit = 25): array
    {
        $schedules = InventoryScheduledReport::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $results = [];
        $failed = 0;

        foreach ($schedules as $schedule) {
            $this->run($schedule);
            $fresh = $schedule->fresh();
            $status = (string) $fresh->last_status;
            $failed += $status === 'failed' ? 1 : 0;
            $results[] = [
                'id' => (int) $fresh->id,
                'status' => $status,
                'error' => $fresh->last_error,
            ];
        }

        return [
            'checked' => $schedules->count(),
            'ran' => count($results),
            'failed' => $failed,
            'results' => $results,
        ];
    }

    public function nextRunAt(string $frequency): \Carbon\CarbonInterface
    {
        return match ($frequency) {
            'daily' => now()->addDay(),
            'monthly' => now()->addMonth(),
            default => now()->addWeek(),
        };
    }
}
