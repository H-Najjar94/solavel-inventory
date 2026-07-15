<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\ItemVariant;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPriceList;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\SourceDocumentPresenter;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends ApiController
{
    use \App\Http\Controllers\Concerns\EnforcesInventoryLimits;

    /** Strip non-column / transient fields before persisting the item row. */
    private function itemAttributes(array $data): array
    {
        unset($data['track_lot'], $data['track_serial'], $data['track_expiry'], $data['barcode']);

        return $data;
    }

    private function barcodeRow(ItemBarcode $barcode): array
    {
        return [
            'id' => $barcode->id,
            'item_id' => $barcode->item_id,
            'variant_id' => $barcode->variant_id,
            'barcode' => $barcode->barcode,
            'type' => $barcode->type,
            'item' => $barcode->relationLoaded('item') && $barcode->item ? [
                'id' => $barcode->item->id,
                'sku' => $barcode->item->sku,
                'name' => $barcode->item->name,
            ] : null,
        ];
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
        $item->load([
            'category:id,name',
            'brand:id,name',
            'baseUnit:id,code,name,symbol',
            'variants',
            'images:id,item_id,is_primary,sort',
            'attachments:id,item_id,name,mime_type,size_bytes,created_at',
            'supplierPrices.supplier:id,code,name',
        ]);

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

        $barcodes = ItemBarcode::query()->where('item_id', $item->id)->orderByRaw("type = 'primary' desc")->orderBy('barcode')->get();
        $primaryBarcode = $barcodes->first()?->barcode;

        return $this->success([
            'item' => $item,
            'primary_barcode' => $primaryBarcode,
            'barcodes' => $barcodes->map(fn (ItemBarcode $b) => $this->barcodeRow($b))->values(),
            'stock_by_warehouse' => $balances,
            'images' => $images,
            'primary_image_url' => $primary['url'] ?? null,
            'attachments' => $item->attachments->values(),
            'supplier_price_lists' => $item->supplierPrices->sortByDesc('is_active')->values(),
            'package_recommendation' => $this->packageRecommendation($item),
        ]);
    }

    private function packageRecommendation(Item $item): ?array
    {
        $dims = [(float) ($item->length ?? 0), (float) ($item->width ?? 0), (float) ($item->height ?? 0)];
        if (min($dims) <= 0) {
            return null;
        }
        sort($dims);
        $weight = (float) ($item->weight ?? 0);
        $cartons = [
            ['code' => 'S', 'label' => 'Small carton', 'dims' => [20, 15, 10], 'max_weight' => 5],
            ['code' => 'M', 'label' => 'Medium carton', 'dims' => [35, 25, 20], 'max_weight' => 15],
            ['code' => 'L', 'label' => 'Large carton', 'dims' => [60, 40, 35], 'max_weight' => 30],
            ['code' => 'XL', 'label' => 'Oversize carton', 'dims' => [100, 60, 50], 'max_weight' => 50],
        ];

        foreach ($cartons as $carton) {
            $box = $carton['dims'];
            sort($box);
            if ($dims[0] <= $box[0] && $dims[1] <= $box[1] && $dims[2] <= $box[2] && ($weight <= 0 || $weight <= $carton['max_weight'])) {
                return [
                    'code' => $carton['code'],
                    'label' => $carton['label'],
                    'max_dimensions' => implode(' x ', $carton['dims']),
                    'max_weight' => $carton['max_weight'],
                ];
            }
        }

        return [
            'code' => 'FREIGHT',
            'label' => 'Freight or custom package',
            'max_dimensions' => null,
            'max_weight' => null,
        ];
    }

    public function barcodeLookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('barcode', ''));
        if ($code === '') {
            return $this->error('barcode_required', 'Barcode is required.', 422);
        }

        $barcode = ItemBarcode::query()->with('item:id,sku,name,item_type,tracking_type,is_active')
            ->where('barcode', $code)->first();

        if (! $barcode) {
            return $this->error('barcode_not_found', 'No item matches that barcode.', 404);
        }

        return $this->success($this->barcodeRow($barcode));
    }

    public function storeBarcode(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $exists = ItemBarcode::query()->where('barcode', $data['barcode'])->exists();
        if ($exists) {
            return $this->error('barcode_taken', 'Barcode must be unique within the organization.', 422);
        }

        $barcode = ItemBarcode::query()->create([
            'organization_id' => $item->organization_id,
            'item_id' => $item->id,
            'variant_id' => $data['variant_id'] ?? null,
            'barcode' => $data['barcode'],
            'type' => $data['type'] ?? 'internal',
        ]);

        return $this->success($this->barcodeRow($barcode), 201);
    }

    public function destroyBarcode(Item $item, ItemBarcode $barcode): JsonResponse
    {
        if ((int) $barcode->item_id !== (int) $item->id) {
            return $this->error('barcode_item_mismatch', 'Barcode does not belong to this item.', 404);
        }

        $barcode->delete();

        return $this->success(['deleted' => true]);
    }

    public function primaryBarcode(Item $item, ItemBarcode $barcode): JsonResponse
    {
        if ((int) $barcode->item_id !== (int) $item->id) {
            return $this->error('barcode_item_mismatch', 'Barcode does not belong to this item.', 404);
        }

        ItemBarcode::query()->where('item_id', $item->id)->where('type', 'primary')->update(['type' => 'internal']);
        $barcode->update(['type' => 'primary']);

        return $this->success($this->barcodeRow($barcode->fresh()));
    }

    public function storeVariant(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'variant_attributes' => ['nullable', 'array'],
            'barcode_primary' => ['nullable', 'string', 'max:100'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (ItemVariant::query()->where('sku', $data['sku'])->exists() || Item::query()->where('sku', $data['sku'])->exists()) {
            return $this->error('variant_sku_taken', 'Variant SKU must be unique.', 422);
        }
        if (! empty($data['barcode_primary']) && ItemBarcode::query()->where('barcode', $data['barcode_primary'])->exists()) {
            return $this->error('barcode_taken', 'Barcode must be unique within the organization.', 422);
        }

        $variant = ItemVariant::query()->create([
            'organization_id' => $item->organization_id,
            'item_id' => $item->id,
            'sku' => $data['sku'],
            'variant_attributes' => $data['variant_attributes'] ?? [],
            'barcode_primary' => $data['barcode_primary'] ?? null,
            'purchase_price' => $data['purchase_price'] ?? null,
            'sales_price' => $data['sales_price'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (! empty($data['barcode_primary'])) {
            ItemBarcode::query()->create([
                'organization_id' => $item->organization_id,
                'item_id' => $item->id,
                'variant_id' => $variant->id,
                'barcode' => $data['barcode_primary'],
                'type' => 'primary',
            ]);
        }
        $item->forceFill(['is_variant_parent' => true])->save();

        return $this->success($variant->fresh(), 201);
    }

    public function updateVariant(Request $request, Item $item, ItemVariant $variant): JsonResponse
    {
        if ((int) $variant->item_id !== (int) $item->id) {
            return $this->error('variant_item_mismatch', 'Variant does not belong to this item.', 404);
        }

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'variant_attributes' => ['nullable', 'array'],
            'barcode_primary' => ['nullable', 'string', 'max:100'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (ItemVariant::query()->where('sku', $data['sku'])->whereKeyNot($variant->id)->exists() || Item::query()->where('sku', $data['sku'])->exists()) {
            return $this->error('variant_sku_taken', 'Variant SKU must be unique.', 422);
        }

        $variant->fill([
            'sku' => $data['sku'],
            'variant_attributes' => $data['variant_attributes'] ?? [],
            'barcode_primary' => $data['barcode_primary'] ?? null,
            'purchase_price' => $data['purchase_price'] ?? null,
            'sales_price' => $data['sales_price'] ?? null,
            'is_active' => $data['is_active'] ?? $variant->is_active,
        ])->save();

        if (! empty($data['barcode_primary'])) {
            $existing = ItemBarcode::query()->where('barcode', $data['barcode_primary'])
                ->where(fn ($q) => $q->whereNull('variant_id')->orWhere('variant_id', '!=', $variant->id))
                ->exists();
            if ($existing) {
                return $this->error('barcode_taken', 'Barcode must be unique within the organization.', 422);
            }
            ItemBarcode::query()->updateOrCreate(
                ['variant_id' => $variant->id, 'type' => 'primary'],
                ['organization_id' => $item->organization_id, 'item_id' => $item->id, 'barcode' => $data['barcode_primary']]
            );
        }

        return $this->success($variant->fresh());
    }

    public function destroyVariant(Item $item, ItemVariant $variant): JsonResponse
    {
        if ((int) $variant->item_id !== (int) $item->id) {
            return $this->error('variant_item_mismatch', 'Variant does not belong to this item.', 404);
        }
        $variant->delete();

        return $this->success(['deleted' => true]);
    }

    public function storeSupplierPrice(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'minimum_qty' => ['nullable', 'numeric', 'gt:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);
        Supplier::query()->whereKey($data['supplier_id'])->firstOrFail();

        $price = SupplierPriceList::query()->create([
            'organization_id' => $item->organization_id,
            'item_id' => $item->id,
            'supplier_id' => $data['supplier_id'],
            'supplier_sku' => $data['supplier_sku'] ?? null,
            'unit_cost' => $data['unit_cost'],
            'minimum_qty' => $data['minimum_qty'] ?? 1,
            'currency_code' => strtoupper($data['currency_code'] ?? 'SAR'),
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($price->fresh('supplier:id,code,name'), 201);
    }

    public function updateSupplierPrice(Request $request, Item $item, SupplierPriceList $price): JsonResponse
    {
        if ((int) $price->item_id !== (int) $item->id) {
            return $this->error('supplier_price_item_mismatch', 'Supplier price does not belong to this item.', 404);
        }
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'minimum_qty' => ['nullable', 'numeric', 'gt:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);
        Supplier::query()->whereKey($data['supplier_id'])->firstOrFail();
        $price->fill($data + ['minimum_qty' => 1, 'is_active' => true])->save();

        return $this->success($price->fresh('supplier:id,code,name'));
    }

    public function destroySupplierPrice(Item $item, SupplierPriceList $price): JsonResponse
    {
        if ((int) $price->item_id !== (int) $item->id) {
            return $this->error('supplier_price_item_mismatch', 'Supplier price does not belong to this item.', 404);
        }
        $price->delete();

        return $this->success(['deleted' => true]);
    }

    public function labelSheet(Item $item): JsonResponse
    {
        $barcodes = ItemBarcode::query()->where('item_id', $item->id)->orderByRaw("type = 'primary' desc")->orderBy('barcode')->get();
        $rows = $barcodes->map(fn (ItemBarcode $barcode) => [
            'item_id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'barcode' => $barcode->barcode,
            'type' => $barcode->type,
            'qr_svg' => $this->qrSvg($barcode->barcode),
        ])->values();

        return $this->success(['labels' => $rows]);
    }

    private function qrSvg(string $value): string
    {
        $hash = hash('sha256', $value);
        $cells = 21;
        $rects = [];
        for ($y = 0; $y < $cells; $y++) {
            for ($x = 0; $x < $cells; $x++) {
                $i = ($x + $y * $cells) % strlen($hash);
                $dark = hexdec($hash[$i]) % 2 === 0;
                if ($x < 7 && $y < 7 || $x > 13 && $y < 7 || $x < 7 && $y > 13) {
                    $dark = $x === 0 || $y === 0 || $x === 6 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                }
                if ($dark) {
                    $rects[] = '<rect x="'.$x.'" y="'.$y.'" width="1" height="1"/>';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21" shape-rendering="crispEdges">'.implode('', $rects).'</svg>';
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        // Grandfathered SKU ceiling: block only new items past the plan limit.
        // Item is org-scoped, so count() is the active org's current SKU count.
        $this->enforceLimit('stock.max_items', Item::query()->count());

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

    public function bulkUpdate(Request $request): JsonResponse
    {
        $orgId = app(OrganizationContext::class)->idOrFail();
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:500'],
            'item_ids.*' => ['required', 'integer', Rule::exists('items', 'id')->where('organization_id', $orgId)],
            'is_active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('organization_id', $orgId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('item_brands', 'id')->where('organization_id', $orgId)],
            'enable_reorder_alert' => ['nullable', 'boolean'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $fields = collect($data)->only([
            'is_active',
            'category_id',
            'brand_id',
            'enable_reorder_alert',
            'reorder_point',
            'reorder_qty',
        ])->filter(fn ($v) => $v !== null)->all();

        if ($fields === []) {
            return $this->error('bulk_update_empty', 'Select at least one bulk update field.', 422);
        }

        $items = Item::query()->whereIn('id', $data['item_ids'])->get();
        $updated = 0;
        foreach ($items as $item) {
            $before = $item->only(array_keys($fields));
            $item->fill($fields)->save();
            $updated++;
            InventoryAuditLog::create([
                'organization_id' => app(OrganizationContext::class)->id(),
                'actor_user_id' => auth()->id(),
                'action' => 'item.bulk_updated',
                'entity_type' => 'item',
                'entity_id' => $item->id,
                'before' => $before,
                'after' => $item->only(array_keys($fields)),
                'document_ref' => $item->sku,
                'created_at' => now(),
            ]);
        }

        return $this->success(['updated' => $updated]);
    }

    public function movements(Request $request, Item $item): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = StockLedger::query()->with(['warehouse:id,name,code', 'item:id,name,sku'])
            ->where('item_id', $item->id)
            ->orderBy('moved_at')->orderBy('id');

        // Same read-only shape as the ledger index: running balance + warehouse
        // NAME (not a bare id) + movement cost.
        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(SourceDocumentPresenter::decorateRows($page->getCollection()));

        return $this->paginated($page->through(fn ($row) => StockLedgerController::movementRow($row)));
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
        $sourceLedgerRows = SourceDocumentPresenter::decorateRows(
            StockLedger::query()
                ->whereIn('cost_layer_id', $layers->pluck('id')->all())
                ->where('direction', 'in')
                ->get()
        )->keyBy('cost_layer_id');

        // Resolve warehouse names/codes once so the UI shows names, not raw IDs.
        $warehouseMeta = \App\Models\Tenant\Warehouse::query()
            ->whereIn('id', $balances->pluck('warehouse_id')->all())
            ->get(['id', 'name', 'code'])->keyBy('id');

        $warehouses = $balances->map(function ($b) use ($layersByWarehouse, $warehouseMeta, $sourceLedgerRows) {
            $whLayers = $layersByWarehouse->get($b->warehouse_id, collect());
            $whMeta = $warehouseMeta->get($b->warehouse_id);

            $fifoValue = '0';
            $layerOut = $whLayers->map(function ($l) use (&$fifoValue, $sourceLedgerRows, $whMeta) {
                $lineValue = Decimal::mul((string) $l->remaining_qty, (string) $l->unit_cost);
                $fifoValue = Decimal::add($fifoValue, $lineValue);
                $source = $sourceLedgerRows->get($l->id);

                return [
                    'id' => $l->id,
                    'received_at' => optional($l->received_at)->toDateString(),
                    'unit_cost' => (string) $l->unit_cost,
                    'original_qty' => (string) $l->original_qty,
                    'remaining_qty' => (string) $l->remaining_qty,
                    'consumed_qty' => Decimal::qty(Decimal::sub((string) $l->original_qty, (string) $l->remaining_qty)),
                    'lot_id' => $l->lot_id,
                    'layer_value' => Decimal::money($lineValue),
                    'source_ledger_id' => $source?->id ?? $l->source_ledger_id,
                    'source_display' => $source?->source_display,
                    'source_label' => $source?->source_label,
                    'source_number' => $source?->source_number,
                    'source_route' => $source?->source_route,
                    'source_missing' => (bool) ($source?->source_missing ?? false),
                    'warehouse_name' => $whMeta?->name,
                    'warehouse_code' => $whMeta?->code,
                ];
            })->values();

            // `average_value` is the balance's own total_value. For a FIFO item the
            // balance is now valued on the FIFO basis (running ledger in−out cost),
            // so it AGREES with the layer-derived `fifo_value` — they no longer
            // diverge after partial consumption (the old weighted-average projection
            // drifted and broke the integrity value-reconciliation). For an AVERAGE
            // item, total_value is the weighted-average value. We still surface both
            // for transparency. The hard invariant: Σ remaining layer qty == on-hand
            // qty (when layers exist, i.e. FIFO).
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
