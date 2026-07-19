<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryUserWarehouse;
use App\Models\Tenant\StockBalance;
use App\Services\Access\InventoryPermissionService;
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
            // Resolve item + warehouse NAMES (org-scoped by their global scope) so the
            // grid shows names, not raw #ids. Eager-loaded => no N+1 across the page.
            ->with(['item:id,name,sku', 'warehouse:id,name,code', 'bin:id,code,coords', 'lot:id,status,expiry_date', 'serial:id,status'])
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('lot_id'), fn ($q) => $q->where('lot_id', (int) $request->query('lot_id')))
            ->when($request->filled('bin_id'), fn ($q) => $q->where('bin_id', (int) $request->query('bin_id')))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('on_hand_qty', '<=', 'reserved_qty'))
            ->orderByDesc('total_value');
        if ($request->user() && ! app(InventoryPermissionService::class)->can($request->user(), 'inventory.manage_settings')) {
            // Apply the assignment at the projection query itself. A submitted
            // warehouse filter must never widen this result set.
            $allowed = InventoryUserWarehouse::query()
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->pluck('warehouse_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $query->whereIn('stock_balances.warehouse_id', $allowed);
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        // available_qty is the canonical projection. sellable_available_qty excludes
        // quarantined/damaged coordinates without mutating the stock balance.
        $paginator->getCollection()->transform(function (StockBalance $b) {
            $binType = $b->bin?->coords['bin_type'] ?? null;
            $traceStatus = $b->lot?->effectiveStatus() ?? $b->serial?->lifecycleStatus();
            $isQuarantined = in_array($binType, ['quarantine', 'damaged'], true)
                || in_array($traceStatus, ['quarantined', 'recalled', 'damaged', 'retired'], true);
            $available = (float) $b->on_hand_qty - (float) $b->reserved_qty;

            return [
                'id' => $b->id,
                'item_id' => $b->item_id,
                'item_name' => $b->item?->name,
                'item_sku' => $b->item?->sku,
                'variant_id' => $b->variant_id,
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $b->warehouse?->name,
                'warehouse_code' => $b->warehouse?->code,
                'lot_id' => $b->lot_id,
                'bin_id' => $b->bin_id,
                'bin_code' => $b->bin?->code,
                'bin_type' => $binType,
                'on_hand_qty' => $b->on_hand_qty,
                'reserved_qty' => $b->reserved_qty,
                'available_qty' => $b->available_qty,
                'quarantine_qty' => $isQuarantined ? $b->on_hand_qty : '0.0000',
                'sellable_available_qty' => $isQuarantined ? '0.0000' : number_format(max(0, $available), 4, '.', ''),
                'availability_status' => $isQuarantined ? 'quarantined' : 'available',
                'average_cost' => $b->average_cost,
                'total_value' => $b->total_value,
                'last_movement_at' => $b->last_movement_at,
            ];
        });

        return $this->paginated($paginator);
    }
}
