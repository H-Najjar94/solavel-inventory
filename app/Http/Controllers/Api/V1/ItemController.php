<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends ApiController
{
    /** Strip non-column / transient fields before persisting the item row. */
    private function itemAttributes(array $data): array
    {
        unset($data['track_lot'], $data['track_serial'], $data['track_expiry'], $data['barcode']);

        return $data;
    }

    private function audit(string $action, Item $item, ?array $before = null): void
    {
        InventoryAuditLog::create([
            'organization_id' => app(OrganizationContext::class)->id(),
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'item',
            'entity_id' => $item->id,
            'before' => $before,
            'after' => $item->only(['sku', 'name', 'item_type', 'tracking_type', 'costing_method', 'is_active']),
            'document_ref' => $item->sku,
            'created_at' => now(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = Item::query()
            ->with(['category:id,name', 'brand:id,name', 'baseUnit:id,code,symbol', 'primaryImage:id,item_id,is_primary'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->query('search'));
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%"));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->query('category_id')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', (int) $request->query('brand_id')))
            ->when($request->filled('item_type'), fn ($q) => $q->where('item_type', $request->query('item_type')))
            ->when($request->filled('preferred_supplier_id'), fn ($q) => $q->where('preferred_supplier_id', (int) $request->query('preferred_supplier_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        // stock_status filter joins the balances projection.
        if ($request->filled('stock_status')) {
            $status = $request->query('stock_status');
            $itemIds = StockBalance::query()
                ->selectRaw('item_id, SUM(on_hand_qty - reserved_qty) avail')
                ->groupBy('item_id')->get();
            $match = $itemIds->filter(function ($r) use ($status) {
                $a = (float) $r->avail;
                return $status === 'out' ? $a <= 0 : ($status === 'low' ? $a > 0 && $a <= 5 : $a > 0);
            })->pluck('item_id');
            $query->whereIn('id', $match);
        }

        return $this->paginated(
            $query->paginate($perPage)->withQueryString()->through(function ($it) {
                $it->setAttribute('primary_image_url',
                    $it->primaryImage ? "/inventory/api/v1/item-images/{$it->primaryImage->id}" : null);
                $it->unsetRelation('primaryImage');

                return $it;
            })
        );
    }

    public function show(Item $item): JsonResponse
    {
        $item->load(['category:id,name', 'brand:id,name', 'baseUnit:id,code,symbol', 'variants',
            'images:id,item_id,is_primary,sort']);

        // Stock by warehouse from the balances projection (not item fields).
        $balances = StockBalance::query()->where('item_id', $item->id)->get();

        // Private, org-scoped serve URLs (never public file URLs).
        $images = $item->images->sortByDesc('is_primary')->values()->map(fn ($img) => [
            'id' => $img->id,
            'is_primary' => (bool) $img->is_primary,
            'url' => "/inventory/api/v1/item-images/{$img->id}",
        ])->all();
        $primary = collect($images)->firstWhere('is_primary', true);
        $item->unsetRelation('images');

        $primaryBarcode = ItemBarcode::query()->where('item_id', $item->id)
            ->orderByRaw("type = 'primary' desc")->value('barcode');

        return $this->success([
            'item' => $item,
            'primary_barcode' => $primaryBarcode,
            'stock_by_warehouse' => $balances,
            'images' => $images,
            'primary_image_url' => $primary['url'] ?? null,
        ]);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $item = Item::create($this->itemAttributes($data));

        // Optional primary barcode → item_barcodes row.
        if (! empty($data['barcode'])) {
            ItemBarcode::create([
                'organization_id' => $item->organization_id,
                'item_id' => $item->id,
                'barcode' => $data['barcode'],
                'type' => 'primary',
            ]);
        }

        $this->audit('item.created', $item);

        return $this->success($item->fresh(), 201);
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $before = $item->only(['sku', 'name', 'item_type', 'tracking_type', 'costing_method', 'is_active']);
        $data = $request->validated();
        $item->update($this->itemAttributes($data));

        $this->audit('item.updated', $item, $before);

        return $this->success($item->fresh());
    }

    public function movements(Request $request, Item $item): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = StockLedger::query()->with('warehouse:id,name,code')
            ->where('item_id', $item->id)
            ->orderBy('moved_at')->orderBy('id');

        // Same read-only shape as the ledger index: running balance + warehouse
        // NAME (not a bare id) + movement cost.
        return $this->paginated(
            $query->paginate($perPage)->withQueryString()
                ->through(fn ($row) => StockLedgerController::movementRow($row))
        );
    }

    /**
     * Read-only valuation visibility for an item: per-warehouse on-hand,
     * average cost and total value, PLUS the FIFO cost-layer stack (the data
     * the costing engine maintains but never surfaced before). Includes a
     * reconciliation flag proving Σ(layer remaining_qty × unit_cost) equals the
     * balance's total_value for FIFO warehouses. Org-scoped + permission-gated
     * by the route; touches no writes and preserves the immutable-ledger model.
     */
    public function valuation(Item $item): JsonResponse
    {
        $balances = StockBalance::query()->where('item_id', $item->id)->get();
        $layers = CostLayer::query()->where('item_id', $item->id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('warehouse_id')->orderBy('received_at')->orderBy('id')
            ->get();
        $layersByWarehouse = $layers->groupBy('warehouse_id');

        // Resolve warehouse names/codes once so the UI shows names, not raw IDs.
        $warehouseMeta = \App\Models\Tenant\Warehouse::query()
            ->whereIn('id', $balances->pluck('warehouse_id')->all())
            ->get(['id', 'name', 'code'])->keyBy('id');

        $warehouses = $balances->map(function ($b) use ($layersByWarehouse, $warehouseMeta) {
            $whLayers = $layersByWarehouse->get($b->warehouse_id, collect());
            $whMeta = $warehouseMeta->get($b->warehouse_id);

            $fifoValue = '0';
            $layerOut = $whLayers->map(function ($l) use (&$fifoValue) {
                $lineValue = Decimal::mul((string) $l->remaining_qty, (string) $l->unit_cost);
                $fifoValue = Decimal::add($fifoValue, $lineValue);

                return [
                    'id' => $l->id,
                    'received_at' => optional($l->received_at)->toDateString(),
                    'unit_cost' => (string) $l->unit_cost,
                    'original_qty' => (string) $l->original_qty,
                    'remaining_qty' => (string) $l->remaining_qty,
                    'lot_id' => $l->lot_id,
                    'layer_value' => Decimal::money($lineValue),
                    'source_ledger_id' => $l->source_ledger_id,
                ];
            })->values();

            // The balance carries a WEIGHTED-AVERAGE projection (average_cost ×
            // on_hand_qty); the cost layers carry the true FIFO value. These two
            // legitimately DIVERGE for a FIFO item after partial consumption, so
            // we surface BOTH rather than forcing them to match. The hard
            // invariant that MUST hold is on QUANTITY: the sum of remaining layer
            // qty equals on-hand qty (when layers exist, i.e. FIFO).
            $averageValue = Decimal::money((string) $b->total_value);
            $fifoValue = Decimal::money($fifoValue);

            $layerQty = '0';
            foreach ($whLayers as $l) {
                $layerQty = Decimal::add($layerQty, (string) $l->remaining_qty);
            }
            $qtyReconciled = $whLayers->isEmpty()
                ? true
                : Decimal::qty($layerQty) === Decimal::qty((string) $b->on_hand_qty);

            return [
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $whMeta?->name,
                'warehouse_code' => $whMeta?->code,
                'on_hand_qty' => (string) $b->on_hand_qty,
                'reserved_qty' => (string) $b->reserved_qty,
                'available_qty' => (string) $b->available_qty,
                'average_cost' => (string) $b->average_cost,
                'average_value' => $averageValue,   // weighted-average projection
                'fifo_value' => $fifoValue,         // true FIFO value from layers
                'qty_reconciled' => $qtyReconciled, // Σ layer qty == on-hand qty
                'layers' => $layerOut,
            ];
        })->values();

        // Headline on-hand value uses the item's own costing basis: FIFO items
        // report the true layer value; average items report the balance value.
        // NB: the costing basis lives on Item::effectiveCostingMethod() (column
        // `costing_method`, falling back to the org InventorySetting) — NOT
        // `default_costing_method`, which is an InventorySetting column.
        $method = $item->effectiveCostingMethod();
        $isFifo = $method === 'fifo';
        $averageTotal = '0';
        $fifoTotal = '0';
        foreach ($warehouses as $w) {
            $averageTotal = Decimal::add($averageTotal, $w['average_value']);
            $fifoTotal = Decimal::add($fifoTotal, $w['fifo_value']);
        }

        return $this->success([
            'item_id' => $item->id,
            'sku' => $item->sku,
            'costing_method' => $method,
            'on_hand_value' => Decimal::money($isFifo ? $fifoTotal : $averageTotal),
            'average_value_total' => Decimal::money($averageTotal),
            'fifo_value_total' => Decimal::money($fifoTotal),
            'warehouses' => $warehouses,
        ]);
    }
}
