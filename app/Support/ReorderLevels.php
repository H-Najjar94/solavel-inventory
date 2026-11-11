<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** NF-1203-04: a reorder rule must be internally consistent once merged with its stored values. */
final class ReorderLevels
{
    public static function assertConsistent(array $levels): void
    {
        $max = $levels['max_stock'] ?? null;
        if ($max === null || $max === '') {
            return;
        }
        $min = $levels['min_stock'] ?? null;
        if ($min !== null && $min !== '' && (float) $min > (float) $max) {
            throw ValidationException::withMessages(['max_stock' => __('inventory.validation.max_stock_min')]);
        }
        $point = $levels['reorder_point'] ?? null;
        if ($point !== null && $point !== '' && (float) $point > (float) $max) {
            throw ValidationException::withMessages(['reorder_point' => __('inventory.validation.reorder_exceeds_max')]);
        }
    }
}
