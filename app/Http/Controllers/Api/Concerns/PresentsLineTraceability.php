<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Tenant\Lot;
use App\Models\Tenant\SerialNumber;

/**
 * Draft lines store lot_id / serial_id only. Attach the lot code, lot expiry and
 * serial so edit forms can show them and resubmit the ids unchanged.
 */
trait PresentsLineTraceability
{
    /** @param iterable<\Illuminate\Database\Eloquent\Model> $lines */
    protected function attachLineTraceability(iterable $lines): void
    {
        $lines = collect($lines);
        $lotIds = $lines->pluck('lot_id')->filter()->unique();
        $serialIds = $lines->pluck('serial_id')->filter()->unique();
        $lots = $lotIds->isEmpty() ? collect() : Lot::query()->whereIn('id', $lotIds)->get(['id', 'lot_code', 'expiry_date'])->keyBy('id');
        $serials = $serialIds->isEmpty() ? collect() : SerialNumber::query()->whereIn('id', $serialIds)->pluck('serial', 'id');
        foreach ($lines as $line) {
            $lot = $line->lot_id ? ($lots[$line->lot_id] ?? null) : null;
            $line->setAttribute('lot_code', $lot?->lot_code);
            $line->setAttribute('lot_expiry_date', $lot?->expiry_date?->toDateString());
            $line->setAttribute('serial', $line->serial_id ? ($serials[$line->serial_id] ?? null) : null);
        }
    }
}
