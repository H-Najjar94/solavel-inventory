<?php

namespace App\Services\Documents;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods Receipt (GRN). Posting a GRN is the inbound (IN) stock event for
 * purchases — delegated entirely to StockLedgerService. Updates PO received_qty
 * and PO status. NEVER writes stock tables directly.
 */
class GoodsReceiptService
{
    use \App\Services\Documents\Concerns\CapturesTraceability;

    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private \App\Services\Integration\IntegrationOutboxService $outbox,
        private \App\Services\Traceability\LotService $lots,
        private \App\Services\Traceability\SerialService $serials,
    ) {}

    protected function lotService(): \App\Services\Traceability\LotService
    {
        return $this->lots;
    }

    protected function serialService(): \App\Services\Traceability\SerialService
    {
        return $this->serials;
    }

    private function connection(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(GoodsReceipt $grn): string
    {
        return 'goods_receipt:'.$grn->id.':post';
    }

    public function createDraft(array $attributes, array $lines): GoodsReceipt
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($attributes, $lines, $orgId) {
            $grn = new GoodsReceipt(array_merge([
                'status' => 'draft',
                'receipt_date' => $attributes['receipt_date'] ?? now()->toDateString(),
            ], $attributes));
            $grn->organization_id = $orgId;
            $grn->save();

            foreach ($this->expandAndCaptureLines($lines, (int) $grn->id, $orgId) as $line) {
                $grn->lines()->create($line);
            }

            return $grn->fresh('lines');
        });
    }

    /** Update a DRAFT GRN: replace header + lines. */
    public function updateDraft(GoodsReceipt $grn, array $attributes, array $lines): GoodsReceipt
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->connection())->transaction(function () use ($grn, $attributes, $lines, $orgId) {
            $grn = GoodsReceipt::query()->lockForUpdate()->findOrFail($grn->id);
            if ($grn->status !== 'draft') {
                throw new RuntimeException("Only a draft GRN can be edited (status '{$grn->status}').");
            }

            $grn->fill(collect($attributes)->only(['grn_number', 'purchase_order_id', 'supplier_id', 'warehouse_id', 'receipt_date', 'notes'])->toArray());
            $grn->lines()->delete();

            foreach ($this->expandAndCaptureLines($lines, (int) $grn->id, $orgId) as $line) {
                $grn->lines()->create($line);
            }
            $grn->save();

            return $grn->fresh('lines');
        });
    }

    /**
     * Resolve traceability capture for each line into concrete lot_id/serial_id
     * and expand serial captures into one qty-1 line per serial. Lines that
     * already carry lot_id/serial_id pass through unchanged. Lot/serial rows are
     * minted via the approved traceability services (no stock writes here).
     *
     * Capture inputs (optional, per line): lot_code, expiry_date, serials[].
     *
     * @return array<int,array<string,mixed>>
     */
    private function expandAndCaptureLines(array $lines, int $grnId, int $orgId): array
    {
        $out = [];
        foreach ($lines as $line) {
            $cap = $this->resolveCapture($line, $orgId, GoodsReceipt::class, $grnId);

            $base = [
                'organization_id' => $orgId,
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'rejected_qty' => Decimal::qty((string) ($line['rejected_qty'] ?? '0')),
                'unit_cost' => Decimal::cost((string) ($line['unit_cost'] ?? '0')),
                'lot_id' => $cap['lot_id'],
                'bin_id' => $line['bin_id'] ?? null,
                'expiry_date' => $cap['expiry_date'],
                'notes' => $line['notes'] ?? null,
            ];

            // Serial capture: one qty-1 line per serial.
            if ($cap['serial_ids'] !== []) {
                foreach ($cap['serial_ids'] as $sid) {
                    $out[] = $base + ['received_qty' => '1.0000', 'accepted_qty' => '1.0000', 'serial_id' => $sid];
                }

                continue;
            }

            $accepted = Decimal::qty((string) ($line['accepted_qty'] ?? $line['received_qty']));
            $out[] = $base + [
                'received_qty' => Decimal::qty((string) $line['received_qty']),
                'accepted_qty' => $accepted,
                'serial_id' => $line['serial_id'] ?? null,
            ];
        }

        return $out;
    }

    /** Post a GRN → inbound ledger movements for accepted qty; update PO. */
    public function post(GoodsReceipt $grn): GoodsReceipt
    {
        return DB::connection($this->connection())->transaction(function () use ($grn) {
            $grn = GoodsReceipt::query()->lockForUpdate()->findOrFail($grn->id);
            if ($grn->isPosted()) {
                return $grn; // idempotent
            }
            if ($grn->status !== 'draft') {
                throw new RuntimeException("GRN {$grn->id} cannot be posted from status '{$grn->status}'.");
            }

            $grn->loadMissing('lines');

            // Over-receipt guard: a line tied to a PO line cannot accept more than
            // the PO line's remaining (ordered − already received). No tolerance
            // setting yet, so over-receipt is blocked.
            foreach ($grn->lines as $line) {
                if ($line->purchase_order_line_id) {
                    $poLine = \App\Models\Tenant\PurchaseOrderLine::query()->find($line->purchase_order_line_id);
                    if ($poLine) {
                        $remaining = Decimal::sub((string) $poLine->ordered_qty, (string) $poLine->received_qty);
                        if (Decimal::gt((string) $line->accepted_qty, $remaining)) {
                            throw new RuntimeException(
                                "Over-receipt blocked: accepting {$line->accepted_qty} exceeds PO remaining {$remaining} for item #{$line->item_id}."
                            );
                        }
                    }
                }
            }

            $movements = [];
            foreach ($grn->lines as $line) {
                if (! Decimal::gt((string) $line->accepted_qty, '0')) {
                    continue; // nothing accepted on this line
                }
                $movements[] = new StockMovement(
                    direction: 'in',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) $grn->warehouse_id,
                    quantity: (string) $line->accepted_qty,
                    sourceType: GoodsReceipt::class,
                    sourceId: (int) $grn->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    unitCost: (string) $line->unit_cost,
                    movedAt: $grn->receipt_date?->toDateTimeString() ?? now()->toDateTimeString(),
                    expiryDate: $line->expiry_date ? (string) $line->expiry_date : null,
                );
            }

            if ($movements === []) {
                throw new RuntimeException('GRN has no accepted quantity to receive.');
            }

            $this->ledger->post($movements, $this->postNamespace($grn), [
                'action' => 'goods_receipt.post',
                'entity_type' => 'goods_receipt',
                'entity_id' => $grn->id,
                'document_ref' => $grn->grn_number,
            ]);

            $this->applyToPurchaseOrder($grn);

            $grn->status = 'posted';
            $grn->posted_at = now();
            $grn->posted_by = auth()->id();
            $grn->posted_guard_key = $this->postNamespace($grn);
            $grn->markSystemTransition()->save();

            $this->outbox->record('grn.posted', $grn, 'goods_receipt', $grn->grn_number, (string) $grn->receipt_date);

            return $grn;
        });
    }

    /** Roll PO line received_qty forward and recompute PO status. */
    private function applyToPurchaseOrder(GoodsReceipt $grn): void
    {
        if (! $grn->purchase_order_id) {
            return;
        }
        $po = PurchaseOrder::query()->with('lines')->find($grn->purchase_order_id);
        if (! $po) {
            return;
        }

        foreach ($grn->lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $poLine = $po->lines->firstWhere('id', $line->purchase_order_line_id);
            if ($poLine) {
                $poLine->received_qty = Decimal::qty(Decimal::add((string) $poLine->received_qty, (string) $line->accepted_qty));
                $poLine->save();
            }
        }

        $allReceived = $po->lines->every(fn ($l) => Decimal::gte((string) $l->received_qty, (string) $l->ordered_qty));
        $anyReceived = $po->lines->contains(fn ($l) => Decimal::gt((string) $l->received_qty, '0'));
        $po->status = $allReceived ? 'received' : ($anyReceived ? 'partially_received' : $po->status);
        $po->save();
    }
}
