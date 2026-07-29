<?php

namespace App\Services\Documents;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\IntegrationOutboxService;
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
    ) {}

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function reverseGoodsReceipt(GoodsReceipt $receipt, string $reason): InventoryReversal
    {
        return DB::connection($this->connection())->transaction(function () use ($receipt, $reason) {
            $receipt = GoodsReceipt::query()->with('lines')->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->reversal_id) {
                return InventoryReversal::query()->findOrFail($receipt->reversal_id);
            }
            if ($receipt->status !== 'posted') {
                throw new RuntimeException("Only a posted goods receipt can be reversed (status '{$receipt->status}').");
            }

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
                $this->assertInboundSourceStillReversible('stock_adjustment:'.$adjustment->id.':post');
            }

            $this->assertReason($reason);
            $reversal = $this->createReversal('stock_adjustment', $adjustment->id, $adjustment->adjustment_number, 'adjustment.posted', 'REV-ADJ', $reason);
            $this->ledger->reverse(
                'stock_adjustment:'.$adjustment->id.':post',
                'inventory_reversal:'.$reversal->id.':post',
                [
                    'action' => 'stock_adjustment.reverse',
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

    /** Reject an un-receipt once any downstream outbound touched its coordinates. */
    private function assertInboundSourceStillReversible(string $namespace): void
    {
        $rows = StockLedger::query()->where('idempotency_key', 'like', $namespace.'#%')->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException(__('inventory.documents.reversal_no_ledger'));
        }

        foreach ($rows as $row) {
            if ($row->direction !== 'in') {
                continue;
            }
            $downstreamOut = StockLedger::query()
                ->where('id', '>', $row->id)
                ->where('item_id', $row->item_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->where('direction', 'out')
                ->when($row->variant_id, fn ($q) => $q->where('variant_id', $row->variant_id), fn ($q) => $q->whereNull('variant_id'))
                ->when($row->lot_id, fn ($q) => $q->where('lot_id', $row->lot_id), fn ($q) => $q->whereNull('lot_id'))
                ->when($row->bin_id, fn ($q) => $q->where('bin_id', $row->bin_id), fn ($q) => $q->whereNull('bin_id'))
                ->exists();
            if ($downstreamOut) {
                throw new RuntimeException(__('inventory.documents.reversal_downstream'));
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
                throw new RuntimeException(__('inventory.documents.reversal_unavailable'));
            }
        }
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
