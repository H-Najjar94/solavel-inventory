<?php

namespace App\Services\Documents;

use App\Models\Tenant\SalesOrder;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockReservationService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales fulfillment order. A fulfillment document only — never creates invoices
 * or accounting. Confirming allows reservation; reservation goes through
 * StockReservationService (no ledger). Shipping (separate service) is the OUT.
 */
class SalesOrderService
{
    public function __construct(
        private OrganizationContext $context,
        private StockReservationService $reservations,
        private IntegrationOutboxService $outbox,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function createDraft(array $attributes, array $lines): SalesOrder
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            $so = new SalesOrder(array_merge([
                'status' => 'draft', 'source_app' => 'manual',
                'order_date' => $attributes['order_date'] ?? now()->toDateString(),
            ], $attributes));
            $so->organization_id = $orgId;
            $so->save();
            $this->syncLines($so, $lines, $orgId);

            return $so->fresh('lines');
        });
    }

    public function updateDraft(SalesOrder $so, array $attributes, array $lines): SalesOrder
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($so, $attributes, $lines, $orgId) {
            $so = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            if ($so->status !== 'draft') {
                throw new RuntimeException("Only a draft sales order can be edited (status '{$so->status}').");
            }
            $so->fill(collect($attributes)->only(['order_number', 'customer_name', 'customer_external_id', 'order_date', 'requested_ship_date', 'warehouse_id', 'notes'])->toArray());
            $so->save();
            $so->lines()->delete();
            $this->syncLines($so, $lines, $orgId);

            return $so->fresh('lines');
        });
    }

    public function confirm(SalesOrder $so): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so) {
            $so = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            if ($so->status !== 'draft') {
                throw new RuntimeException("Only a draft sales order can be confirmed (status '{$so->status}').");
            }
            $so->status = 'confirmed';
            $so->save();
            $this->outbox->record('sales_order.confirmed', $so, 'sales_order', $so->order_number, (string) $so->order_date);

            return $so->fresh('lines');
        });
    }

    /** Reserve stock for every line (idempotent). Updates SO + line status. */
    public function reserve(SalesOrder $so): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so) {
            $so = SalesOrder::query()->lockForUpdate()->with('lines')->findOrFail($so->id);
            if (! in_array($so->status, ['confirmed', 'partially_reserved', 'reserved'], true)) {
                throw new RuntimeException("Sales order must be confirmed before reserving (status '{$so->status}').");
            }

            $allReserved = true;
            foreach ($so->lines as $line) {
                $res = $this->reservations->reserve(
                    (int) $line->item_id, (int) ($line->warehouse_id ?? $so->warehouse_id),
                    (string) $line->ordered_qty, 'sales_order', (int) $so->id,
                    $line->bin_id ? (int) $line->bin_id : null
                );
                $line->reserved_qty = $res->qty;
                $line->save();
                if (Decimal::lt((string) $line->reserved_qty, (string) $line->ordered_qty)) {
                    $allReserved = false;
                }
            }

            $so->status = $allReserved ? 'reserved' : 'partially_reserved';
            $so->save();
            $this->outbox->record('stock_reserved', $so, 'sales_order', $so->order_number, (string) $so->order_date);

            return $so->fresh('lines');
        });
    }

    public function releaseReservation(SalesOrder $so): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so) {
            $so = SalesOrder::query()->lockForUpdate()->with('lines')->findOrFail($so->id);
            $this->reservations->release('sales_order', (int) $so->id);
            foreach ($so->lines as $line) { $line->reserved_qty = '0'; $line->save(); }
            $so->status = 'confirmed';
            $so->save();
            $this->outbox->record('stock_reservation_released', $so, 'sales_order', $so->order_number, (string) $so->order_date);

            return $so->fresh('lines');
        });
    }

    public function cancel(SalesOrder $so): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so) {
            $so = SalesOrder::query()->lockForUpdate()->findOrFail($so->id);
            if (in_array($so->status, ['shipped', 'cancelled'], true)) {
                throw new RuntimeException("A {$so->status} sales order cannot be cancelled.");
            }
            $this->reservations->release('sales_order', (int) $so->id);
            $so->status = 'cancelled';
            $so->save();

            return $so->fresh('lines');
        });
    }

    private function syncLines(SalesOrder $so, array $lines, int $orgId): void
    {
        foreach ($lines as $line) {
            $so->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $so->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'ordered_qty' => Decimal::qty((string) $line['ordered_qty']),
                'unit_price' => Decimal::cost((string) ($line['unit_price'] ?? '0')),
                'status' => 'open',
            ]);
        }
    }
}
