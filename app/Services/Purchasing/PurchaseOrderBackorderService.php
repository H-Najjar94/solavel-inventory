<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderBackorder;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Carbon;

class PurchaseOrderBackorderService
{
    public function __construct(private OrganizationContext $context) {}

    public function refresh(PurchaseOrder $po): void
    {
        $orgId = $this->context->idOrFail();
        $po->loadMissing('lines');

        foreach ($po->lines as $line) {
            $remaining = Decimal::qty(Decimal::sub((string) $line->ordered_qty, (string) $line->received_qty));
            $hasBackorder = Decimal::gt($remaining, '0')
                && in_array($po->status, ['approved', 'partially_received'], true);

            if ($hasBackorder) {
                PurchaseOrderBackorder::query()->updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'purchase_order_line_id' => $line->id,
                    ],
                    [
                        'purchase_order_id' => $po->id,
                        'item_id' => $line->item_id,
                        'warehouse_id' => $po->warehouse_id,
                        'supplier_id' => $po->supplier_id,
                        'ordered_qty' => Decimal::qty((string) $line->ordered_qty),
                        'received_qty' => Decimal::qty((string) $line->received_qty),
                        'backorder_qty' => $remaining,
                        'unit_price' => Decimal::cost((string) $line->unit_price),
                        'expected_date' => $line->expected_date ?? $po->expected_date,
                        'status' => 'open',
                        'opened_at' => now(),
                        'closed_at' => null,
                    ],
                );

                continue;
            }

            $status = $po->status === 'cancelled' ? 'cancelled' : 'closed';
            PurchaseOrderBackorder::query()
                ->where('organization_id', $orgId)
                ->where('purchase_order_line_id', $line->id)
                ->where('status', 'open')
                ->update([
                    'received_qty' => Decimal::qty((string) $line->received_qty),
                    'backorder_qty' => Decimal::qty(Decimal::lt($remaining, '0') ? '0' : $remaining),
                    'status' => $status,
                    'closed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        }
    }
}
