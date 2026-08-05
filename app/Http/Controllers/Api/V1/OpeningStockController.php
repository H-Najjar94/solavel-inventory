<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreOpeningStockRequest;
use App\Models\Tenant\Item;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Warehouse;
use App\Services\Documents\OpeningStockService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Opening Stock documents. All stock writes are delegated to OpeningStockService
 * → StockLedgerService. This controller NEVER touches stock tables directly.
 */
class OpeningStockController extends ApiController
{
    use \App\Http\Controllers\Concerns\EnforcesInventoryLimits;

    public function __construct(private OpeningStockService $service, private OrganizationContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = OpeningStockEntry::query()
            ->with(['warehouse:id,name,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (OpeningStockEntry $entry) {
            $entry->setAttribute('warehouse_name', $entry->warehouse?->name);
            $entry->setAttribute('warehouse_code', $entry->warehouse?->code);
            return $entry;
        }));
    }

    public function show(OpeningStockEntry $entry): JsonResponse
    {
        $entry->load(['lines.item:id,name,sku', 'lines.enteredUnit:id,code,name,symbol', 'warehouse:id,name,code']);
        $entry->setAttribute('warehouse_name', $entry->warehouse?->name);
        $ledger = StockLedger::query()
            ->where('source_type', OpeningStockEntry::class)
            ->where('source_id', $entry->id)->get();

        return $this->success(['entry' => $entry, 'ledger' => $ledger]);
    }

    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            unset($data['entry_number']);
            $entry = $this->service->createDraft(
                collect($data)->except('lines')->toArray(),
                $data['lines']
            );
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_create_failed', $e->getMessage(), 422);
        }

        return $this->success($entry, 201);
    }

    public function update(StoreOpeningStockRequest $request, OpeningStockEntry $entry): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($entry, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(OpeningStockEntry $entry): JsonResponse
    {
        try {
            $entry = $this->service->post($entry);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_post_failed', $e->getMessage(), 422);
        }

        return $this->success($entry->fresh('lines'));
    }

    public function reverse(OpeningStockEntry $entry): JsonResponse
    {
        try {
            $entry = $this->service->reverse($entry);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success($entry->fresh('lines'));
    }

    public function importCsv(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'warehouse_id' => ['required', 'integer'],
            'opening_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'post' => ['nullable', 'boolean'],
        ]);
        $orgId = $this->context->idOrFail();
        Warehouse::query()->whereKey($data['warehouse_id'])->firstOrFail();

        $rows = $this->readCsv($request->file('file')->getRealPath());
        $lines = [];
        $created = 0;

        // The CSV import creates new SKUs, so it must honour the same stock.max_items
        // ceiling as ItemController::store — a bulk import is not an escape hatch past
        // the plan limit. Count the org's existing items ONCE; the per-row guard below
        // adds $created so the (existing + newly-imported) total is what's checked.
        $existingItemCount = Item::query()->count();

        foreach ($rows as $index => $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? $sku));
            if ($sku === '' || $name === '') {
                return $this->error('invalid_import_row', 'Each import row needs sku and name. Row '.($index + 2).'.', 422);
            }
            $qty = (string) ($row['quantity'] ?? $row['qty'] ?? '');
            if ($qty === '' || (float) $qty <= 0) {
                return $this->error('invalid_import_row', 'Each import row needs a positive quantity. Row '.($index + 2).'.', 422);
            }

            $item = Item::query()->where('sku', $sku)->first();
            if (! $item) {
                $categoryId = filter_var($row['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $unitId = filter_var($row['base_unit_id'] ?? $row['unit_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $validCategory = $categoryId && \App\Models\Tenant\ItemCategory::query()
                    ->whereKey($categoryId)->where('is_active', true)->whereNull('deleted_at')->exists();
                $validUnit = $unitId && \App\Models\Tenant\Unit::query()
                    ->whereKey($unitId)->where('is_active', true)->whereNull('deleted_at')->exists();
                if (! $validCategory || ! $validUnit) {
                    return $this->error('invalid_import_master_data',
                        'Each new inventory item needs a valid category_id and base_unit_id. Row '.($index + 2).'.', 422);
                }
                // Grandfathered ceiling: block the import at the plan limit (existing
                // + created so far), exactly like a single create. Existing items are
                // untouched; only NEW rows past the limit are refused (402).
                $this->enforceLimit('stock.max_items', $existingItemCount + $created);
                $item = Item::query()->create([
                    'organization_id' => $orgId,
                    'sku' => $sku,
                    'name' => $name,
                    'item_type' => $row['item_type'] ?? 'inventory',
                    'tracking_type' => $row['tracking_type'] ?? 'none',
                    'costing_method' => $row['costing_method'] ?? 'average',
                    'category_id' => $categoryId,
                    'base_unit_id' => $unitId,
                    'purchase_price' => $row['purchase_price'] ?? $row['unit_cost'] ?? 0,
                    'sales_price' => $row['sales_price'] ?? 0,
                    'is_active' => true,
                ]);
                $created++;
            }

            $lines[] = [
                'item_id' => $item->id,
                'quantity' => $qty,
                'unit_cost' => $row['unit_cost'] ?? '0',
                'lot_code' => $row['lot_code'] ?? null,
                'expiry_date' => $row['expiry_date'] ?? null,
                'bin_id' => $row['bin_id'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        if ($lines === []) {
            return $this->error('empty_import', __('inventory.imports.empty'), 422);
        }

        $entry = $this->service->createDraft([
            'warehouse_id' => (int) $data['warehouse_id'],
            'opening_date' => $data['opening_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? 'Bulk opening-stock import',
        ], $lines);

        if ($request->boolean('post')) {
            $entry = $this->service->post($entry);
        }

        return $this->success([
            'entry' => $entry->fresh('lines'),
            'created_items' => $created,
            'line_count' => count($lines),
            'posted' => $request->boolean('post'),
        ], 201);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        $header = null;
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $line);
                continue;
            }
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $values = array_slice(array_pad($line, count($header), null), 0, count($header));
            $rows[] = array_combine($header, $values);
        }
        fclose($handle);

        return $rows;
    }
}
