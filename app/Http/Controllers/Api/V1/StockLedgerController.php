<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\StockLedger;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\SourceDocumentPresenter;
use App\Services\Stock\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The canonical stock ledger — read-only. Supports item/warehouse ledger views,
 * date and movement-type filters, and source-document drill-down fields. Running
 * quantity/value are read from the stored balance_*_after snapshots.
 */
class StockLedgerController extends ApiController
{
    public function __construct(private WarehouseAccessService $warehouseAccess) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = StockLedger::query()
            ->with(['warehouse:id,name,code', 'item:id,name,sku'])
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', (int) $request->query('item_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->query('direction')))
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->query('source_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('moved_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('moved_at', '<=', $request->query('to')))
            // Ledger is chronological: oldest→newest gives a meaningful running balance.
            ->orderBy('moved_at')->orderBy('id');
        $this->warehouseAccess->scope($query);

        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(SourceDocumentPresenter::decorateRows($page->getCollection()));

        return $this->paginated($page->through(fn ($row) => self::movementRow($row)));
    }

    /**
     * Read-only: the FIFO cost layers a single OUTBOUND movement consumed. Returns
     * each draw-down with its qty, the layer's unit cost, the resulting total
     * value, a reference to the source cost layer (and the layer's own origin
     * ledger), and timestamps. Empty for inbound or average-costed movements.
     *
     * The {ledger} binding is org-scoped by the global OrganizationScope, so a row
     * belonging to another organization 404s — isolation is enforced by the model,
     * not by this method.
     */
    public function consumedLayers(StockLedger $ledger): JsonResponse
    {
        $consumptions = $ledger->consumptions()->orderBy('id')->get();

        // Pull the referenced layers once (org-scoped) to expose their origin.
        $layers = CostLayer::query()
            ->whereIn('id', $consumptions->pluck('cost_layer_id')->all())
            ->get()
            ->keyBy('id');
        $sourceLedgerRows = SourceDocumentPresenter::decorateRows(
            StockLedger::query()
                ->whereIn('cost_layer_id', $layers->keys()->all())
                ->where('direction', 'in')
                ->get()
        )->keyBy('cost_layer_id');

        $totalValue = '0';
        $rows = $consumptions->map(function ($c) use ($layers, $sourceLedgerRows, &$totalValue) {
            $value = Decimal::money(Decimal::mul((string) $c->qty, (string) $c->unit_cost));
            $totalValue = Decimal::add($totalValue, $value);
            $layer = $layers->get($c->cost_layer_id);
            $source = $layer ? $sourceLedgerRows->get($layer->id) : null;

            return [
                'cost_layer_id' => $c->cost_layer_id,
                'qty' => (string) $c->qty,
                'unit_cost' => (string) $c->unit_cost,
                'total_value' => $value,
                'source_layer' => $layer ? [
                    'id' => $layer->id,
                    'received_at' => optional($layer->received_at)->toDateString(),
                    'original_qty' => (string) $layer->original_qty,
                    'remaining_qty' => (string) $layer->remaining_qty,
                    'source_ledger_id' => $source?->id ?? $layer->source_ledger_id,
                    'source_display' => $source?->source_display,
                    'source_label' => $source?->source_label,
                    'source_number' => $source?->source_number,
                    'source_route' => $source?->source_route,
                    'source_missing' => (bool) ($source?->source_missing ?? false),
                    'lot_id' => $layer->lot_id,
                ] : null,
                'consumed_at' => optional($c->created_at)->toDateTimeString(),
            ];
        })->values();

        return $this->success([
            'ledger_id' => $ledger->id,
            'direction' => $ledger->direction,
            'item_id' => $ledger->item_id,
            'warehouse_id' => $ledger->warehouse_id,
            'warehouse_name' => $ledger->warehouse?->name,
            'warehouse_code' => $ledger->warehouse?->code,
            'source_display' => $ledger->source_display ?? null,
            'source_label' => $ledger->source_label ?? ($ledger->source_type ? class_basename((string) $ledger->source_type) : null),
            'source_number' => $ledger->source_number ?? null,
            'costing_method' => $ledger->costing_method,
            'consumed_layer_count' => $rows->count(),
            'consumed_total_value' => Decimal::money($totalValue),
            'layers' => $rows,
        ]);
    }

    /**
     * Shared read-only shape for a movement row: clear running-balance fields,
     * movement cost, and a resolved warehouse NAME (never a bare id). Used by the
     * ledger index and the per-item movements endpoint so both read identically.
     */
    public static function movementRow(StockLedger $row): array
    {
        return [
            'id' => $row->id,
            'moved_at' => optional($row->moved_at)->toDateTimeString(),
            'direction' => $row->direction,
            'quantity' => (string) $row->quantity,
            'unit_cost' => $row->unit_cost === null ? null : (string) $row->unit_cost,
            'total_cost' => $row->total_cost === null ? null : (string) $row->total_cost,
            'balance_qty_after' => $row->balance_qty_after === null ? null : (string) $row->balance_qty_after,
            'balance_value_after' => $row->balance_value_after === null ? null : (string) $row->balance_value_after,
            'costing_method' => $row->costing_method,
            'cost_layer_id' => $row->cost_layer_id,
            'item_id' => $row->item_id,
            'item_name' => $row->item?->name,
            'item_sku' => $row->item?->sku,
            'warehouse_id' => $row->warehouse_id,
            'warehouse_name' => $row->warehouse?->name,
            'warehouse_code' => $row->warehouse?->code,
            'lot_id' => $row->lot_id,
            'serial_id' => $row->serial_id,
            'source_type' => $row->source_type,
            'source_label' => $row->source_label ?? ($row->source_type ? class_basename((string) $row->source_type) : null),
            'source_number' => $row->source_number ?? null,
            'source_display' => $row->source_display ?? null,
            'source_route' => $row->source_route ?? null,
            'source_missing' => (bool) ($row->source_missing ?? false),
            'source_id' => $row->source_id,
            'source_line_id' => $row->source_line_id,
        ];
    }
}
