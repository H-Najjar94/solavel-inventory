<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\SupplierTerms\FinanceLink;
use App\Services\Purchasing\SupplierTerms\ItemSupplierService;
use App\Services\Purchasing\SupplierTerms\ReorderSuggestions;
use App\Services\Purchasing\SupplierTerms\SupplierPriceResolver;
use App\Services\Purchasing\SupplierTerms\SupplierPurchaseHistory;
use App\Services\Purchasing\SupplierTerms\SupplierTermsCsv;
use App\Services\Stock\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Supplier-item terms, vendor price suggestions, purchase history and reorder suggestions. */
class ItemSupplierController extends ApiController
{
    public function __construct(private readonly ItemSupplierService $service) {}

    public function index(Item $item): JsonResponse
    {
        $this->enabled();
        $relationships = ItemSupplier::query()->where('item_id', $item->id)
            ->with(['supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices'])
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'historical' THEN 1 ELSE 2 END")->orderBy('id')->get();

        return $this->success([
            'item_id' => $item->id,
            'preferred_supplier_id' => $item->preferred_supplier_id ? (int) $item->preferred_supplier_id : null,
            'base_unit_id' => $item->base_unit_id ? (int) $item->base_unit_id : null,
            'suppliers' => $relationships->map(fn ($r) => $this->service->present($r, $item))->values(),
            'finance' => ['state' => app(FinanceLink::class)->state()['state']],
            'price_rule' => __('inventory.supplier_terms.price_rule'),
        ]);
    }

    public function show(Item $item, ItemSupplier $itemSupplier): JsonResponse
    {
        $this->enabled();
        $this->owned($item, $itemSupplier);

        return $this->success($this->service->present($itemSupplier, $item));
    }

    public function store(Request $request, Item $item): JsonResponse
    {
        $this->enabled();
        $data = $request->validate($this->service->rules());

        return $this->success($this->service->present($this->service->save($item, $data, null, $request->user()?->id), $item->fresh()), 201);
    }

    public function update(Request $request, Item $item, ItemSupplier $itemSupplier): JsonResponse
    {
        $this->enabled();
        $this->owned($item, $itemSupplier);
        $data = $request->validate($this->service->rules(false));

        return $this->success($this->service->present($this->service->save($item, $data, $itemSupplier, $request->user()?->id), $item->fresh()));
    }

    public function destroy(Item $item, ItemSupplier $itemSupplier): JsonResponse
    {
        $this->enabled();
        $this->owned($item, $itemSupplier);
        $this->service->delete($itemSupplier);

        return $this->success(['deleted' => true]);
    }

    public function supplierItems(Request $request, Supplier $supplier): JsonResponse
    {
        $this->enabled();
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $search = addcslashes(trim((string) $request->query('search', '')), '%_\\');
        $page = ItemSupplier::query()->where('supplier_id', $supplier->id)
            ->with(['item:id,sku,name,item_type,base_unit_id,preferred_supplier_id,is_active', 'supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('supplier_sku', 'like', "%$search%")
                ->orWhere('supplier_item_name', 'like', "%$search%")
                ->orWhereHas('item', fn ($i) => $i->where('sku', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))))
            ->orderBy('id')->paginate($perPage)->withQueryString();

        return $this->paginated($page->through(fn (ItemSupplier $r) => $this->service->present($r, $r->item) + [
            'item' => $r->item?->only(['id', 'sku', 'name', 'item_type', 'is_active']),
        ]));
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'min:1'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'date' => ['nullable', 'date'],
        ]);
        $item = Item::query()->whereKey($data['item_id'])->firstOrFail();

        return $this->success(app(SupplierPriceResolver::class)->suggest(
            $item, isset($data['supplier_id']) ? (int) $data['supplier_id'] : null, (string) ($data['quantity'] ?? '1'),
            isset($data['unit_id']) ? (int) $data['unit_id'] : null, $data['currency_code'] ?? null, $data['date'] ?? null,
        ));
    }

    /** Supplier SKU lookup for PO entry: exact or prefix match on the supplier's code for the item. */
    public function lookup(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['required', 'string', 'max:100'],
        ]);
        $rows = ItemSupplier::query()->where('status', 'approved')
            ->when($data['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->where(fn ($q) => $q->where('supplier_sku', 'like', addcslashes($data['search'], '%_\\').'%')->orWhere('supplier_item_name', 'like', '%'.addcslashes($data['search'], '%_\\').'%'))
            ->with(['item:id,sku,name,base_unit_id,is_active', 'supplier:id,code,name'])
            ->limit(20)->get()->filter(fn ($r) => $r->item && $r->item->is_active);

        return $this->success($rows->map(fn ($r) => [
            'item_id' => $r->item_id, 'item_sku' => $r->item->sku, 'item_name' => $r->item->name,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $r->supplier?->name,
            'supplier_sku' => $r->supplier_sku, 'supplier_item_name' => $r->supplier_item_name,
            'purchase_unit_id' => $r->purchase_unit_id,
        ])->values());
    }

    public function itemHistory(Request $request, Item $item): JsonResponse
    {
        $this->enabled();

        return $this->success($this->history($request, ['item_id' => $item->id]) + [
            'vendor_prices' => $this->vendorPrices($item),
        ]);
    }

    public function supplierHistory(Request $request, Supplier $supplier): JsonResponse
    {
        $this->enabled();

        return $this->success($this->history($request, ['supplier_id' => $supplier->id]));
    }

    /** Vendor comparison: saved pricelist vs last paid, lead time, per supplier of one item. */
    public function comparison(Request $request, Item $item): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
        ]);
        $suggestion = app(SupplierPriceResolver::class)->suggest($item, null, (string) ($data['quantity'] ?? '1'), null, null, $data['date'] ?? null);
        $history = app(SupplierPurchaseHistory::class)->history(['item_id' => $item->id]);
        $last = collect($history['summary'])->keyBy('supplier_id');
        $relationships = ItemSupplier::query()->where('item_id', $item->id)->with('supplier:id,code,name')->get();
        $candidates = collect($suggestion['candidates'])->keyBy('supplier_id');
        $rows = $relationships->map(function (ItemSupplier $r) use ($candidates, $last, $item) {
            $c = $candidates->get($r->supplier_id);
            $h = $last->get($r->supplier_id);
            $variance = $c && $h && $h['last_base_unit_cost'] !== null && $c['net_unit_price_base_unit_in_base_currency'] !== null
                ? Decimal::cost(Decimal::sub($c['net_unit_price_base_unit_in_base_currency'], $h['last_base_unit_cost'])) : null;

            return [
                'supplier_id' => $r->supplier_id,
                'supplier' => $r->supplier?->only(['id', 'code', 'name']),
                'status' => $r->status,
                'is_preferred' => (int) $item->preferred_supplier_id === (int) $r->supplier_id,
                'currency_code' => $r->currency_code,
                'vendor_price' => $c['net_unit_price'] ?? null,
                'vendor_price_base_unit_in_base_currency' => $c['net_unit_price_base_unit_in_base_currency'] ?? null,
                'normalized' => $c ? $c['net_unit_price_base_unit_in_base_currency'] !== null : null,
                'last_paid_base_unit_cost' => $h['last_base_unit_cost'] ?? null,
                'last_paid_date' => $h['last_date'] ?? null,
                'weighted_average_base_unit_cost' => $h['weighted_average_base_unit_cost'] ?? null,
                'vendor_vs_last_paid' => $variance,
                'lead_time_days' => $r->lead_time_days,
                'is_best' => ($suggestion['best']['supplier_id'] ?? null) === (int) $r->supplier_id,
            ];
        })->values();

        return $this->success([
            'item_id' => $item->id,
            'quantity' => $suggestion['quantity'],
            'date' => $suggestion['date'],
            'base_currency_code' => $suggestion['base_currency_code'],
            'rows' => $rows,
            'trend' => $this->trend($history['bills'] !== [] ? $history['bills'] : $history['receipts']),
            'rule' => __('inventory.supplier_terms.comparison_rule'),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->success(['rows' => app(ReorderSuggestions::class)->suggestions(
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
        )]);
    }

    public function export(Request $request): Response
    {
        $this->enabled();
        $csv = app(SupplierTermsCsv::class)->export(
            $request->filled('item_id') ? (int) $request->query('item_id') : null,
            $request->filled('supplier_id') ? (int) $request->query('supplier_id') : null,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vendor-pricelist-'.now()->format('Ymd').'.csv"',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000000'],
            'apply' => ['nullable', 'boolean'],
        ]);

        return $this->success(app(SupplierTermsCsv::class)->import($data['content'], (bool) ($data['apply'] ?? false), $request->user()?->id));
    }

    private function history(Request $request, array $filters): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return app(SupplierPurchaseHistory::class)->history($filters + array_filter($data), (int) config('supplier_terms.history_limit', 200));
    }

    private function vendorPrices(Item $item): array
    {
        return ItemSupplier::query()->where('item_id', $item->id)
            ->with(['supplier:id,code,name,is_active', 'purchaseUnit:id,code,name,symbol', 'prices'])->get()
            ->map(fn ($r) => $this->service->present($r, $item))->values()->all();
    }

    /** Monthly quantity-weighted base unit cost per supplier. */
    private function trend(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if ($row['base_unit_cost'] === null) {
                continue;
            }
            $key = substr($row['date'], 0, 7).'|'.($row['supplier_id'] ?? $row['supplier_name']);
            $buckets[$key] ??= ['month' => substr($row['date'], 0, 7), 'supplier_id' => $row['supplier_id'], 'supplier_name' => $row['supplier_name'], 'qty' => '0', 'value' => '0'];
            $buckets[$key]['qty'] = Decimal::add($buckets[$key]['qty'], $row['quantity']);
            $buckets[$key]['value'] = Decimal::add($buckets[$key]['value'], Decimal::mul($row['quantity'], $row['base_unit_cost']));
        }
        ksort($buckets);

        return array_values(array_map(fn ($b) => [
            'month' => $b['month'], 'supplier_id' => $b['supplier_id'], 'supplier_name' => $b['supplier_name'],
            'quantity' => Decimal::qty($b['qty']),
            'average_base_unit_cost' => Decimal::gt($b['qty'], '0') ? Decimal::cost(Decimal::div($b['value'], $b['qty'])) : null,
        ], $buckets));
    }

    private function owned(Item $item, ItemSupplier $relationship): void
    {
        abort_unless((int) $relationship->item_id === (int) $item->id, 404);
    }

    private function enabled(): void
    {
        abort_unless((bool) config('supplier_terms.enabled', true), 404);
        // Code can be live a moment before a tenant's additive migration; answer clearly, never 500.
        abort_unless(\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('item_suppliers'), 503, 'supplier_terms_schema_pending');
    }
}
