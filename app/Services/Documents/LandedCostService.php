<?php

namespace App\Services\Documents;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\Item;
use App\Models\Tenant\LandedCost;
use App\Models\Tenant\LandedCostCharge;
use App\Models\Tenant\LandedCostComponent;
use App\Models\Tenant\LandedCostLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\OrganizationAccountRequirements;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\PurchaseCostAdjustmentPlanner;
use App\Services\Stock\PurchaseCostAdjustmentService;
use App\Services\Stock\Support\Decimal;
use App\Services\Stock\Support\LandedCostProvenance;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Landed costs (freight, duty, insurance) applied to posted receipt lines.
 *
 * - The document total is converted to base at the document rate (Finance
 *   convention: 1 base = exchange_rate transaction units) and allocated to the
 *   selected receipt lines by value, quantity or weight (largest remainder, so
 *   the lines always sum exactly to the total).
 * - Each line amount is split through the receipt's actual disposition by the
 *   purchase cost-adjustment planner and applied by the same revaluation code:
 *   on-hand share → FIFO layer / AVG balance; consumed share → cogs
 *   (shipments) or adjustment_loss (adjustments, counts). Transfers and other
 *   dispositions are refused, exactly as for supplier price deltas.
 * - Connected: one `landed_cost.posted` outbox event (Dr inventory + Dr
 *   cogs/adjustment_loss / Cr landed_cost_clearing). Standalone: no event.
 * - Reverse: an InventoryReversal that undoes the revaluation exactly; refused
 *   once the revalued stock has moved, because the inverse would no longer be exact.
 */
class LandedCostService
{
    public const METHODS = ['value', 'quantity', 'weight'];

    public const CHARGE_TYPES = ['freight', 'duty', 'insurance', 'other'];

    public function __construct(
        private OrganizationContext $context,
        private PurchaseCostAdjustmentPlanner $planner,
        private PurchaseCostAdjustmentService $valuation,
        private IntegrationOutboxService $outbox,
        private InventoryReversalService $reversals,
    ) {}

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * @param array{landed_cost_date?:string,allocation_method:string,currency_code?:?string,exchange_rate?:mixed,supplier_reference?:?string,notes?:?string} $attributes
     * @param list<array{charge_type:string,description?:?string,amount:mixed}> $charges
     * @param list<int> $receiptLineIds
     */
    public function createDraft(array $attributes, array $charges, array $receiptLineIds): LandedCost
    {
        return DB::connection($this->connection())->transaction(function () use ($attributes, $charges, $receiptLineIds) {
            $orgId = $this->context->idOrFail();
            $doc = LandedCost::query()->create([
                'landed_cost_number' => DocumentNumber::next('LC', LandedCost::class, 'landed_cost_number', $orgId, $this->connection()),
                'landed_cost_date' => $attributes['landed_cost_date'] ?? now()->toDateString(),
                'status' => 'draft',
                'allocation_method' => $attributes['allocation_method'],
                'currency_code' => 'XXX',
                'created_by' => auth()->id(),
            ]);
            $this->fillDraft($doc, $attributes, $charges, $receiptLineIds);

            return $doc->fresh(['charges', 'lines']);
        });
    }

    public function updateDraft(LandedCost $doc, array $attributes, array $charges, array $receiptLineIds): LandedCost
    {
        return DB::connection($this->connection())->transaction(function () use ($doc, $attributes, $charges, $receiptLineIds) {
            $doc = LandedCost::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status !== 'draft') {
                $this->fail('not_draft');
            }
            $this->fillDraft($doc, $attributes, $charges, $receiptLineIds);

            return $doc->fresh(['charges', 'lines']);
        });
    }

    private function fillDraft(LandedCost $doc, array $attributes, array $charges, array $receiptLineIds): void
    {
        $method = (string) ($attributes['allocation_method'] ?? '');
        if (! in_array($method, self::METHODS, true)) {
            $this->fail('method_invalid');
        }
        [$currency, $base, $rate] = $this->currencyContract($attributes['currency_code'] ?? null, $attributes['exchange_rate'] ?? null);
        if ($charges === []) {
            $this->fail('no_charges');
        }
        $receiptLineIds = array_values(array_map('intval', $receiptLineIds));
        if ($receiptLineIds === []) {
            $this->fail('no_lines');
        }
        if (count($receiptLineIds) !== count(array_unique($receiptLineIds))) {
            $this->fail('duplicate_line');
        }

        LandedCostCharge::query()->where('landed_cost_id', $doc->id)->delete();
        LandedCostLine::query()->where('landed_cost_id', $doc->id)->delete();
        $total = '0';
        $totalBase = '0';
        foreach ($charges as $charge) {
            $type = (string) ($charge['charge_type'] ?? '');
            $amount = trim((string) ($charge['amount'] ?? ''));
            if (! in_array($type, self::CHARGE_TYPES, true) || ! is_numeric($amount) || ! Decimal::gt($amount, '0')) {
                $this->fail('charge_invalid');
            }
            $amount = Decimal::round($amount, 4);
            $baseAmount = $this->toBase($amount, $rate);
            LandedCostCharge::query()->create([
                'landed_cost_id' => $doc->id, 'charge_type' => $type,
                'description' => isset($charge['description']) ? mb_substr(trim((string) $charge['description']), 0, 255) ?: null : null,
                'amount' => $amount, 'base_amount' => $baseAmount,
            ]);
            $total = Decimal::add($total, $amount, 4);
            $totalBase = Decimal::add($totalBase, $baseAmount, 2);
        }
        foreach ($receiptLineIds as $lineId) {
            $snapshot = $this->receiptLineSnapshot($lineId);
            LandedCostLine::query()->create(['landed_cost_id' => $doc->id] + $snapshot);
        }
        $doc->fill([
            'landed_cost_date' => $attributes['landed_cost_date'] ?? $doc->landed_cost_date ?? now()->toDateString(),
            'allocation_method' => $method,
            'currency_code' => $currency,
            'base_currency_code' => $base,
            'exchange_rate' => $rate,
            'supplier_reference' => isset($attributes['supplier_reference']) ? (mb_substr(trim((string) $attributes['supplier_reference']), 0, 120) ?: null) : $doc->supplier_reference,
            'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $doc->notes,
            'total_amount' => Decimal::round($total, 4),
            'total_base_amount' => Decimal::money($totalBase),
        ])->save();
        // Validates the basis now (e.g. a weight method without item weights),
        // so a draft that can never post is reported when it is saved.
        $this->storeAllocations($doc->fresh('lines'));
    }

    /** Draft view: allocation and the on-hand / consumed split, without writing anything. */
    public function preview(LandedCost $doc): array
    {
        $doc->loadMissing('lines');
        try {
            $plan = $this->plan($doc);
        } catch (ValidationException $e) {
            return ['ready' => false, 'message' => collect($e->errors())->flatten()->first(), 'lines' => []];
        }

        return ['ready' => true, 'message' => null, 'lines' => collect($plan['lines'])->map(fn (array $line) => [
            'landed_cost_line_id' => $line['line']->id,
            'allocated_base_amount' => $line['allocated'],
            'inventory_base_amount' => $line['totals']['inventory_asset'],
            'cogs_base_amount' => $line['totals']['cogs'],
            'adjustment_loss_base_amount' => $line['totals']['adjustment_loss'],
        ])->values()->all(), 'totals' => $plan['totals']];
    }

    public function post(LandedCost $doc): LandedCost
    {
        return DB::connection($this->connection())->transaction(function () use ($doc) {
            $doc = LandedCost::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status === 'posted' || $doc->status === 'reversed') {
                return $doc; // idempotent
            }
            if ($doc->status !== 'draft') {
                $this->fail('not_draft');
            }
            $orgId = (int) $doc->organization_id;
            // Every stock lock is taken BEFORE the first non-locking read of this
            // transaction. Under REPEATABLE READ the read view is fixed by the first
            // plain SELECT; taken earlier, it would hide a shipment that committed
            // while this post waited for the item lock, and the plan would treat sold
            // stock as on hand. The lines are read with a locking read for the same
            // reason, and the lock order (items, then balances and layers) is the one
            // StockLedgerService uses, so there is no deadlock inversion.
            $locked = LandedCostLine::query()->where('landed_cost_id', $doc->id)->orderBy('id')->lockForUpdate()->get(['id', 'item_id', 'warehouse_id']);
            $pairs = $locked->map(fn ($l) => [(int) $l->item_id, (int) $l->warehouse_id])->all();
            $this->lockStock($locked->pluck('item_id')->all(), $pairs);
            $this->assertConnectedReady($orgId, 'landed_cost.posted');
            // The connection may have changed since the draft was saved.
            $this->currencyContract($doc->currency_code, (string) $doc->exchange_rate, $doc->base_currency_code, true);
            $doc->load('lines');
            $lockedPairs = array_flip(array_map(fn (array $p) => $p[0].':'.$p[1], $pairs));
            foreach ($doc->lines as $line) {
                $snapshot = $this->receiptLineSnapshot((int) $line->goods_receipt_line_id);
                if (! isset($lockedPairs[$snapshot['item_id'].':'.$snapshot['warehouse_id']])) {
                    $this->fail('receipt_line_unavailable', ['line' => (int) $line->goods_receipt_line_id]);
                }
                $line->fill($snapshot)->save();
            }
            $plan = $this->plan($doc->fresh('lines'));

            $inventory = '0';
            $consumed = '0';
            foreach ($plan['lines'] as $entry) {
                $line = $entry['line'];
                foreach ($entry['components'] as $component) {
                    LandedCostComponent::query()->create($component + [
                        'landed_cost_id' => $doc->id, 'landed_cost_line_id' => $line->id,
                    ]);
                }
                $lineConsumed = Decimal::add($entry['totals']['cogs'], $entry['totals']['adjustment_loss'], 2);
                $line->fill([
                    'allocated_base_amount' => $entry['allocated'],
                    'inventory_base_amount' => $entry['totals']['inventory_asset'],
                    'consumed_base_amount' => $lineConsumed,
                ])->save();
                $inventory = Decimal::add($inventory, $entry['totals']['inventory_asset'], 2);
                $consumed = Decimal::add($consumed, $lineConsumed, 2);
            }
            $components = LandedCostComponent::query()->where('landed_cost_id', $doc->id)->orderBy('id')->get();
            $this->valuation->revalueInventoryComponents($orgId, $components, false);

            $doc->status = 'posted';
            $doc->posted_at = now();
            $doc->posted_by = auth()->id();
            $doc->posted_guard_key = 'landed_cost:'.$doc->id.':post';
            $doc->inventory_base_amount = Decimal::money($inventory);
            $doc->consumed_base_amount = Decimal::money($consumed);
            $doc->ledger_watermark = (int) StockLedger::query()->withoutGlobalScope('warehouse_access')
                ->where('organization_id', $orgId)->max('id');
            $doc->markSystemTransition()->save();

            $event = $this->outbox->record('landed_cost.posted', $doc, 'landed_cost', $doc->landed_cost_number, $doc->landed_cost_date?->toDateString());
            if ($event) {
                $doc->event_uuid = $event->event_uuid;
                $doc->markSystemTransition()->save();
            }
            $this->audit($doc, 'landed_cost.posted', ['total_base_amount' => (string) $doc->total_base_amount,
                'inventory_base_amount' => (string) $doc->inventory_base_amount, 'consumed_base_amount' => (string) $doc->consumed_base_amount]);

            return $doc->fresh(['charges', 'lines', 'components']);
        });
    }

    public function reverse(LandedCost $doc, string $reason): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($doc, $reason) {
            $doc = LandedCost::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status === 'reversed' && $doc->reversal_id) {
                return InventoryReversal::query()->findOrFail($doc->reversal_id);
            }
            if ($doc->status !== 'posted') {
                $this->fail('not_posted');
            }
            $orgId = (int) $doc->organization_id;
            // Lock first (see post()): a plain read before the stock locks would
            // fix a read view that hides a movement committed while this reversal
            // waited, so the "has the stock moved" refusal would pass wrongly.
            // The pairs come from a locking read of the document LINES, not the
            // components: a locking scan of the components index gap-locks the range
            // a concurrent post() of a newer document inserts its components into
            // while it already holds the item lock (reverse waits for the item, post
            // waits for the gap: deadlock). Nothing inserts lines while holding item
            // locks, and every component shares its line's item and receipt
            // warehouse, so the line pairs cover every inventory_asset component.
            $locked = LandedCostLine::query()->where('landed_cost_id', $doc->id)->orderBy('id')->lockForUpdate()
                ->get(['id', 'item_id', 'warehouse_id']);
            $this->lockStock($locked->pluck('item_id')->all(), $locked->map(fn ($l) => [(int) $l->item_id, (int) $l->warehouse_id])->all());
            $this->assertConnectedReady($orgId, 'landed_cost.reversed');
            $components = LandedCostComponent::query()->where('landed_cost_id', $doc->id)->orderBy('id')->get();
            $inventory = $components->where('destination_role', 'inventory_asset');
            $lockedPairs = $locked->mapWithKeys(fn ($l) => [$l->item_id.':'.$l->warehouse_id => true]);
            if ($inventory->contains(fn ($c) => ! $lockedPairs->has($c->item_id.':'.$c->warehouse_id))) {
                $this->fail('reverse_moved'); // fail closed: never revalue an unlocked pair
            }
            $this->assertExactlyReversible($doc, $inventory);

            $reversal = $this->reversals->reverseLandedCost($doc, trim($reason) !== '' ? $reason : __('inventory.landed_cost.default_reason'),
                fn () => $this->valuation->revalueInventoryComponents($orgId, $components, true));

            $doc->status = 'reversed';
            $doc->reversal_id = $reversal->id;
            $doc->reversed_at = now();
            $doc->reversed_by = auth()->id();
            $doc->markSystemTransition()->save();
            $this->audit($doc, 'landed_cost.reversed', ['reversal_id' => $reversal->id, 'reason' => $reversal->reason]);

            return $reversal->fresh();
        });
    }

    /**
     * Largest-remainder allocation of a 2-decimal total by non-negative bases.
     * Lines always sum exactly to the total; ties go to the earlier line.
     *
     * @param array<int|string,string> $bases
     * @return array<int|string,string>
     */
    public static function allocate(string $total, array $bases): array
    {
        $sum = '0';
        foreach ($bases as $basis) {
            if (Decimal::lt((string) $basis, '0')) {
                throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.basis_zero')]);
            }
            $sum = Decimal::add($sum, (string) $basis);
        }
        if (! Decimal::gt($sum, '0')) {
            throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.basis_zero')]);
        }
        $cents = bcmul(Decimal::money($total), '100', 0);
        $floors = [];
        $fractions = [];
        $assigned = '0';
        $order = 0;
        foreach ($bases as $key => $basis) {
            $share = bcdiv(bcmul($cents, (string) $basis, 12), $sum, 12);
            $floor = bcadd($share, '0', 0);
            $floors[$key] = $floor;
            $fractions[] = [$key, bcsub($share, $floor, 12), $order++];
            $assigned = bcadd($assigned, $floor, 0);
        }
        usort($fractions, fn ($a, $b) => bccomp($b[1], $a[1], 12) ?: $a[2] <=> $b[2]);
        $left = (int) bcsub($cents, $assigned, 0);
        foreach ($fractions as [$key]) {
            if ($left <= 0) {
                break;
            }
            $floors[$key] = bcadd($floors[$key], '1', 0);
            $left--;
        }

        return array_map(fn (string $c) => bcdiv($c, '100', 2), $floors);
    }

    /** Transaction amount → base (2 dp): base = amount ÷ rate. */
    public function toBase(string $amount, string $rate): string
    {
        return Decimal::money(Decimal::div($amount, $rate, 10));
    }

    /** @return array{0:string,1:?string,2:string} currency, base currency, rate */
    private function currencyContract(?string $currency, mixed $rate, ?string $expectedBase = null, bool $posting = false): array
    {
        $orgId = $this->context->idOrFail();
        $contract = app(FinanceBaseValuation::class)->contract($orgId);
        $base = $contract ? (string) ($contract['base_currency_code'] ?? '') : null;
        $currency = strtoupper(trim((string) ($currency ?? '')));
        if ($currency === '' && $base) {
            $currency = $base;
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->fail('currency_invalid');
        }
        $rate = trim((string) ($rate ?? ''));
        $rate = $rate === '' ? '1' : $rate;
        if (! is_numeric($rate) || ! Decimal::gt($rate, '0')) {
            $this->fail('rate_invalid');
        }
        $rate = Decimal::round($rate, 12);
        if ($base !== null) {
            $setting = IntegrationSetting::query()->where('organization_id', $orgId)->where('integration', IntegrationEvents::INTEGRATION)->first();
            $enabled = (array) data_get($setting?->meta, 'finance_currency_contract.enabled_currency_codes', []);
            if ($currency !== $base && ! in_array($currency, $enabled, true)) {
                $this->fail('currency_disabled', ['currency' => $currency]);
            }
        }
        // A draft saved under another connection state must be saved again.
        if ($posting && $expectedBase !== $base) {
            $this->fail('currency_invalid');
        }
        if (($base !== null && $currency === $base) && Decimal::cmp($rate, '1', 12) !== 0) {
            $this->fail('base_rate_must_be_one');
        }

        return [$currency, $base, rtrim(rtrim($rate, '0'), '.') ?: '1'];
    }

    /** Line facts from the posted receipt; refuses lines that cannot carry a landed cost. */
    private function receiptLineSnapshot(int $lineId): array
    {
        $line = GoodsReceiptLine::query()->find($lineId);
        $receipt = $line ? GoodsReceipt::query()->find($line->goods_receipt_id) : null; // warehouse-scoped
        if (! $line || ! $receipt || $receipt->status !== 'posted' || $receipt->reversal_id) {
            $this->fail('receipt_line_unavailable', ['line' => $lineId]);
        }
        $item = Item::query()->findOrFail($line->item_id);
        if ($item->effectiveCostingMethod() === 'standard') {
            $this->fail('standard_costing', ['sku' => $item->sku]);
        }
        $rows = $this->receiptRows($receipt, (int) $line->id);
        if ($rows->isEmpty()) {
            $this->fail('receipt_line_unavailable', ['line' => $lineId]);
        }
        $qty = $rows->reduce(fn (string $c, StockLedger $r) => Decimal::add($c, (string) $r->quantity), '0');
        $value = $rows->reduce(fn (string $c, StockLedger $r) => Decimal::add($c, (string) $r->total_cost), '0');
        $weight = Decimal::gt((string) ($item->weight ?? '0'), '0') ? Decimal::qty(Decimal::mul($qty, (string) $item->weight)) : null;

        return ['goods_receipt_id' => $receipt->id, 'goods_receipt_line_id' => $line->id, 'item_id' => $item->id,
            'warehouse_id' => $receipt->warehouse_id, 'quantity' => Decimal::qty($qty), 'receipt_value' => Decimal::money($value),
            'weight' => $weight];
    }

    /** @return Collection<int,StockLedger> */
    private function receiptRows(GoodsReceipt $receipt, int $lineId): Collection
    {
        return StockLedger::query()->where('organization_id', $receipt->organization_id)
            ->where('source_type', GoodsReceipt::class)->where('source_id', $receipt->id)
            ->where('source_line_id', $lineId)->where('direction', 'in')
            ->where('quantity', '>', 0)->orderBy('id')->get();
    }

    private function storeAllocations(LandedCost $doc): void
    {
        $allocations = $this->allocations($doc);
        foreach ($doc->lines as $line) {
            $line->fill(['allocated_base_amount' => $allocations[$line->id]])->save();
        }
    }

    /** @return array<int,string> line id => base amount */
    private function allocations(LandedCost $doc): array
    {
        $bases = [];
        foreach ($doc->lines as $line) {
            $bases[$line->id] = match ($doc->allocation_method) {
                'quantity' => (string) $line->quantity,
                'weight' => $line->weight !== null && Decimal::gt((string) $line->weight, '0')
                    ? (string) $line->weight
                    : $this->fail('weight_missing', ['item' => (string) (Item::query()->whereKey($line->item_id)->value('sku') ?? $line->item_id)]),
                default => (string) $line->receipt_value,
            };
        }

        return self::allocate((string) $doc->total_base_amount, $bases);
    }

    /**
     * @return array{lines:list<array{line:LandedCostLine,allocated:string,components:list<array>,totals:array<string,string>}>,totals:array<string,string>}
     */
    private function plan(LandedCost $doc): array
    {
        $allocations = $this->allocations($doc);
        $result = [];
        $grand = ['inventory_asset' => '0.00', 'cogs' => '0.00', 'adjustment_loss' => '0.00'];
        foreach ($doc->lines as $line) {
            $allocated = $allocations[$line->id];
            $receipt = GoodsReceipt::query()->findOrFail($line->goods_receipt_id);
            $rows = $this->receiptRows($receipt, (int) $line->goods_receipt_line_id);
            $rowAmounts = self::allocate($allocated, $rows->mapWithKeys(fn (StockLedger $r) => [$r->id => (string) $r->quantity])->all());
            $components = [];
            foreach ($rows as $row) {
                if (! Decimal::gt($rowAmounts[$row->id], '0')) {
                    continue;
                }
                $source = LandedCostProvenance::forReceiptLedger((int) $doc->id, (int) $line->id, $row);
                try {
                    $parts = $this->planner->planLandedCost($source, $row, $rowAmounts[$row->id]);
                } catch (ValidationException $e) {
                    $this->failDisposition($e);
                }
                foreach ($parts as $part) {
                    $layerId = $part['destination_role'] === 'inventory_asset' ? data_get($part, 'provenance.cost_layer_id') : null;
                    if ($layerId) {
                        // Evidence for an exact reversal: the layer must be untouched since posting.
                        $part['provenance']['layer_remaining_at_post'] = Decimal::qty((string) CostLayer::query()->whereKey($layerId)->value('remaining_qty'));
                    }
                    $part['provenance']['landed_cost_receipt_ledger_id'] = (int) $row->id;
                    $components[] = $part;
                }
            }
            $components = $this->rounded($components, $allocated);
            $totals = ['inventory_asset' => '0.00', 'cogs' => '0.00', 'adjustment_loss' => '0.00'];
            foreach ($components as $component) {
                $totals[$component['destination_role']] = Decimal::add($totals[$component['destination_role']], $component['posted_base_amount'], 2);
            }
            foreach ($totals as $role => $amount) {
                $grand[$role] = Decimal::add($grand[$role], $amount, 2);
            }
            $result[] = ['line' => $line, 'allocated' => $allocated, 'components' => $components, 'totals' => $totals];
        }

        return ['lines' => $result, 'totals' => $grand];
    }

    /** Round each component to money and plug the bounded residual so the line total is exact. */
    private function rounded(array $components, string $allocated): array
    {
        $posted = '0';
        foreach ($components as $i => $component) {
            if (! in_array($component['destination_role'], ['inventory_asset', 'cogs', 'adjustment_loss'], true)) {
                $this->fail('disposition_unsupported');
            }
            unset($components[$i]['provenance']['restored_fifo_layer_slices']);
            $components[$i]['posted_base_amount'] = Decimal::money($component['exact_base_amount']);
            $posted = Decimal::add($posted, $components[$i]['posted_base_amount'], 2);
        }
        $residual = Decimal::sub($allocated, $posted, 2);
        if (! Decimal::isZero($residual, 2)) {
            $bound = Decimal::mul((string) max(1, count($components)), '0.005', 3);
            if ($components === [] || Decimal::gt(ltrim($residual, '-'), $bound, 3)) {
                $this->fail('rounding_bound');
            }
            $target = array_key_first($components);
            foreach ($components as $i => $component) {
                if (Decimal::gt($component['posted_base_amount'], $components[$target]['posted_base_amount'], 2)) {
                    $target = $i;
                }
            }
            $components[$target]['posted_base_amount'] = Decimal::add($components[$target]['posted_base_amount'], $residual, 2);
        }

        return array_values(array_filter($components, fn (array $c) => ! Decimal::isZero($c['posted_base_amount'], 2)));
    }

    /**
     * Lock items (sorted, as StockLedgerService does), then every balance and
     * FIFO layer of the touched item/warehouse pairs. Only locking reads happen
     * here; the valuation-hold check (a plain read) runs after every lock is held.
     */
    private function lockStock(array $itemIds, array $pairs): void
    {
        $holds = app(PurchaseValuationHoldService::class);
        $holds->lockItems($itemIds);
        $unique = [];
        foreach ($pairs as [$itemId, $warehouseId]) {
            $unique[$itemId.':'.$warehouseId] = [(int) $itemId, (int) $warehouseId];
        }
        ksort($unique, SORT_NATURAL);
        foreach ($unique as [$itemId, $warehouseId]) {
            StockBalance::query()->withoutGlobalScope('warehouse_access')->where('item_id', $itemId)
                ->where('warehouse_id', $warehouseId)->orderBy('id')->lockForUpdate()->get(['id']);
            CostLayer::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
                ->orderBy('id')->lockForUpdate()->get(['id']);
        }
        foreach ($unique as [$itemId, $warehouseId]) {
            $holds->assertMovable($itemId, $warehouseId);
        }
    }

    /**
     * The reversal must take back exactly what the posting added. Once revalued
     * stock has left (shipment, adjustment, transfer) part of the landed cost is
     * already in COGS through that movement, so an inverse would be wrong.
     */
    private function assertExactlyReversible(LandedCost $doc, Collection $inventory): void
    {
        foreach ($inventory->unique(fn ($c) => $c->item_id.':'.$c->warehouse_id) as $component) {
            $moved = StockLedger::query()->withoutGlobalScope('warehouse_access')
                ->where('organization_id', $doc->organization_id)->where('id', '>', (int) $doc->ledger_watermark)
                ->where('item_id', $component->item_id)->where('warehouse_id', $component->warehouse_id)
                ->exists();
            if ($moved) {
                $this->fail('reverse_moved');
            }
        }
        foreach ($inventory as $component) {
            $layerId = data_get($component->provenance, 'cost_layer_id');
            if ($layerId && Decimal::cmp((string) CostLayer::query()->whereKey($layerId)->value('remaining_qty'),
                (string) data_get($component->provenance, 'layer_remaining_at_post', '-1'), 4) !== 0) {
                $this->fail('reverse_moved');
            }
        }
    }

    /** Connected organizations need the reviewed landed-cost workflow and its account roles. */
    private function assertConnectedReady(int $orgId, string $operation): void
    {
        try {
            app(OrganizationAccountRequirements::class)->assertOperationReady($orgId, $operation);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            // Posting readiness, not availability: drafts stay possible, and the
            // refusal names the one setup action (choose the clearing account).
            if (isset($errors['workflow'])) {
                throw LandedCostSetupRequired::withMessages(['landed_cost' => __('inventory.landed_cost.connection_not_enabled')]);
            }
            if (isset($errors['account_mappings'])) {
                $missing = trim((string) str_replace('required_account_mappings_missing:', '', (string) collect($errors['account_mappings'])->first()));
                throw LandedCostSetupRequired::withMessages(['landed_cost' => __('inventory.landed_cost.mapping_missing', ['roles' => $missing])]);
            }
            throw $e;
        }
    }

    private function failDisposition(ValidationException $e): never
    {
        $message = (string) collect($e->errors())->flatten()->first();
        if (str_contains($message, 'Transferred cost')) {
            $this->fail('disposition_transfer');
        }
        if (str_contains($message, 'no reviewed accounting destination')) {
            $this->fail('disposition_unsupported');
        }
        $this->fail('provenance_review');
    }

    private function audit(LandedCost $doc, string $action, array $after): void
    {
        InventoryAuditLog::create([
            'organization_id' => $doc->organization_id,
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'landed_cost',
            'entity_id' => $doc->id,
            'after' => ['landed_cost_number' => $doc->landed_cost_number] + $after,
            'created_at' => now(),
        ]);
    }

    private function fail(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['landed_cost' => __('inventory.landed_cost.'.$key, $replace)]);
    }
}
