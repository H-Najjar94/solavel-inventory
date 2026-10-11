<?php
namespace App\Services\Presentation;

use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockLedger;

/** Read-only presentation of actual posted costs, preserving historical headers/lines. */
final class AdjustmentValuation
{
    public function apply(iterable $documents): void
    {
        $documents = collect($documents);
        $ids = $documents->filter(fn ($row) => in_array($row->status, ['posted', 'reversed'], true))->pluck('id');
        if ($ids->isEmpty()) return;
        $totals = StockLedger::query()->where('source_type', StockAdjustment::class)->whereIn('source_id', $ids)
            ->selectRaw('source_id, SUM(CASE WHEN direction = "in" THEN total_cost ELSE 0 END) increase_value, SUM(CASE WHEN direction = "out" THEN total_cost ELSE 0 END) decrease_value')
            ->groupBy('source_id')->get()->keyBy('source_id');
        foreach ($documents as $document) {
            if (!in_array($document->status, ['posted', 'reversed'], true) || !isset($totals[$document->id])) continue;
            $document->total_increase_value = $totals[$document->id]->increase_value;
            $document->total_decrease_value = $totals[$document->id]->decrease_value;
        }
    }
}
