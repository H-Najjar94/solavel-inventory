<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\StockBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Current stock — reads ONLY from the stock_balances projection (never recomputed
 * from item fields). Read-only.
 */
class StockBalanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = StockBalance::query()
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('lot_id'), fn ($q) => $q->where('lot_id', (int) $request->query('lot_id')))
            ->when($request->filled('bin_id'), fn ($q) => $q->where('bin_id', (int) $request->query('bin_id')))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('on_hand_qty', '<=', 'reserved_qty'))
            ->orderByDesc('total_value');

        $paginator = $query->paginate($perPage)->withQueryString();

        // available_qty is a generated column; expose it explicitly.
        $paginator->getCollection()->transform(function (StockBalance $b) {
            return [
                'id' => $b->id,
                'item_id' => $b->item_id,
                'variant_id' => $b->variant_id,
                'warehouse_id' => $b->warehouse_id,
                'lot_id' => $b->lot_id,
                'bin_id' => $b->bin_id,
                'on_hand_qty' => $b->on_hand_qty,
                'reserved_qty' => $b->reserved_qty,
                'available_qty' => $b->available_qty,
                'average_cost' => $b->average_cost,
                'total_value' => $b->total_value,
                'last_movement_at' => $b->last_movement_at,
            ];
        });

        return $this->paginated($paginator);
    }
}
