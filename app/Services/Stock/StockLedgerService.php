<?php

namespace App\Services\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\CostLayerConsumption;
use App\Models\Tenant\HistoricalFifoCorrection;
use App\Models\Tenant\HistoricalFifoPlan;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\Lot;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Integration\WorkflowValidationService;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\Historical\HistoricalFifoReviewService;
use App\Services\Stock\Historical\HistoricalFifoSourceOwnership;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * THE ONLY class permitted to write stock_ledger, stock_balances, and cost layer
 * remaining quantities. Everything else (documents, controllers, jobs) calls
 * post()/reverse(); nothing else mutates stock.
 *
 * post() guarantees, in ONE transaction:
 *   1. begin transaction
 *   2. resolve + lockForUpdate the affected balance rows
 *   3. validate organization ownership
 *   4. validate item / warehouse / bin / lot / serial / unit compatibility
 *   5. enforce negative-stock policy
 *   6. enforce serial uniqueness + quantity rules
 *   7. apply costing (CostingEngine)
 *   8. append immutable ledger rows
 *   9. update stock_balances in the same transaction
 *  10. update FIFO cost layers when applicable
 *  11. write audit logs
 *  12. enforce idempotency (unique idempotency_key; safe retry returns prior rows)
 *  13. reversal supported via opposite ledger rows
 *  14. NEVER updates/deletes existing ledger rows
 */
class StockLedgerService
{
    public function __construct(
        private OrganizationContext $context,
        private CostingEngine $costing,
    ) {}

    /** Tenant connection name. */
    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    /**
     * Post a batch of movements atomically under a shared idempotency namespace.
     * If the namespace was already posted, returns the existing ledger rows
     * (idempotent retry — no duplication).
     *
     * @param  StockMovement[]  $movements
     * @param  string  $idempotencyNamespace  e.g. "opening_stock:42:post"
     * @return StockLedger[]
     */
    public function post(array $movements, string $idempotencyNamespace, array $audit = []): array
    {
        $orgId = $this->context->idOrFail();
        $connection = $this->connection();

        return DB::connection($connection)->transaction(function () use ($movements, $idempotencyNamespace, $orgId, $audit) {
            // Idempotency: if any row for this namespace exists, this batch already ran.
            $existing = StockLedger::query()
                ->where('idempotency_key', 'like', $idempotencyNamespace.'#%')
                ->orderBy('id')
                ->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            app(PurchaseValuationHoldService::class)->lockItems(array_map(fn ($movement) => $movement->itemId, $movements));

            $created = [];
            $index = 0;
            foreach ($movements as $movement) {
                $created[] = $this->applyMovement($movement, $orgId, $idempotencyNamespace, $index);
                $index++;
            }

            $this->writeAudit($orgId, $audit, $idempotencyNamespace, count($created));

            return $created;
        });
    }

    /**
     * Reverse a previously-posted namespace by appending OPPOSITE movements.
     * Never edits/deletes the original rows. Idempotent on the reversal namespace.
     *
     * @return StockLedger[]
     */
    public function reverse(
        string $originalNamespace,
        string $reversalNamespace,
        array $audit = [],
        ?string $reversalSourceType = null,
        ?int $reversalSourceId = null,
    ): array {
        $affected = StockLedger::query()->where('idempotency_key', 'like', $originalNamespace.'#%')->pluck('id')->all();
        foreach ((Schema::connection($this->connection())->hasTable('historical_fifo_plans') ? HistoricalFifoPlan::query()->where('status', 'applied')->get() : []) as $revision) {
            $refs = [];
            foreach ($revision->plan['events'] as $event) {
                foreach ($event['existing_stock_segments'] ?? [] as $segment) {
                    $refs[] = (int) $segment['ledger_id'];
                }
            }
            if (array_intersect($affected, $refs)) {
                throw new RuntimeException('This historical projection requires a reviewed compensating correction; ordinary reversal would restore stale original costs.');
            }
        }
        $orgId = $this->context->idOrFail();
        $connection = $this->connection();

        return DB::connection($connection)->transaction(function () use ($originalNamespace, $reversalNamespace, $orgId, $audit, $reversalSourceType, $reversalSourceId) {
            $alreadyReversed = StockLedger::query()
                ->where('idempotency_key', 'like', $reversalNamespace.'#%')
                ->orderBy('id')
                ->get();
            if ($alreadyReversed->isNotEmpty()) {
                return $alreadyReversed->all();
            }

            $originals = StockLedger::query()
                ->where('idempotency_key', 'like', $originalNamespace.'#%')
                ->orderBy('id')
                ->get();

            app(PurchaseValuationHoldService::class)->lockItems($originals->pluck('item_id')->all());

            foreach ($originals as $original) {
                app(PurchaseValuationHoldService::class)->assertMovable($original->item_id, $original->warehouse_id);
            }

            if ($originals->isEmpty()) {
                throw new RuntimeException("Cannot reverse: no posted ledger rows for '{$originalNamespace}'.");
            }

            $created = [];
            $index = 0;
            // Reverse in REVERSE order so balances/layers unwind cleanly (LIFO of
            // the original sequence).
            foreach ($originals->reverse()->values() as $orig) {
                // Every reversal preserves the exact original ledger value. FIFO
                // additionally restores/removes the precise source layers.
                $created[] = $this->applyExactReversal(
                    $orig,
                    $reversalNamespace,
                    $index,
                    $reversalSourceType,
                    $reversalSourceId,
                );
                $index++;
            }

            $this->writeAudit($orgId, $audit + ['action' => 'reverse'], $reversalNamespace, count($created));

            return $created;
        });
    }

    /**
     * Apply a reviewed complete historical projection. Original ledger rows and
     * superseded consumption rows are retained. Missing quantity and cost-only
     * revisions are appended; no ordinary movement guard is relaxed.
     */
    public function applyHistoricalFifo(HistoricalFifoPlan $review): array
    {
        $org = $this->context->idOrFail();
        if ((int) $review->organization_id !== $org) {
            throw new RuntimeException('Historical FIFO cross-organization review');
        }
        if (($review->plan['blocked_items'] ?? []) !== []) {
            throw new RuntimeException('Historical FIFO unresolved origin evidence');
        }

        return DB::connection($this->connection())->transaction(function () use ($org, $review) {
            $review = HistoricalFifoPlan::query()->where('organization_id', $org)->lockForUpdate()->findOrFail($review->id);
            if ($review->status === 'applied') {
                return (array) $review->result;
            }
            $plan = $review->plan;
            $pools = array_map(fn ($key) => array_map('intval', explode(':', $key)), array_keys($plan['layers']));
            app(PurchaseValuationHoldService::class)->lockItems(array_column($pools, 0));
            foreach ($pools as [$itemId, $warehouseId]) {
                app(PurchaseValuationHoldService::class)->assertMovable($itemId, $warehouseId);
            }

            if ($review->status !== 'reviewed' || $plan['blocked_items'] !== []) {
                throw new RuntimeException('Historical FIFO unresolved origin evidence');
            }
            $withoutHash = $plan;
            unset($withoutHash['plan_sha256']);
            if (! hash_equals($review->plan_sha256, SolaStockJournalContract::payloadHash($withoutHash))) {
                throw new RuntimeException('Historical FIFO reviewed plan changed');
            }
            $ledger = app(HistoricalFifoReviewService::class)->ledgerForPlan($org, $plan, true);
            if (! hash_equals($review->ledger_sha256, SolaStockJournalContract::payloadHash(app(HistoricalFifoReviewService::class)->projectionSnapshot($org, $plan, true)))) {
                throw new RuntimeException('Historical FIFO ledger changed since review');
            }
            $native = $ledger->keyBy('id');
            $covered = $originLayers = $balances = $running = $eventRows = $created = [];
            foreach (array_keys($plan['layers']) as $key) {
                [$itemId, $warehouseId] = array_map('intval', explode(':', $key));
                $item = Item::query()->where('organization_id', $org)->findOrFail($itemId);
                Warehouse::query()->where('organization_id', $org)->findOrFail($warehouseId);
                if ($item->effectiveCostingMethod() !== 'fifo' || $item->item_type !== 'inventory' || $item->tracksLots() || $item->tracksSerials()) {
                    throw new RuntimeException('Historical FIFO unsupported item policy or tracking');
                }
                $balance = StockBalance::query()->where('organization_id', $org)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
                    ->whereNull('variant_id')->whereNull('lot_id')->whereNull('bin_id')->lockForUpdate()->first();
                if (! $balance || ! Decimal::isZero((string) $balance->reserved_qty)) {
                    throw new RuntimeException('Historical FIFO balance missing or reserved');
                }
                $balances[$key] = $balance;
                $running[$key] = ['quantity' => (string) $balance->on_hand_qty, 'value' => (string) $balance->total_value];
                foreach ($plan['opening_evidence'][$key]['layers'] as $index => $opening) {
                    $id = (int) ($opening['source_ledger_id'] ?? 0);
                    $row = $native->get($id);
                    if (! $row || $row->direction !== 'in' || (int) $row->item_id !== $itemId || (int) $row->warehouse_id !== $warehouseId
                        || Decimal::cmp((string) $row->quantity, $opening['quantity']) !== 0 || Decimal::cmp((string) $row->unit_cost, $opening['unit_cost']) !== 0) {
                        throw new RuntimeException('Historical FIFO opening must reference proved native opening movement');
                    }
                    $covered[$id] = ['quantity' => (string) $row->quantity, 'cost' => (string) $row->total_cost];
                    $originLayers[$key]['opening:'.$index] = $this->historicalLayerForRow($org, $row);
                }
            }
            // Bind every reviewed source portion to immutable native quantity/cost.
            foreach ($plan['events'] as $event) {
                $qty = $cost = '0';
                foreach ($event['existing_stock_segments'] ?? [] as $segment) {
                    $row = $native->get((int) $segment['ledger_id']);
                    $direction = $event['kind'] === 'out' ? 'out' : 'in';
                    if (! $row || $row->direction !== $direction || (int) $row->item_id !== (int) $event['stock_item_id'] || (int) $row->warehouse_id !== (int) $event['warehouse_id']
                        || substr((string) $row->moved_at, 0, 10) !== $event['date'] || $row->variant_id || $row->bin_id || $row->lot_id || $row->serial_id) {
                        throw new RuntimeException('Historical FIFO native source segment incompatible');
                    }
                    app(HistoricalFifoSourceOwnership::class)->assert($event, $row, $org, (string) $segment['quantity']);
                    if (! Decimal::gt((string) $segment['quantity'], '0') || Decimal::lt((string) $segment['cost'], '0')) {
                        throw new RuntimeException('Historical FIFO invalid source portion');
                    }
                    $id = (int) $row->id;
                    $covered[$id] = ['quantity' => Decimal::add($covered[$id]['quantity'] ?? '0', (string) $segment['quantity']), 'cost' => Decimal::add($covered[$id]['cost'] ?? '0', (string) $segment['cost'])];
                    $qty = Decimal::add($qty, (string) $segment['quantity']);
                    $cost = Decimal::add($cost, (string) $segment['cost']);
                    if ($event['kind'] === 'receipt') {
                        if (count($event['existing_stock_segments']) !== 1) {
                            throw new RuntimeException('Historical FIFO receipt requires one exact native line');
                        }
                        $originLayers[$event['stock_item_id'].':'.$event['warehouse_id']][$event['source_id']] = $this->historicalLayerForRow($org, $row);
                    }
                }
                foreach ($event['existing_cost_revision_segments'] ?? [] as $segment) {
                    $row = $native->get((int) $segment['ledger_id']);
                    if (! $row || ! Decimal::isZero((string) $row->quantity) || $row->source_type !== HistoricalFifoCorrection::class || isset($covered[$row->id])) {
                        throw new RuntimeException('Historical FIFO invalid or duplicate prior value revision');
                    }
                    app(HistoricalFifoSourceOwnership::class)->assert($event, $row, $org, '0');
                    if (Decimal::cmp((string) $row->total_cost, (string) $segment['cost']) !== 0) {
                        throw new RuntimeException('Historical FIFO prior value revision cost mismatch');
                    }
                    $covered[$row->id] = ['quantity' => '0', 'cost' => (string) $row->total_cost];
                    $positive = $row->direction === ($event['kind'] === 'out' ? 'out' : 'in');
                    $cost = $positive ? Decimal::add($cost, (string) $row->total_cost) : Decimal::sub($cost, (string) $row->total_cost);
                }
                if (Decimal::gt($qty, $event['quantity']) || Decimal::cmp($cost, $event['previous_posted_cost']) !== 0) {
                    throw new RuntimeException('Historical FIFO reviewed previous quantity/cost mismatch');
                }
                if ($event['kind'] === 'receipt' && (Decimal::cmp($qty, $event['quantity']) !== 0 || Decimal::cmp($cost, $event['reconstructed_cost']) !== 0)) {
                    throw new RuntimeException('Historical FIFO purchases require supported native receipt first');
                }
            }
            foreach ($native as $id => $row) {
                if (! isset($covered[$id]) || Decimal::cmp($covered[$id]['quantity'], (string) $row->quantity) !== 0 || Decimal::cmp($covered[$id]['cost'], (string) $row->total_cost) !== 0) {
                    throw new RuntimeException('Historical FIFO incomplete native ledger coverage');
                }
            }
            foreach ($plan['events'] as $event) {
                if ($event['kind'] === 'receipt') {
                    continue;
                }
                $key = $event['stock_item_id'].':'.$event['warehouse_id'];
                $existingQty = array_reduce($event['existing_stock_segments'] ?? [], fn ($sum, $segment) => Decimal::add($sum, (string) $segment['quantity']), '0');
                $missing = Decimal::qty(Decimal::sub($event['quantity'], $existingQty));
                // Mixed partial native coverage must supply an explicit reviewed cost partition.
                $missingCost = Decimal::isZero($missing) ? '0.00' : (Decimal::isZero($existingQty) ? $event['reconstructed_cost'] : ($event['missing_quantity_cost'] ?? null));
                if ($missingCost === null || Decimal::lt((string) $missingCost, '0') || Decimal::gt((string) $missingCost, $event['reconstructed_cost'])) {
                    throw new RuntimeException('Historical FIFO missing quantity cost partition unproven');
                }
                $deltaCost = Decimal::money(Decimal::sub(Decimal::sub($event['reconstructed_cost'], (string) $missingCost), $event['previous_posted_cost']));
                if (Decimal::isZero($missing) && Decimal::isZero($deltaCost)) {
                    continue;
                }
                if (! Str::isUuid($event['correction_uuid'] ?? '') || empty($event['unit_conversion']) || empty($event['finance_source_id']) || empty($event['finance_line_id'])) {
                    throw new RuntimeException('Historical FIFO correction identity/conversion missing');
                }
                $causal = ['version' => 'historical-fifo.v1', 'correction_uuid' => $event['correction_uuid'], 'batch_id' => $review->batch_id,
                    'plan_sha256' => $review->plan_sha256, 'source_id' => $event['finance_source_id'], 'planner_unique_id' => $event['source_id'],
                    'finance_document_id' => $event['finance_document_id'], 'finance_document_type' => $event['finance_document_type'],
                    'source_row' => (string) $event['source_row'], 'finance_line_id' => (int) $event['finance_line_id'], 'finance_line_ids' => $event['finance_line_ids'] ?? [(int) $event['finance_line_id']],
                    'stock_item_id' => (int) $event['stock_item_id'], 'warehouse_id' => (int) $event['warehouse_id'], 'business_date' => $event['date'],
                    'quantity' => Decimal::qty($event['quantity']), 'original_quantity_is_reference' => true, 'missing_quantity' => $missing,
                    'quantity_delta' => $event['kind'] === 'out' ? Decimal::qty(Decimal::sub('0', $missing)) : $missing, 'previous_posted_cost' => $event['previous_posted_cost'], 'reconstructed_cost' => $event['reconstructed_cost'],
                    'cogs_delta' => $event['cogs_delta'], 'inventory_value_delta' => Decimal::money(Decimal::sub('0', $event['cogs_delta'])),
                    'previous_stock_source_keys' => $event['previous_stock_source_keys'] ?? [], 'origin_evidence_references' => $event['origin_evidence_references'] ?? [],
                    'opening_evidence_sha256' => $plan['opening_evidence'][$key]['hash']];
                $doc = HistoricalFifoCorrection::query()->create(['organization_id' => $org, 'plan_id' => $review->id,
                    'correction_uuid' => $event['correction_uuid'], 'source_id' => $event['finance_source_id'], 'causal_payload' => $causal,
                    'conversion_snapshot' => $event['unit_conversion'], 'ledger_ids' => []]);
                $rows = [];
                if (! Decimal::isZero($missing)) {
                    $rows[] = $this->appendHistoricalFifoRow($org, $doc, $event, $missing, (string) $missingCost, $event['kind'] === 'out' ? 'out' : 'in', $running[$key], 'quantity');
                }
                if (! Decimal::isZero($deltaCost)) {
                    $direction = Decimal::gt($deltaCost, '0') ? ($event['kind'] === 'out' ? 'out' : 'in') : ($event['kind'] === 'out' ? 'in' : 'out');
                    $rows[] = $this->appendHistoricalFifoRow($org, $doc, $event, '0', ltrim($deltaCost, '-'), $direction, $running[$key], 'value');
                }
                $doc->ledger_ids = array_map(fn ($row) => (int) $row->id, $rows);
                $doc->save();
                $created[] = $doc;
                $eventRows[$event['source_id']] = $rows;
            }
            foreach ($plan['events'] as $event) {
                if ($event['kind'] !== 'return') {
                    continue;
                }
                $key = $event['stock_item_id'].':'.$event['warehouse_id'];
                $targets = array_map(fn ($segment) => ['id' => $segment['ledger_id'], 'remaining' => $segment['quantity']], $event['existing_stock_segments'] ?? []);
                foreach ($eventRows[$event['source_id']] ?? [] as $row) {
                    if (Decimal::gt((string) $row->quantity, '0')) {
                        $targets[] = ['id' => $row->id, 'remaining' => (string) $row->quantity];
                    }
                }
                CostLayer::query()->whereIn('source_ledger_id', array_column($targets, 'id'))->update(['superseded_fifo_correction_id' => $review->id]);
                $cursor = 0;
                foreach ($event['allocations'] as $allocation) {
                    $remaining = $allocation['quantity'];
                    while (! Decimal::isZero($remaining)) {
                        if (! isset($targets[$cursor])) {
                            throw new RuntimeException('Historical FIFO return layer quantity unproven');
                        }
                        $take = Decimal::lt($remaining, $targets[$cursor]['remaining']) ? $remaining : $targets[$cursor]['remaining'];
                        $origin = $event['source_id'].':'.$allocation['origin'];
                        if (isset($originLayers[$key][$origin])) {
                            throw new RuntimeException('Historical FIFO return allocation must have one native receipt segment per origin');
                        }
                        $originLayers[$key][$origin] = CostLayer::query()->create(['organization_id' => $org, 'item_id' => $event['stock_item_id'], 'warehouse_id' => $event['warehouse_id'],
                            'received_at' => $event['date'].' 00:00:00', 'unit_cost' => $allocation['unit_cost'], 'original_qty' => $take, 'remaining_qty' => $take, 'source_ledger_id' => $targets[$cursor]['id']]);
                        $remaining = Decimal::sub($remaining, $take);
                        $targets[$cursor]['remaining'] = Decimal::sub($targets[$cursor]['remaining'], $take);
                        if (Decimal::isZero($targets[$cursor]['remaining'])) {
                            $cursor++;
                        }
                    }
                }
            }
            // Replace only the derived allocation projection, retaining every old row.
            CostLayerConsumption::query()->whereIn('ledger_id', $native->keys()->all())->update(['superseded_fifo_correction_id' => $review->id]);
            foreach ($plan['events'] as $event) {
                if ($event['kind'] !== 'out') {
                    continue;
                }
                $key = $event['stock_item_id'].':'.$event['warehouse_id'];
                $targets = array_map(fn ($s) => ['id' => $s['ledger_id'], 'remaining' => $s['quantity']], $event['existing_stock_segments'] ?? []);
                foreach ($eventRows[$event['source_id']] ?? [] as $row) {
                    if (Decimal::gt((string) $row->quantity, '0')) {
                        $targets[] = ['id' => $row->id, 'remaining' => (string) $row->quantity];
                    }
                }
                $cursor = 0;
                foreach ($event['allocations'] as $allocation) {
                    $remaining = $allocation['quantity'];
                    while (! Decimal::isZero($remaining)) {
                        if (! isset($targets[$cursor])) {
                            throw new RuntimeException('Historical FIFO allocation does not cover native quantity');
                        }
                        $take = Decimal::lt($remaining, $targets[$cursor]['remaining']) ? $remaining : $targets[$cursor]['remaining'];
                        $layer = $originLayers[$key][$allocation['origin']] ?? null;
                        if (! $layer) {
                            throw new RuntimeException('Historical FIFO allocation origin layer missing');
                        }
                        CostLayerConsumption::query()->create(['organization_id' => $org, 'ledger_id' => $targets[$cursor]['id'], 'cost_layer_id' => $layer->id, 'qty' => $take, 'unit_cost' => $allocation['unit_cost']]);
                        $remaining = Decimal::sub($remaining, $take);
                        $targets[$cursor]['remaining'] = Decimal::sub($targets[$cursor]['remaining'], $take);
                        if (Decimal::isZero($targets[$cursor]['remaining'])) {
                            $cursor++;
                        }
                    }
                }
            }
            foreach ($plan['layers'] as $key => $layers) {
                $qty = '0';
                foreach ($layers as $projection) {
                    $layer = $originLayers[$key][$projection['origin']] ?? null;
                    if (! $layer) {
                        throw new RuntimeException('Historical FIFO return projection requires supported original-cost receipt layer');
                    }
                    if (Decimal::cmp((string) $layer->unit_cost, $projection['unit_cost']) !== 0) {
                        throw new RuntimeException('Historical FIFO native layer cost changed');
                    }
                    $layer->remaining_qty = $projection['remaining'];
                    $layer->save();
                    $qty = Decimal::add($qty, $projection['remaining']);
                }
                if (Decimal::cmp($qty, $running[$key]['quantity']) !== 0 || Decimal::lt($running[$key]['value'], '0')) {
                    throw new RuntimeException('Historical FIFO projection quantity/value inconsistent');
                }
                $balances[$key]->on_hand_qty = Decimal::qty($qty);
                $balances[$key]->total_value = Decimal::money($running[$key]['value']);
                $balances[$key]->average_cost = Decimal::isZero($qty) ? '0' : Decimal::cost(Decimal::div($running[$key]['value'], $qty));
                $balances[$key]->save();
            }
            foreach ($created as $doc) {
                app(WorkflowValidationService::class)->assertOperationalDocumentReady($doc, 'stock.historical_fifo_cost_corrected.v1');
                app(IntegrationOutboxService::class)->record('stock.historical_fifo_cost_corrected.v1', $doc, 'HistoricalFifoCorrection', $doc->correction_uuid, $doc->causal_payload['business_date']);
            }
            $result = ['plan_id' => $review->id, 'correction_ids' => array_map(fn ($doc) => (int) $doc->id, $created)];
            $review->status = 'applied';
            $review->result = $result;
            $review->save();
            $this->writeAudit($org, ['action' => 'historical_fifo_projection', 'user_id' => $review->reviewed_by_central_id], 'historical-fifo:'.$review->correction_uuid, count($created));

            return $result;
        });
    }

    private function historicalLayerForRow(int $org, StockLedger $row): CostLayer
    {
        $layer = CostLayer::query()->where('organization_id', $org)->where('source_ledger_id', $row->id)->lockForUpdate()->first();
        if (! $layer) {
            throw new RuntimeException('Historical FIFO native inbound layer missing');
        }

        return $layer;
    }

    private function appendHistoricalFifoRow(int $org, object $doc, array $event, string $qty, string $value, string $direction, array &$running, string $kind): StockLedger
    {
        $sign = $direction === 'in' ? '1' : '-1';
        $running['quantity'] = Decimal::qty(Decimal::add($running['quantity'], Decimal::mul($qty, $sign)));
        $running['value'] = Decimal::money(Decimal::add($running['value'], Decimal::mul($value, $sign)));
        if (Decimal::lt($running['quantity'], '0') || Decimal::lt($running['value'], '0')) {
            throw new RuntimeException('Historical FIFO negative current projection');
        }

        return StockLedger::query()->create(['organization_id' => $org, 'item_id' => $event['stock_item_id'], 'warehouse_id' => $event['warehouse_id'],
            'direction' => $direction, 'quantity' => Decimal::qty($qty), 'unit_cost' => Decimal::isZero($qty) ? '0' : Decimal::cost(Decimal::div($value, $qty)),
            'total_cost' => Decimal::money($value), 'costing_method' => 'fifo', 'source_type' => HistoricalFifoCorrection::class,
            'source_id' => $doc->id, 'source_line_id' => $event['finance_line_id'], 'moved_at' => $event['date'].' 00:00:00', 'posted_at' => now(),
            'idempotency_key' => 'historical-fifo:'.$doc->correction_uuid.':'.$kind, 'balance_qty_after' => $running['quantity'], 'balance_value_after' => $running['value'],
            'created_by' => auth()->id()]);
    }

    /**
     * Apply one movement: validate, lock balance, cost, append ledger, update
     * balance + layers. Assumes it runs inside an open transaction.
     */
    private function applyMovement(
        StockMovement $m,
        int $orgId,
        string $namespace,
        int $index,
        bool $isReversal = false
    ): StockLedger {
        // ── validate direction & quantity ──
        if (! in_array($m->direction, ['in', 'out'], true)) {
            throw new RuntimeException("Invalid movement direction '{$m->direction}'.");
        }
        $qty = Decimal::qty($m->quantity);
        if (! Decimal::gt($qty, '0')) {
            throw new RuntimeException(__('inventory.stock.movement_positive'));
        }

        app(PurchaseValuationHoldService::class)->lockItems([$m->itemId]);
        app(PurchaseValuationHoldService::class)->assertMovable($m->itemId, $m->warehouseId);

        // ── validate item & warehouse belong to this org (defense in depth) ──
        $item = Item::query()->find($m->itemId);
        if (! $item) {
            throw new RuntimeException("Item {$m->itemId} not found in this organization.");
        }
        if ((int) $item->organization_id !== $orgId) {
            throw new RuntimeException(__('inventory.stock.cross_item'));
        }
        $warehouse = Warehouse::query()->find($m->warehouseId);
        if (! $warehouse) {
            throw new RuntimeException("Warehouse {$m->warehouseId} not found in this organization.");
        }
        if ((int) $warehouse->organization_id !== $orgId) {
            throw new RuntimeException(__('inventory.stock.cross_warehouse'));
        }

        // ── only stock-tracked items move stock (SC-UAE-042) ──
        // Service and non-inventory items have no quantity or valuation; a posted
        // movement would create ledger rows, cost layers and inventory/COGS
        // postings for them. Reversals stay allowed so historical rows unwind.
        if (! $isReversal && (string) $item->item_type !== 'inventory') {
            throw new RuntimeException(__('inventory.stock.non_stock_item', ['sku' => (string) $item->sku]));
        }

        // ── tracking compatibility (relaxed for reversals: original coords carried) ──
        if (! $isReversal) {
            if ($item->tracksSerials() && $m->serialId === null) {
                throw new RuntimeException("Item {$item->sku} is serial-tracked; a serial is required.");
            }
            if ($item->tracksLots() && $m->lotId === null) {
                throw new RuntimeException("Item {$item->sku} is lot-tracked; a lot is required.");
            }
        }
        if ($item->tracksSerials() && Decimal::cmp($qty, '1') !== 0) {
            throw new RuntimeException('Serial-tracked movements must have quantity exactly 1.');
        }

        // ── lot trace policy: block OUT of expired / quarantined / recalled lots
        // unless the caller carries the matching override (a permission-gated flag
        // resolved by the document service). Reversals are exempt. ──
        if (! $isReversal && $m->direction === 'out' && $m->lotId !== null) {
            $this->enforceLotPolicy($orgId, $m, $item);
        }

        $method = $item->effectiveCostingMethod();
        $settings = InventorySetting::query()->first();
        $allowNegative = (bool) ($settings->allow_negative_stock ?? false);

        // ── lock + resolve the balance row (coordinate granularity) ──
        $balance = $this->lockBalance($orgId, $m, $item);

        // ── cost ──
        $costLayerId = null;
        if ($m->direction === 'in') {
            if ($m->unitCost === null) {
                throw new RuntimeException(__('inventory.stock.inbound_cost'));
            }
            $costed = $this->costing->costInbound(
                $method, $orgId, $m->itemId, $m->variantId, $m->warehouseId, $m->lotId,
                $qty, $m->unitCost, $m->movedAt ?? now()->toDateTimeString()
            );
            $unitCost = $costed['unit_cost'];
            $totalCost = $costed['total_cost'];
            $consumed = [];
            $costLayerId = $costed['cost_layer_id'];
        } else {
            // A reversing 'out' (undoing a prior 'in') may take stock to zero even
            // when negative stock is disabled — pass allowNegative=true in that case.
            $costed = $this->costing->costOutbound(
                $method, $orgId, $m->itemId, $m->variantId, $m->warehouseId, $m->lotId,
                $qty, $balance, $allowNegative || $isReversal
            );
            $unitCost = $costed['unit_cost'];
            $totalCost = $costed['total_cost'];
            $consumed = $costed['consumed'];
        }

        // ── negative-stock policy (skip for reversals, which undo prior stock) ──
        if ($m->direction === 'out' && ! $isReversal) {
            $newQty = Decimal::sub((string) $balance->on_hand_qty, $qty);
            if (! $allowNegative && Decimal::lt($newQty, '0')) {
                throw new RuntimeException(
                    "Insufficient stock for item {$item->sku} at warehouse {$m->warehouseId}: "
                    ."have {$balance->on_hand_qty}, need {$qty}. Negative stock disabled."
                );
            }
        }

        // ── serial uniqueness / status ──
        if ($item->tracksSerials() && $m->serialId !== null) {
            $this->applySerial($orgId, $m, $item);
        }

        // ── update balance (same transaction) ──
        [$newOnHand, $newAvg, $newValue] = $this->projectBalance($balance, $m->direction, $qty, $unitCost, $totalCost, $method);
        $this->enforceBinCapacity($orgId, $m, $balance, $newOnHand);
        $balance->on_hand_qty = $newOnHand;
        $balance->average_cost = $newAvg;
        $balance->total_value = $newValue;
        $balance->last_movement_at = $m->movedAt ?? now();
        $balance->save();

        // ── append immutable ledger row ──
        $ledger = new StockLedger([
            'organization_id' => $orgId,
            'item_id' => $m->itemId,
            'variant_id' => $m->variantId,
            'warehouse_id' => $m->warehouseId,
            'zone_id' => $m->zoneId,
            'bin_id' => $m->binId,
            'lot_id' => $m->lotId,
            'serial_id' => $m->serialId,
            'expiry_date' => $m->expiryDate ?? $this->lotExpiry($orgId, $m->lotId),
            'direction' => $m->direction,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'costing_method' => $method,
            'cost_layer_id' => $consumed[0]['layer_id'] ?? ($costLayerId ?? null),
            'source_type' => $m->sourceType,
            'source_id' => $m->sourceId,
            'source_line_id' => $m->sourceLineId,
            'moved_at' => $m->movedAt ?? now(),
            'posted_at' => now(),
            'idempotency_key' => $namespace.'#'.$index,
            'balance_qty_after' => $newOnHand,
            'balance_value_after' => $newValue,
            'created_by' => auth()->id(),
        ]);
        $ledger->save();
        if ($m->direction === 'in' && $costLayerId) {
            CostLayer::query()->where('organization_id', $orgId)->whereKey($costLayerId)
                ->whereNull('source_ledger_id')->update(['source_ledger_id' => $ledger->id]);
        }

        // Record the exact FIFO layers this OUT consumed so a reversal can
        // restore them precisely (preserving layer order + valuation) instead of
        // recreating one blended layer. A transfer also reads these to recreate
        // one destination layer per consumed source layer.
        if ($m->direction === 'out' && $consumed !== []) {
            foreach ($consumed as $c) {
                CostLayerConsumption::query()->create([
                    'organization_id' => $orgId,
                    'ledger_id' => $ledger->id,
                    'cost_layer_id' => $c['layer_id'],
                    'qty' => $c['qty'],
                    'unit_cost' => $c['unit_cost'],
                ]);
            }
        }

        return $ledger;
    }

    private function enforceBinCapacity(int $orgId, StockMovement $movement, StockBalance $balance, string $newCoordinateOnHand): void
    {
        if ($movement->binId === null || $movement->direction !== 'in') {
            return;
        }

        $bin = WarehouseBin::query()->where('organization_id', $orgId)->lockForUpdate()->find($movement->binId);
        if (! $bin) {
            throw new RuntimeException(__('inventory.stock.bin_not_found'));
        }
        if ((int) $bin->warehouse_id !== $movement->warehouseId) {
            throw new RuntimeException(__('inventory.stock.bin_wrong_warehouse'));
        }
        if (! $bin->is_active) {
            throw new RuntimeException(__('inventory.stock.bin_inactive'));
        }
        if ($bin->capacity === null) {
            return;
        }

        $otherOnHand = StockBalance::query()
            ->where('organization_id', $orgId)
            ->where('warehouse_id', $movement->warehouseId)
            ->where('bin_id', $movement->binId)
            ->where('id', '!=', $balance->id)
            ->sum('on_hand_qty');

        $projectedBinQty = Decimal::qty(Decimal::add((string) $otherOnHand, $newCoordinateOnHand));
        if (Decimal::gt($projectedBinQty, (string) $bin->capacity)) {
            throw new RuntimeException("Bin {$bin->code} capacity exceeded: capacity {$bin->capacity}, projected {$projectedBinQty}.");
        }
    }

    /**
     * Reverse a single ORIGINAL FIFO ledger movement by restoring/removing the
     * EXACT cost layers it touched (never re-costing into a blended layer):
     *   - undo an OUT → add the consumed qty back to each layer it drew from (from
     *     cost_layer_consumptions) and append a reversing IN;
     *   - undo an IN  → remove the qty from the layer it created and append a
     *     reversing OUT.
     * Balances are projected via the shared projectBalance() to stay consistent.
     */
    private function applyExactReversal(
        StockLedger $orig,
        string $namespace,
        int $index,
        ?string $reversalSourceType = null,
        ?int $reversalSourceId = null,
    ): StockLedger {
        $orgId = $this->context->idOrFail();
        $item = Item::query()->find((int) $orig->item_id);
        if (! $item || (int) $item->organization_id !== $orgId) {
            throw new RuntimeException(__('inventory.stock.cross_item_reversal'));
        }

        $reverseDirection = $orig->direction === 'in' ? 'out' : 'in';
        $qty = Decimal::qty((string) $orig->quantity);
        $unitCost = Decimal::cost((string) $orig->unit_cost);
        $totalCost = Decimal::money((string) $orig->total_cost);

        $movement = new StockMovement(
            direction: $reverseDirection,
            itemId: (int) $orig->item_id,
            warehouseId: (int) $orig->warehouse_id,
            quantity: $qty,
            sourceType: $reversalSourceType ?? $orig->source_type,
            sourceId: $reversalSourceId ?? (int) $orig->source_id,
            sourceLineId: $orig->source_line_id ? (int) $orig->source_line_id : null,
            variantId: $orig->variant_id ? (int) $orig->variant_id : null,
            zoneId: $orig->zone_id ? (int) $orig->zone_id : null,
            binId: $orig->bin_id ? (int) $orig->bin_id : null,
            lotId: $orig->lot_id ? (int) $orig->lot_id : null,
            serialId: $orig->serial_id ? (int) $orig->serial_id : null,
            unitCost: $unitCost,
            movedAt: now()->toDateTimeString(),
        );

        $balance = $this->lockBalance($orgId, $movement, $item);
        $layerForLedger = null;

        if ($item->tracksSerials() && $movement->serialId !== null) {
            $this->applySerialReversal($orgId, $movement, $item, $orig->direction);
        }

        if ((string) $orig->costing_method === 'fifo' && $orig->direction === 'out') {
            // RESTORE the exact layers this OUT consumed (add back per-layer qty).
            $consumptions = CostLayerConsumption::query()->where('ledger_id', $orig->id)->orderBy('id')->get();
            foreach ($consumptions as $c) {
                $layer = CostLayer::query()->lockForUpdate()->find($c->cost_layer_id);
                if ($layer) {
                    $layer->remaining_qty = Decimal::qty(Decimal::add((string) $layer->remaining_qty, (string) $c->qty));
                    $layer->save();
                }
            }
            $layerForLedger = $consumptions->first()?->cost_layer_id ? (int) $consumptions->first()->cost_layer_id : null;
        } elseif ((string) $orig->costing_method === 'fifo') {
            // REMOVE the qty from the layer this IN created. If the layer no longer
            // holds the full received quantity, those units were already consumed
            // downstream (sold, transferred, adjusted out) — the receipt cannot be
            // un-received without corrupting FIFO. Reject cleanly and roll back the
            // whole reversal (atomic) instead of flooring at 0, which silently
            // collapsed layers and drove on-hand negative.
            $layerForLedger = $orig->cost_layer_id ? (int) $orig->cost_layer_id : null;
            if ($layerForLedger) {
                $layer = CostLayer::query()->lockForUpdate()->find($layerForLedger);
                if ($layer) {
                    $newRemaining = Decimal::sub((string) $layer->remaining_qty, $qty);
                    if (Decimal::lt($newRemaining, '0')) {
                        $consumedDownstream = Decimal::qty(Decimal::sub($qty, (string) $layer->remaining_qty));
                        throw new RuntimeException(
                            "Cannot reverse this inbound movement: {$consumedDownstream} unit(s) of the "
                            ."received cost layer (#{$layer->id}) were already consumed downstream "
                            .'(sold, transferred, or adjusted out). Reverse the downstream movement(s) '
                            .'first, or post a compensating adjustment.'
                        );
                    }
                    $layer->remaining_qty = Decimal::qty($newRemaining);
                    $layer->save();
                }
            }
        }

        [$newOnHand, $newAvg, $newValue] = $this->projectBalance($balance, $reverseDirection, $qty, $unitCost, $totalCost, 'fifo');
        $balance->on_hand_qty = $newOnHand;
        $balance->average_cost = $newAvg;
        $balance->total_value = $newValue;
        $balance->last_movement_at = now();
        $balance->save();

        $ledger = new StockLedger([
            'organization_id' => $orgId,
            'item_id' => $orig->item_id,
            'variant_id' => $orig->variant_id,
            'warehouse_id' => $orig->warehouse_id,
            'zone_id' => $orig->zone_id,
            'bin_id' => $orig->bin_id,
            'lot_id' => $orig->lot_id,
            'serial_id' => $orig->serial_id,
            'expiry_date' => $orig->expiry_date,
            'direction' => $reverseDirection,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'costing_method' => $orig->costing_method,
            'cost_layer_id' => $layerForLedger,
            'source_type' => $reversalSourceType ?? $orig->source_type,
            'source_id' => $reversalSourceId ?? $orig->source_id,
            'source_line_id' => $orig->source_line_id,
            'moved_at' => now(),
            'posted_at' => now(),
            'idempotency_key' => $namespace.'#'.$index,
            'balance_qty_after' => $newOnHand,
            'balance_value_after' => $newValue,
            'created_by' => auth()->id(),
        ]);
        $ledger->save();

        return $ledger;
    }

    /** Lock (or create) the balance row for this movement's coordinate. */
    private function lockBalance(int $orgId, StockMovement $m, Item $item): StockBalance
    {
        // A FOR UPDATE fetch of the exact coordinate. Re-runnable so we can
        // re-acquire the lock after creating a brand-new row.
        $lockedFetch = static fn () => StockBalance::query()
            ->where('organization_id', $orgId)
            ->where('item_id', $m->itemId)
            ->where('warehouse_id', $m->warehouseId)
            ->when($m->variantId !== null, fn ($q) => $q->where('variant_id', $m->variantId), fn ($q) => $q->whereNull('variant_id'))
            ->when($m->lotId !== null, fn ($q) => $q->where('lot_id', $m->lotId), fn ($q) => $q->whereNull('lot_id'))
            ->when($m->binId !== null, fn ($q) => $q->where('bin_id', $m->binId), fn ($q) => $q->whereNull('bin_id'))
            ->lockForUpdate()
            ->first();

        if ($balance = $lockedFetch()) {
            return $balance;
        }

        // First movement at this coordinate → create the row. A CONCURRENT
        // transaction may insert the same coordinate first; its row wins the
        // (org,item,variant,warehouse,lot,bin) unique index and our INSERT throws
        // a duplicate-key error, which we swallow and then lock the existing row.
        try {
            StockBalance::query()->create([
                'organization_id' => $orgId,
                'item_id' => $m->itemId,
                'variant_id' => $m->variantId,
                'warehouse_id' => $m->warehouseId,
                'lot_id' => $m->lotId,
                'bin_id' => $m->binId,
                'on_hand_qty' => '0',
                'reserved_qty' => '0',
                'average_cost' => '0',
                'total_value' => '0',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent transaction created it first — fall through to lock it.
        }

        // CRITICAL: re-fetch WITH the lock so the row is held FOR UPDATE before
        // any negative-stock / availability check reads on_hand/reserved off it.
        $balance = $lockedFetch();
        if (! $balance) {
            throw new RuntimeException('stock_balances row could not be locked after creation.');
        }

        return $balance;
    }

    /** Compute new on-hand, average cost, and total value after the movement. */
    private function projectBalance(StockBalance $balance, string $direction, string $qty, string $unitCost, string $totalCost, string $method): array
    {
        $prevQty = (string) $balance->on_hand_qty;
        $prevAvg = (string) $balance->average_cost;

        $newQty = $direction === 'in'
            ? Decimal::qty(Decimal::add($prevQty, $qty))
            : Decimal::qty(Decimal::sub($prevQty, $qty));

        // FIFO: total_value must track the ACTUAL cost that flowed in/out at this
        // coordinate, i.e. the running sum of ledger total_cost (in − out). Deriving
        // it from a running weighted average instead made balance.total_value drift
        // from the true FIFO value on every mixed-cost movement — the integrity
        // checker flags this as a per-coordinate "value mismatch", the visible
        // symptom of the cost-layer collapse. This matches the checker's own
        // reconciliation (ledger net total_cost vs balance.total_value) exactly, at
        // any coordinate granularity (bin-aware). average_cost is the blended unit
        // value for display only.
        if ($method === 'fifo') {
            $prevValue = (string) $balance->total_value;
            $signedCost = $direction === 'in' ? $totalCost : '-'.$totalCost;
            $newValue = Decimal::money(Decimal::add($prevValue, $signedCost));
            // Guard tiny negative dust at zero stock.
            if (Decimal::isZero($newQty)) {
                $newValue = '0.00';
            }
            $newAvg = Decimal::isZero($newQty) ? '0' : Decimal::cost(Decimal::div($newValue, $newQty));

            return [$newQty, $newAvg, $newValue];
        }

        // Average costing.
        if ($direction === 'in') {
            $newAvg = $this->costing->newWeightedAverage($prevQty, $prevAvg, $qty, $unitCost);
            $newValue = Decimal::money(Decimal::mul($newQty, $newAvg));

            return [$newQty, $newAvg, $newValue];
        }

        // out: average cost unchanged on issue; value = qty * avg.
        $newAvg = $prevAvg;
        $newValue = Decimal::money(Decimal::mul($newQty, $newAvg));

        return [$newQty, $newAvg, $newValue];
    }

    /**
     * Block issuing stock from an expired / quarantined / recalled lot unless the
     * movement carries the matching override. Keeps the lot's status authoritative
     * (a past expiry counts as expired even if the status flag lags).
     */
    private function enforceLotPolicy(int $orgId, StockMovement $m, Item $item): void
    {
        $lot = Lot::query()->where('organization_id', $orgId)->find($m->lotId);
        if (! $lot) {
            return; // lot rows are created by capture; a missing lot is caught elsewhere
        }
        $status = $lot->effectiveStatus();

        if (in_array($status, ['quarantined', 'recalled'], true) && ! $m->allowQuarantinedLot) {
            throw new RuntimeException(
                "Lot {$lot->lot_code} for item {$item->sku} is {$status} and cannot be shipped without an override."
            );
        }
        if ($status === 'expired' && ! $m->allowExpiredLot) {
            throw new RuntimeException(
                "Lot {$lot->lot_code} for item {$item->sku} is expired and cannot be shipped without an override."
            );
        }
    }

    /** Expiry date for a lot, if any — denormalized onto the ledger row. */
    private function lotExpiry(int $orgId, ?int $lotId): ?string
    {
        if ($lotId === null) {
            return null;
        }
        $date = Lot::query()->where('organization_id', $orgId)->where('id', $lotId)->value('expiry_date');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** Validate + update a serial's status/location for the movement. */
    private function applySerial(int $orgId, StockMovement $m, Item $item): void
    {
        $serial = SerialNumber::query()->where('organization_id', $orgId)->find($m->serialId);
        if (! $serial) {
            throw new RuntimeException("Serial {$m->serialId} not found.");
        }
        if ((int) $serial->item_id !== $m->itemId) {
            throw new RuntimeException(__('inventory.stock.serial_wrong_item'));
        }

        if ($m->direction === 'in') {
            if ($serial->status === 'in_stock') {
                throw new RuntimeException("Serial {$serial->serial} is already in stock.");
            }
            $serial->status = 'in_stock';
            $serial->warehouse_id = $m->warehouseId;
            $serial->bin_id = $m->binId;
        } else {
            if (! $serial->isAvailable()) {
                throw new RuntimeException("Serial {$serial->serial} is not in stock (status: {$serial->status}).");
            }
            $serial->status = 'sold';
        }
        $serial->save();
    }

    /**
     * Preserve serial lifecycle semantics while undoing a posted movement.
     * Undoing an inbound receipt removes the serial from on-hand, but it is not
     * a sale; keep the identity/history and mark it returned. Undoing an outbound
     * source puts the exact serial back into stock.
     */
    private function applySerialReversal(int $orgId, StockMovement $movement, Item $item, string $originalDirection): void
    {
        $serial = SerialNumber::query()->where('organization_id', $orgId)->find($movement->serialId);
        if (! $serial) {
            throw new RuntimeException("Serial {$movement->serialId} not found.");
        }
        if ((int) $serial->item_id !== $movement->itemId) {
            throw new RuntimeException(__('inventory.stock.serial_wrong_item'));
        }

        if ($originalDirection === 'in') {
            if (! in_array($serial->status, ['available', 'in_stock'], true)) {
                throw new RuntimeException("Serial {$serial->serial} is not available for receipt reversal (status: {$serial->status}).");
            }
            $serial->status = 'returned';
        } else {
            if (! in_array($serial->status, ['sold', 'shipped'], true)) {
                throw new RuntimeException("Serial {$serial->serial} is not shipped for source reversal (status: {$serial->status}).");
            }
            $serial->status = 'in_stock';
            $serial->warehouse_id = $movement->warehouseId;
            $serial->bin_id = $movement->binId;
        }

        $serial->save();
    }

    private function writeAudit(int $orgId, array $audit, string $namespace, int $rows): void
    {
        InventoryAuditLog::create([
            'organization_id' => $orgId,
            'actor_user_id' => auth()->id(),
            'action' => $audit['action'] ?? 'stock.post',
            'entity_type' => $audit['entity_type'] ?? 'stock_ledger',
            'entity_id' => $audit['entity_id'] ?? null,
            'before' => null,
            'after' => ['namespace' => $namespace, 'rows' => $rows] + (isset($audit['reason']) ? ['reason' => $audit['reason']] : []),
            'document_ref' => $audit['document_ref'] ?? $namespace,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
