<?php

namespace App\Services\Integration;

use App\Models\Tenant\Item;
use App\Models\Tenant\PosSaleConsumption;
use App\Models\Tenant\PosSaleConsumptionLine;
use App\Models\Tenant\Warehouse;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a validated SolaPOS consumption/restoration inside the ALREADY
 * SELECTED tenant. Idempotent by (organization, source key): a replay returns
 * the original result — no second ledger row, cost consumption or journal.
 */
class SolaposConsumptionService
{
    public function __construct(private readonly StockLedgerService $ledger, private readonly IntegrationOutboxService $outbox) {}

    /** @return array{data: array, replayed: bool} */
    public function apply(int $orgId, array $data): array
    {
        // Idempotency: existing document ⇒ return its result verbatim.
        $existing = PosSaleConsumption::query()->where('organization_id', $orgId)->where('source_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ['data' => $existing->result + ['replayed' => true], 'replayed' => true];
        }

        // Ownership: every item / warehouse must belong to THIS organization.
        $itemIds = collect($data['lines'])->pluck('item_id')->unique()->all();
        $whIds = collect($data['lines'])->pluck('warehouse_id')->unique()->all();
        $items = Item::query()->withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $itemIds)->get()->keyBy('id');
        $whs = Warehouse::query()->withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $whIds)->pluck('id')->all();
        if ($items->count() !== count($itemIds) || count($whs) !== count($whIds)) {
            throw ValidationException::withMessages(['lines' => ['An item or warehouse does not belong to this organization.']]);
        }

        $isReturn = $data['event_type'] === 'pos_return.restored';
        $original = null;
        if ($isReturn && ! empty($data['original_consumption_key'])) {
            $original = PosSaleConsumption::query()->where('organization_id', $orgId)->where('source_key', $data['original_consumption_key'])->with('lines')->first();
        }

        $doc = DB::connection((string) config('tenancy.tenant_connection', 'tenant'))->transaction(function () use ($data, $orgId, $isReturn, $original, $items) {
            $doc = PosSaleConsumption::create([
                'organization_id' => $orgId, 'source_key' => $data['idempotency_key'], 'event_type' => $data['event_type'], 'direction' => $isReturn ? 'in' : 'out',
                'pos_order_id' => $data['order_id'] ?? $data['original_order_id'] ?? null, 'pos_order_return_id' => $data['return_id'] ?? null,
                'reverses_consumption_id' => $original?->id, 'reference' => $data['order_number'] ?? $data['return_number'] ?? null,
                'transaction_date' => substr((string) $data['completed_at'], 0, 10), 'status' => 'posted', 'contract_version' => 1, 'payload' => $data, 'posted_at' => now(),
            ]);
            $movements = [];
            foreach ($data['lines'] as $line) {
                $unitCost = null;
                if ($isReturn) {
                    // Restore at the ORIGINAL consumed cost of that order item, never at today's cost.
                    $orig = $original?->lines->firstWhere('pos_order_item_id', $line['pos_order_item_id'] ?? null);
                    $unitCost = $orig?->unit_cost !== null ? (string) $orig->unit_cost : (string) ($items[$line['item_id']]->purchase_price ?? '0');
                }
                $movements[] = new StockMovement(
                    direction: $isReturn ? 'in' : 'out', itemId: (int) $line['item_id'], warehouseId: (int) $line['warehouse_id'],
                    quantity: (string) $line['quantity'], sourceType: PosSaleConsumption::class, sourceId: (int) $doc->id, sourceLineId: null,
                    variantId: $line['item_variant_id'] ?? null, unitCost: $unitCost, movedAt: substr((string) $data['completed_at'], 0, 10),
                );
            }
            $rows = $this->ledger->post($movements, 'solapos:'.$data['idempotency_key'], ['actor' => 'solapos', 'reference' => $doc->reference]);
            $result = ['consumption_id' => $doc->id, 'lines' => []];
            foreach ($rows as $i => $row) {
                $src = $data['lines'][$i];
                PosSaleConsumptionLine::create([
                    'organization_id' => $orgId, 'pos_sale_consumption_id' => $doc->id, 'pos_order_item_id' => $src['pos_order_item_id'] ?? null,
                    'pos_allocation_id' => $src['pos_allocation_id'] ?? null, 'pos_order_return_item_id' => $src['pos_order_return_item_id'] ?? null,
                    'item_id' => $row->item_id, 'item_variant_id' => $row->variant_id ?? null, 'warehouse_id' => $row->warehouse_id, 'quantity' => $row->quantity,
                    'unit_cost' => $row->unit_cost, 'total_cost' => $row->total_cost, 'costing_method' => $row->costing_method, 'ledger_id' => $row->id,
                ]);
                $result['lines'][] = ['pos_allocation_id' => $src['pos_allocation_id'] ?? null, 'pos_order_item_id' => $src['pos_order_item_id'] ?? null, 'pos_order_return_item_id' => $src['pos_order_return_item_id'] ?? null,
                    'item_id' => (int) $row->item_id, 'quantity' => (string) $row->quantity, 'unit_cost' => (string) $row->unit_cost, 'total_cost' => (string) $row->total_cost, 'costing_method' => $row->costing_method, 'ledger_id' => (int) $row->id];
            }
            $doc->forceFill(['result' => $result])->save();
            // COGS / inventory asset → SolaCount through the existing bridge, exactly once.
            $this->outbox->record($isReturn ? 'pos_sale_return.posted' : 'pos_sale.posted', $doc, 'pos_sale_consumption', $doc->reference, (string) $doc->transaction_date->toDateString());

            return $doc;
        });

        return ['data' => $doc->result, 'replayed' => false];
    }
}
