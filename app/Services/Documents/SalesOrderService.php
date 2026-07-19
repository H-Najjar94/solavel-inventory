<?php

namespace App\Services\Documents;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Item;
use App\Models\Tenant\SalesOrder;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Integration\IntegrationOutboxService;
use App\Services\Stock\StockReservationService;
use App\Services\Stock\Support\Decimal;
use App\Services\Tax\InventoryTaxService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Carbon;
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
        private InventoryTaxService $taxes,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function createDraft(array $attributes, array $lines): SalesOrder
    {
        $orgId = $this->context->idOrFail();

        return DB::connection($this->conn())->transaction(function () use ($attributes, $lines, $orgId) {
            // Server-issued order number when none was supplied (users don't type it).
            $attributes['order_number'] = ! empty($attributes['order_number'])
                ? $attributes['order_number']
                : DocumentNumber::next('SO', SalesOrder::class, 'order_number', $orgId, $this->conn());

            // Default a missing/blank date (order_date is a NOT NULL column). Done here
            // rather than only in array_merge so a null in $attributes can't win.
            $attributes['order_date'] = $attributes['order_date'] ?? now()->toDateString();
            $attributes = $this->applyCustomerName($attributes);

            $so = new SalesOrder(array_merge([
                'status' => 'draft', 'source_app' => 'manual',
            ], $attributes));
            $so->organization_id = $orgId;
            $so->save();
            $this->syncLines($so, $lines, $orgId);
            $this->refreshTotals($so);

            return $so->fresh(['lines', 'customer']);
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
            $attributes = $this->applyCustomerName($attributes);
            $so->fill(collect($attributes)->only(['order_number', 'customer_id', 'customer_name', 'customer_external_id', 'order_date', 'requested_ship_date', 'warehouse_id', 'notes'])->toArray());
            $so->save();
            $so->lines()->delete();
            $this->syncLines($so, $lines, $orgId);
            $this->refreshTotals($so);

            return $so->fresh(['lines', 'customer']);
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
    public function reserve(SalesOrder $so, array $options = []): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so, $options) {
            $so = SalesOrder::query()->lockForUpdate()->with('lines')->findOrFail($so->id);
            if (! in_array($so->status, ['confirmed', 'partially_reserved', 'reserved'], true)) {
                throw new RuntimeException("Sales order must be confirmed before reserving (status '{$so->status}').");
            }

            $priority = max(1, min(999, (int) ($options['priority'] ?? 100)));
            $expiresAt = ! empty($options['expires_at']) ? Carbon::parse($options['expires_at']) : null;
            $allReserved = true;
            $anyReserved = false;
            foreach ($so->lines->sortBy([
                ['reserved_qty', 'asc'],
                ['id', 'asc'],
            ]) as $line) {
                if (! Decimal::gt((string) $line->ordered_qty, (string) $line->reserved_qty)) {
                    $anyReserved = true;

                    continue;
                }

                $needed = Decimal::sub((string) $line->ordered_qty, (string) $line->reserved_qty);
                $res = $this->reservations->reserveAvailableAcrossLots(
                    (int) $line->item_id, (int) ($line->warehouse_id ?? $so->warehouse_id),
                    $needed, 'sales_order', (int) $so->id,
                    $line->bin_id ? (int) $line->bin_id : null,
                    $expiresAt,
                    $priority
                );
                $allocated = collect($res)->reduce(
                    fn (string $total, $reservation): string => Decimal::add($total, (string) $reservation->qty),
                    '0',
                );
                $line->reserved_qty = Decimal::qty(Decimal::add((string) $line->reserved_qty, $allocated));
                $line->save();
                if (Decimal::lt((string) $line->reserved_qty, (string) $line->ordered_qty)) {
                    $allReserved = false;
                }
                if (Decimal::gt((string) $line->reserved_qty, '0')) {
                    $anyReserved = true;
                }
            }

            $so->status = $allReserved ? 'reserved' : ($anyReserved ? 'partially_reserved' : 'confirmed');
            $so->save();
            $this->outbox->record('stock_reserved', $so, 'sales_order', $so->order_number, (string) $so->order_date);

            return $so->fresh(['lines', 'reservations']);
        });
    }

    public function expireOverdueReservations(SalesOrder $so): SalesOrder
    {
        $this->reservations->expireOverdue('sales_order', (int) $so->id);

        return $so->fresh(['lines', 'reservations']);
    }

    public function releaseReservation(SalesOrder $so): SalesOrder
    {
        return DB::connection($this->conn())->transaction(function () use ($so) {
            $so = SalesOrder::query()->lockForUpdate()->with('lines')->findOrFail($so->id);
            $this->reservations->release('sales_order', (int) $so->id);
            foreach ($so->lines as $line) {
                $line->reserved_qty = '0';
                $line->save();
            }
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
        $items = Item::query()->whereIn('id', collect($lines)->pluck('item_id')->filter()->unique())->get(['id', 'sales_price', 'tax_code'])->keyBy('id');
        foreach ($lines as $line) {
            $item = $items[$line['item_id']] ?? null;
            $qty = Decimal::qty((string) $line['ordered_qty']);
            $unitPrice = Decimal::cost((string) ($line['unit_price'] ?? $item?->sales_price ?? '0'));
            $gross = Decimal::mul($qty, $unitPrice);
            $discountRate = Decimal::cost((string) ($line['discount_rate'] ?? '0'));
            $discountAmount = Decimal::money(Decimal::div(Decimal::mul($gross, $discountRate), '100'));
            $taxBase = Decimal::sub($gross, $discountAmount);
            $tax = $this->taxes->resolve($line['tax_code'] ?? $item?->tax_code, isset($line['tax_rate']) ? (string) $line['tax_rate'] : null, 'sales');
            $taxRate = $tax['rate'];
            $taxAmount = $this->taxes->amount($taxBase, $taxRate);
            $lineTotal = Decimal::money(Decimal::add($taxBase, $taxAmount));
            $so->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? $so->warehouse_id,
                'bin_id' => $line['bin_id'] ?? null,
                'ordered_qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_rate' => $discountRate,
                'discount_amount' => $discountAmount,
                'tax_code' => $tax['code'],
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'status' => 'open',
            ]);
        }
    }

    private function refreshTotals(SalesOrder $so): void
    {
        $so->loadMissing('lines');
        $gross = '0';
        $discount = '0';
        $tax = '0';
        $total = '0';
        foreach ($so->lines as $line) {
            $gross = Decimal::add($gross, Decimal::mul((string) $line->ordered_qty, (string) $line->unit_price));
            $discount = Decimal::add($discount, (string) ($line->discount_amount ?? '0'));
            $tax = Decimal::add($tax, (string) ($line->tax_amount ?? '0'));
            $total = Decimal::add($total, (string) ($line->line_total ?? '0'));
        }
        $so->subtotal = Decimal::money($gross);
        $so->discount_total = Decimal::money($discount);
        $so->tax_total = Decimal::money($tax);
        $so->total = Decimal::money($total);
        $so->save();
    }

    private function applyCustomerName(array $attributes): array
    {
        if (! empty($attributes['customer_id'])) {
            $customer = Customer::query()->find((int) $attributes['customer_id']);
            if ($customer) {
                $attributes['customer_name'] = $attributes['customer_name'] ?? $customer->name;
                $attributes['customer_external_id'] = $attributes['customer_external_id'] ?? $customer->code;
            }
        }

        return $attributes;
    }
}
