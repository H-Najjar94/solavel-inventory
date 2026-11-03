<?php

namespace App\Services\Documents;

use App\Models\Tenant\CostLayerConsumption;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Integration\WorkflowValidationService;
use App\Services\Purchasing\PostedPurchaseReversalGuard;
use App\Services\Purchasing\ReceiptHandoffService;
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates immutable, reasoned reversal documents for posted Inventory sources.
 * Reversals derive every stock coordinate and cost from the original ledger;
 * callers never submit quantities, warehouses, lots, serials, or costs.
 */
class InventoryReversalService
{
    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private IntegrationOutboxService $outbox,
        private WorkflowValidationService $workflowValidation,
    ) {}

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function reverseGoodsReceipt(GoodsReceipt $receipt, string $reason): InventoryReversal
    {
        $originContext = app(\App\Services\FinancialOrigins\OriginPhysicalService::class)->beforeReverse($receipt);
        return DB::connection($this->connection())->transaction(function () use ($receipt, $reason, $originContext) {
            $originContext?->lockAndValidate();
            $receipt = GoodsReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->reversal_id) {
                return InventoryReversal::query()->findOrFail($receipt->reversal_id);
            }
            if ($receipt->status !== 'posted') {
                throw new RuntimeException("Only a posted goods receipt can be reversed (status '{$receipt->status}').");
            }

            app(PostedPurchaseReversalGuard::class)->assertReceiptReversible($receipt);

            $this->workflowValidation->assertOperationalDocumentReady($receipt, 'grn.reversed');
            $this->assertReason($reason);
            $this->assertInboundSourceStillReversible('goods_receipt:'.$receipt->id.':post');
            $reversal = $this->createReversal('goods_receipt', $receipt->id, $receipt->grn_number, 'grn.posted', 'REV-GRN', $reason);

            $this->ledger->reverse(
                'goods_receipt:'.$receipt->id.':post',
                'inventory_reversal:'.$reversal->id.':post',
                [
                    'action' => 'goods_receipt.reverse',
                    'entity_type' => 'inventory_reversal',
                    'entity_id' => $reversal->id,
                    'document_ref' => $reversal->reversal_number,
                ],
                InventoryReversal::class,
                $reversal->id,
            );

            $this->rollBackPurchaseOrder($receipt);
            $receipt->reversal_id = $reversal->id;
            $receipt->reversed_at = now();
            $receipt->reversed_by = auth()->id();
            $receipt->markSystemTransition()->save();

            $this->recordEvent($reversal, 'grn.reversed');
            if (!app(\App\Services\FinancialOrigins\OriginPhysicalService::class)->reversed($receipt, $reversal)) {
                app(ReceivingRequestService::class)->posted($receipt, true);
                app(ReceiptHandoffService::class)->record($receipt, true);
            }

            return $reversal->fresh();
        });
    }

    public function reverseNegativeAdjustment(StockAdjustment $adjustment, string $reason): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($adjustment, $reason) {
            $adjustment = StockAdjustment::query()->with('lines')->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->reversal_id) {
                return InventoryReversal::query()->findOrFail($adjustment->reversal_id);
            }
            if (! $adjustment->isPosted()) {
                throw new RuntimeException("Only a posted adjustment can be reversed (status '{$adjustment->status}').");
            }
            if ($adjustment->lines->contains(fn ($line) => $line->direction === 'increase')) {
                $this->assertInboundSourceStillReversible('stock_adjustment:'.$adjustment->id.':post', 'adjustment');
            }

            $this->assertReason($reason);
            $reversal = $this->createReversal('stock_adjustment', $adjustment->id, $adjustment->adjustment_number, 'adjustment.posted', 'REV-ADJ', $reason);
            $this->ledger->reverse(
                'stock_adjustment:'.$adjustment->id.':post',
                'inventory_reversal:'.$reversal->id.':post',
                [
                    'action' => 'stock_adjustment.reverse',
                    'reason' => trim($reason), 'before' => ['status' => 'posted'], 'after' => ['status' => 'reversed'],
                    'entity_type' => 'inventory_reversal',
                    'entity_id' => $reversal->id,
                    'document_ref' => $reversal->reversal_number,
                ],
                InventoryReversal::class,
                $reversal->id,
            );

            $adjustment->status = 'reversed';
            $adjustment->reversal_id = $reversal->id;
            $adjustment->reversed_at = now();
            $adjustment->reversed_by = auth()->id();
            $adjustment->markSystemTransition()->save();
            $this->recordEvent($reversal, 'adjustment.reversed');

            return $reversal->fresh();
        });
    }

    /**
     * Reverse a posted opening-stock entry through an InventoryReversal so the
     * reversal ledger rows, outbox event and Finance journal belong to their own
     * aggregate. The journal is the exact inverse of the original opening event
     * (AccountingJournalBuilder::inventoryReversal) and links to it through
     * original_event_uuid / source.reversal.original_source_key.
     * The caller has already locked the entry inside the same transaction.
     */
    public function reverseOpeningStock(OpeningStockEntry $entry, string $reason): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($entry, $reason) {
            $existing = InventoryReversal::query()
                ->where('source_type', 'opening_stock')->where('source_id', $entry->id)->first();
            if ($existing) {
                return $existing;
            }
            $this->assertReason($reason);
            $this->assertInboundSourceStillReversible('opening_stock:'.$entry->id.':post', 'opening');
            $reversal = $this->createReversal('opening_stock', (int) $entry->id, (string) $entry->entry_number, 'opening_stock.posted', 'REV-OS', $reason);
            $this->ledger->reverse(
                'opening_stock:'.$entry->id.':post',
                'opening_stock:'.$entry->id.':reverse',
                [
                    'action' => 'opening_stock.reverse',
                    'entity_type' => 'opening_stock_entry',
                    'entity_id' => $entry->id,
                    'document_ref' => $entry->entry_number,
                    'reason' => trim($reason),
                ],
                InventoryReversal::class,
                $reversal->id,
            );
            $this->recordEvent($reversal, 'opening_stock.reversed');

            return $reversal->fresh();
        });
    }

    /**
     * Landed costs move no quantity: the reversal is its own InventoryReversal
     * aggregate (so it has its own document mapping and source key), $inverse
     * undoes the revaluation, and `landed_cost.reversed` links to the posted
     * event so Finance records the exact inverse journal (reversal_of_id).
     * The caller has already locked the landed cost inside the same transaction.
     */
    public function reverseLandedCost(\App\Models\Tenant\LandedCost $landedCost, string $reason, callable $inverse): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($landedCost, $reason, $inverse) {
            $existing = InventoryReversal::query()
                ->where('source_type', 'landed_cost')->where('source_id', $landedCost->id)->first();
            if ($existing) {
                return $existing;
            }
            $this->assertReason($reason);
            $reversal = $this->createReversal('landed_cost', (int) $landedCost->id, (string) $landedCost->landed_cost_number, 'landed_cost.posted', 'REV-LC', $reason);
            $inverse($reversal);
            $this->recordEvent($reversal, 'landed_cost.reversed');

            return $reversal->fresh();
        });
    }

    public function reverseSalesReturn(SalesReturn $return, string $reason): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($return, $reason) {
            app(\App\Services\Sales\SalesReturnFinancialReversalGuard::class)->lockAndAssert($return);
            $return = SalesReturn::query()->with('lines')->lockForUpdate()->findOrFail($return->id);
            if ($return->reversal_id) {
                return InventoryReversal::query()->findOrFail($return->reversal_id);
            }
            if ($return->status !== 'posted') {
                throw new RuntimeException("Only a posted sales return can be reversed (status '{$return->status}').");
            }
            $sourceMappingUuid = IntegrationDocumentLifecycleMapping::query()
                ->where('source_application', 'solastock')->where('source_document_type', 'sales_return')
                ->where('source_document_id', (string) $return->id)->value('mapping_uuid');
            if ($sourceMappingUuid) {
                $this->assertNoEffectiveFinanceCredit($sourceMappingUuid);
            }
            $this->workflowValidation->assertOperationalDocumentReady($return, 'sales_return.reversed');
            $this->assertReason($reason);
            $namespace = 'sales_return:'.$return->id.':post';
            $hasLedger = StockLedger::query()->where('idempotency_key', 'like', $namespace.'#%')->exists();
            $reversal = $this->createReversal('sales_return', $return->id, $return->return_number, 'sales_return.posted', 'REV-RMA', $reason);
            // Damaged/retired units were received then written off by posted decrease
            // adjustments; undo those first so the return's inbound can be reversed.
            $reversedOut = [];
            foreach (StockAdjustment::query()->where('organization_id', $return->organization_id)
                ->whereIn('adjustment_number', \App\Services\Documents\SalesReturnService::writeOffNumbers($return))
                ->lockForUpdate()->get() as $writeOff) {
                if ($writeOff->isPosted()) {
                    $this->reverseNegativeAdjustment($writeOff, 'Reversal of customer return '.$return->return_number);
                }
                // Posted-then-reversed here, or reversed by hand earlier: its OUT rows are not consumption.
                if (in_array($writeOff->fresh()->status, ['reversed'], true)) {
                    $reversedOut[] = 'stock_adjustment:'.$writeOff->id.':post';
                }
            }
            if ($hasLedger) {
                $this->assertInboundSourceStillReversible($namespace, 'receipt', $reversedOut, true);
                $this->ledger->reverse($namespace, 'inventory_reversal:'.$reversal->id.':post', [
                    'action' => 'sales_return.reverse', 'entity_type' => 'inventory_reversal',
                    'entity_id' => $reversal->id, 'document_ref' => $reversal->reversal_number,
                ], InventoryReversal::class, $reversal->id);
            }
            $return->status = 'reversed';
            $return->reversal_id = $reversal->id;
            $return->reversed_at = now();
            $return->reversed_by = auth()->id();
            $return->markSystemTransition()->save();
            $this->recordEvent($reversal, 'sales_return.reversed');
            app(\App\Services\Sales\ReturnHandoffService::class)->record($return, true);

            return $reversal->fresh();
        });
    }

    /**
     * A posted Finance allocation against this return means the customer credit is still effective.
     * Finance voids the credit FIRST (SolaCount never needs the return reversed to do so); only then does
     * Stock reverse the physical return. When Finance already voided the credit but the Stock allocation
     * mirror has not yet been acknowledged, say so: the fix is to retry the void synchronization, not to void again.
     */
    private function assertNoEffectiveFinanceCredit(string $sourceMappingUuid): void
    {
        $posted = IntegrationFinancialLineAllocation::query()
            ->where('source_document_mapping_uuid', $sourceMappingUuid)->where('state', 'posted')->get();
        if ($posted->isEmpty()) {
            return;
        }
        $db = DB::connection($this->connection());
        try {
            // Shared-tenant truth only; a Stock-only tenant has no credit_notes table and keeps the strict message.
            $voidedInFinance = $posted->every(fn ($row) => $row->destination_document_type === 'customer_credit_note'
                && $db->table('credit_notes')->where('id', $row->destination_document_id)->where('status', 'void')->exists());
        } catch (\Illuminate\Database\QueryException) {
            $voidedInFinance = false;
        }
        throw new RuntimeException($voidedInFinance
            ? __('return_reversal.credit_void_acknowledgement_pending')
            : __('return_reversal.credit_before_return'));
    }

    private function createReversal(string $sourceType, int $sourceId, string $sourceNumber, string $originalEventType, string $prefix, string $reason): InventoryReversal
    {
        $orgId = $this->context->idOrFail();
        $existing = InventoryReversal::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $original = IntegrationOutboxEvent::query()
            ->where('event_type', $originalEventType)
            ->where('aggregate_id', $sourceId)
            ->where('aggregate_number', $sourceNumber)
            ->orderByDesc('id')
            ->first();

        return InventoryReversal::query()->create([
            'organization_id' => $orgId,
            'reversal_number' => DocumentNumber::next($prefix, InventoryReversal::class, 'reversal_number', $orgId, $this->connection()),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_number' => $sourceNumber,
            'reversal_date' => now()->toDateString(),
            'status' => 'posted',
            'reason' => trim($reason),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
            'posted_guard_key' => "{$sourceType}:{$sourceId}:reversal",
            'original_event_uuid' => $original?->event_uuid,
        ]);
    }

    private function recordEvent(InventoryReversal $reversal, string $eventType): void
    {
        $event = $this->outbox->record(
            $eventType,
            $reversal,
            'inventory_reversal',
            $reversal->reversal_number,
            (string) $reversal->reversal_date,
        );
        if ($event) {
            $reversal->reversal_event_uuid = $event->event_uuid;
            $reversal->save();
        }
    }

    /**
     * Reject reversing an inbound source once any downstream outbound touched its
     * coordinates, whatever the costing method. $document picks the wording
     * (receipt, adjustment, opening).
     */
    public function assertInboundSourceStillReversible(string $namespace, string $document = 'receipt', array $reversedOutNamespaces = [], bool $ignoreOtherReturnWriteOffsOnOtherLayers = false): void
    {
        $messagePrefix = match ($document) {
            'adjustment' => 'inventory.documents.adjustment_',
            'opening' => 'inventory.documents.opening_',
            default => 'inventory.documents.',
        };
        $rows = StockLedger::query()->where('idempotency_key', 'like', $namespace.'#%')->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException(__('inventory.documents.reversal_no_ledger'));
        }

        // Cost layers this source created: a later write-off of ANOTHER customer return that drew only from other layers never consumed them.
        $ownLayerIds = $rows->where('direction', 'in')->pluck('cost_layer_id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($rows as $row) {
            if ($row->direction !== 'in') {
                continue;
            }
            $downstreamOuts = StockLedger::query()
                ->where('id', '>', $row->id)
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->where('direction', 'out')
                // OUT rows of write-off adjustments already reversed by this operation are not downstream consumption.
                ->when($reversedOutNamespaces !== [], fn ($q) => $q->where(function ($w) use ($reversedOutNamespaces) {
                    $w->whereNull('idempotency_key')->orWhere(function ($k) use ($reversedOutNamespaces) {
                        foreach ($reversedOutNamespaces as $ns) {
                            $k->where('idempotency_key', 'not like', $ns.'#%');
                        }
                    });
                }))
                ->when($row->variant_id, fn ($q) => $q->where('variant_id', $row->variant_id), fn ($q) => $q->whereNull('variant_id'))
                ->when($row->lot_id, fn ($q) => $q->where('lot_id', $row->lot_id), fn ($q) => $q->whereNull('lot_id'))
                ->when($row->bin_id, fn ($q) => $q->where('bin_id', $row->bin_id), fn ($q) => $q->whereNull('bin_id'))
                ->get();
            foreach ($downstreamOuts as $out) {
                if ($ignoreOtherReturnWriteOffsOnOtherLayers && $row->cost_layer_id && $this->isOtherReturnsWriteOffOnOtherLayers($out, $ownLayerIds)) {
                    continue;
                }
                throw new RuntimeException(__($messagePrefix.'reversal_downstream'));
            }

            $balance = StockBalance::query()
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->when($row->variant_id, fn ($q) => $q->where('variant_id', $row->variant_id), fn ($q) => $q->whereNull('variant_id'))
                ->when($row->lot_id, fn ($q) => $q->where('lot_id', $row->lot_id), fn ($q) => $q->whereNull('lot_id'))
                ->when($row->bin_id, fn ($q) => $q->where('bin_id', $row->bin_id), fn ($q) => $q->whereNull('bin_id'))
                ->lockForUpdate()
                ->first();
            if (! $balance || Decimal::lt((string) $balance->on_hand_qty, (string) $row->quantity)) {
                throw new RuntimeException(__($messagePrefix.'reversal_unavailable'));
            }
        }
    }

    /**
     * A later OUT row is NOT downstream consumption of this source only when ALL hold: it belongs to a posted
     * customer-return write-off adjustment, posted or since reversed (reason customer_return_damage, number DMG-*, written by
     * SalesReturnService::writeOffNonStockable with the exact received layer), the ledger row is FIFO, and every
     * cost-layer draw-down recorded for it (cost_layer_consumptions) is on a layer this source did not create.
     * Anything else (sales, transfers, manual adjustments, average-cost rows, a write-off that touched one of
     * this source's layers, a row without recorded draw-downs) keeps blocking, as before.
     *
     * @param  int[]  $ownLayerIds
     */
    private function isOtherReturnsWriteOffOnOtherLayers(StockLedger $out, array $ownLayerIds): bool
    {
        if ($ownLayerIds === [] || $out->source_type !== StockAdjustment::class || (string) $out->costing_method !== 'fifo') {
            return false;
        }
        $adjustment = StockAdjustment::query()->find((int) $out->source_id);
        if (! $adjustment || ! in_array($adjustment->status, ['posted', 'reversed'], true) || $adjustment->reason_code !== 'customer_return_damage'
            || ! str_starts_with((string) $adjustment->adjustment_number, 'DMG-')) {
            return false;
        }
        $layers = CostLayerConsumption::query()->where('ledger_id', $out->id)->pluck('cost_layer_id')->map(fn ($id) => (int) $id)->all();

        return $layers !== [] && array_intersect($layers, $ownLayerIds) === [];
    }

    private function rollBackPurchaseOrder(GoodsReceipt $receipt): void
    {
        if (! $receipt->purchase_order_id) {
            return;
        }
        $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->find($receipt->purchase_order_id);
        if (! $order) {
            return;
        }
        foreach ($receipt->lines as $line) {
            $orderLine = $order->lines->firstWhere('id', $line->purchase_order_line_id);
            if ($orderLine) {
                $remaining = Decimal::sub((string) $orderLine->received_qty, (string) $line->accepted_qty);
                $orderLine->received_qty = Decimal::qty(Decimal::lt($remaining, '0') ? '0' : $remaining);
                $orderLine->save();
            }
        }
        $order->refresh()->load('lines');
        $any = $order->lines->contains(fn ($line) => Decimal::gt((string) $line->received_qty, '0'));
        $all = $order->lines->every(fn ($line) => Decimal::gte((string) $line->received_qty, (string) $line->ordered_qty));
        $order->status = $all ? 'received' : ($any ? 'partially_received' : 'approved');
        $order->save();
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new RuntimeException('A reversal reason of at least 3 characters is required.');
        }
    }
}
