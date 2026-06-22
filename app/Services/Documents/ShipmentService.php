<?php

namespace App\Services\Documents;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shipment;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\StockReservationService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shipment — the physical stock-OUT event of fulfillment. Posting:
 *   1. emits OUT ledger movements via StockLedgerService (COGS computed by engine)
 *   2. consumes the sales-order reservation (releases the hold; on_hand drops)
 *   3. rolls up sales-order shipped_qty/status
 *   4. records a shipment.posted outbox event (COGS hints; no journal entry)
 * Never writes stock tables directly; never creates invoices/accounting.
 */
class ShipmentService
{
    public function __construct(
        private OrganizationContext $context,
        private StockLedgerService $ledger,
        private StockReservationService $reservations,
        private IntegrationOutboxService $outbox,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    private function postNamespace(Shipment $s): string
    {
        return 'shipment:'.$s->id.':post';
    }

    public function createDraft(array $attributes, array $lines): Shipment
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            $s = new Shipment(array_merge([
                'status' => 'draft', 'ship_date' => $attributes['ship_date'] ?? now()->toDateString(),
            ], $attributes));
            $s->organization_id = $orgId;
            $s->save();
            $this->syncLines($s, $lines, $orgId);

            return $s->fresh('lines');
        });
    }

    /** Build a draft shipment from a sales order's outstanding (ordered − shipped). */
    public function fromSalesOrder(SalesOrder $so): array
    {
        $so->loadMissing('lines');

        return $so->lines->map(function ($l) use ($so) {
            $remaining = Decimal::qty(Decimal::sub((string) $l->ordered_qty, (string) $l->shipped_qty));

            return [
                'sales_order_line_id' => $l->id,
                'item_id' => $l->item_id,
                'warehouse_id' => $l->warehouse_id ?? $so->warehouse_id,
                'bin_id' => $l->bin_id,
                'quantity' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
            ];
        })->filter(fn ($l) => Decimal::gt((string) $l['quantity'], '0'))->values()->all();
    }

    public function updateDraft(Shipment $s, array $attributes, array $lines): Shipment
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($s, $attributes, $lines, $orgId) {
            $s = Shipment::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->status !== 'draft') {
                throw new RuntimeException("Only a draft shipment can be edited (status '{$s->status}').");
            }
            $s->fill(collect($attributes)->only(['shipment_number', 'sales_order_id', 'pack_id', 'ship_date', 'warehouse_id', 'carrier', 'tracking_number', 'notes'])->toArray());
            $s->save();
            $s->lines()->delete();
            $this->syncLines($s, $lines, $orgId);

            return $s->fresh('lines');
        });
    }

    /**
     * Post a shipment. $overrides may carry permission-gated trace overrides
     * resolved by the controller: ['allow_expired_lot' => bool, 'allow_quarantined_lot' => bool].
     */
    public function post(Shipment $s, array $overrides = []): Shipment
    {
        $allowExpired = (bool) ($overrides['allow_expired_lot'] ?? false);
        $allowQuarantined = (bool) ($overrides['allow_quarantined_lot'] ?? false);

        return DB::connection($this->conn())->transaction(function () use ($s, $allowExpired, $allowQuarantined) {
            $s = Shipment::query()->lockForUpdate()->with('lines')->findOrFail($s->id);
            if ($s->status === 'posted') {
                return $s; // idempotent
            }
            if ($s->status !== 'draft') {
                throw new RuntimeException("Shipment {$s->id} cannot be posted from status '{$s->status}'.");
            }

            // Release the SO reservation first so the OUT does not trip the
            // negative-stock check against still-reserved quantity.
            if ($s->sales_order_id) {
                $this->reservations->consume('sales_order', (int) $s->sales_order_id);
            }

            $movements = [];
            foreach ($s->lines as $line) {
                if (! Decimal::gt((string) $line->quantity, '0')) {
                    continue;
                }
                $movements[] = new StockMovement(
                    direction: 'out',
                    itemId: (int) $line->item_id,
                    warehouseId: (int) $s->warehouse_id,
                    quantity: (string) $line->quantity,
                    sourceType: Shipment::class,
                    sourceId: (int) $s->id,
                    sourceLineId: (int) $line->id,
                    variantId: $line->variant_id ? (int) $line->variant_id : null,
                    binId: $line->bin_id ? (int) $line->bin_id : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    serialId: $line->serial_id ? (int) $line->serial_id : null,
                    movedAt: $s->ship_date?->toDateTimeString() ?? now()->toDateTimeString(),
                    allowExpiredLot: $allowExpired,
                    allowQuarantinedLot: $allowQuarantined,
                );
            }
            if ($movements === []) {
                throw new RuntimeException('Shipment has no quantity to ship.');
            }

            $this->ledger->post($movements, $this->postNamespace($s), [
                'action' => 'shipment.post', 'entity_type' => 'shipment',
                'entity_id' => $s->id, 'document_ref' => $s->shipment_number,
            ]);

            $this->rollUpSalesOrder($s);

            $s->status = 'posted';
            $s->posted_at = now();
            $s->posted_by = auth()->id();
            $s->posted_guard_key = $this->postNamespace($s);
            $s->markSystemTransition()->save();

            $this->outbox->record('shipment.posted', $s, 'shipment', $s->shipment_number, (string) $s->ship_date);

            return $s->fresh('lines');
        });
    }

    private function rollUpSalesOrder(Shipment $s): void
    {
        if (! $s->sales_order_id) {
            return;
        }
        $so = SalesOrder::query()->with('lines')->find($s->sales_order_id);
        if (! $so) {
            return;
        }
        foreach ($s->lines as $line) {
            if (! $line->sales_order_line_id) {
                continue;
            }
            $soLine = $so->lines->firstWhere('id', $line->sales_order_line_id);
            if ($soLine) {
                $soLine->shipped_qty = Decimal::qty(Decimal::add((string) $soLine->shipped_qty, (string) $line->quantity));
                $soLine->save();
            }
        }
        $allShipped = $so->lines->every(fn ($l) => Decimal::gte((string) $l->shipped_qty, (string) $l->ordered_qty));
        $anyShipped = $so->lines->contains(fn ($l) => Decimal::gt((string) $l->shipped_qty, '0'));
        $so->status = $allShipped ? 'shipped' : ($anyShipped ? 'partially_shipped' : $so->status);
        $so->save();
    }

    private function syncLines(Shipment $s, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $s->lines()->create([
                'organization_id' => $orgId,
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $s->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'quantity' => Decimal::qty((string) $line['quantity']),
                'lot_id' => $line['lot_id'] ?? null,
                'serial_id' => $line['serial_id'] ?? null,
            ]);
        }
    }
}
