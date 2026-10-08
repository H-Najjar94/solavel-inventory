<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\Tenant\Item;
use Illuminate\Validation\Validator;

/**
 * Stock documents may only carry inventory items. The ledger already refuses
 * to post movements for service/non-inventory items (SC-UAE-042); checking at
 * save time stops an unpostable draft from being created in the first place.
 */
trait RejectsNonStockItems
{
    protected function rejectNonStockItems(Validator $validator): void
    {
        $lines = (array) $this->input('lines', []);
        $ids = collect($lines)->pluck('item_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return;
        }
        $nonStock = Item::query()->whereIn('id', $ids)->where('item_type', '!=', 'inventory')->pluck('sku', 'id');
        foreach ($lines as $index => $line) {
            $id = is_numeric($line['item_id'] ?? null) ? (int) $line['item_id'] : null;
            if ($id !== null && $nonStock->has($id)) {
                $validator->errors()->add("lines.{$index}.item_id", __('inventory.stock.non_stock_item', ['sku' => $nonStock[$id]]));
            }
        }
    }
}
