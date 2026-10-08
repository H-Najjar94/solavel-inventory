<?php

namespace App\Services\FinancialOrigins;

use App\Services\Stock\Support\Decimal;
use DomainException;

/** Quantity admission only: cancellation never manufactures a physical return. */
final class CashPartialQuantityPlan
{
    /** All quantities use the durable Finance source unit, not Stock's base unit. */
    public function split(array $source, string $refundQuantity, string $physicalReturnQuantity): array
    {
        foreach (['requested', 'fulfilled', 'cancelled', 'refunded_unshipped', 'refunded_shipped', 'returned', 'held'] as $field) {
            if (!isset($source[$field]) || Decimal::cmp((string) $source[$field], '0') < 0) {
                throw new DomainException('cash_source_quantity_invalid');
            }
        }
        if (Decimal::cmp(Decimal::qty($refundQuantity), $refundQuantity) !== 0
            || Decimal::cmp(Decimal::qty($physicalReturnQuantity), $physicalReturnQuantity) !== 0
            || Decimal::cmp($refundQuantity, '0') <= 0 || Decimal::cmp($physicalReturnQuantity, '0') < 0) {
            throw new DomainException('cash_refund_quantity_invalid');
        }
        $remainingDemand = Decimal::sub(Decimal::sub($source['requested'], $source['fulfilled']), $source['cancelled']);
        $unrefunded = Decimal::sub($source['requested'], Decimal::add($source['refunded_unshipped'], $source['refunded_shipped']));
        $returnable = Decimal::sub($source['fulfilled'], $source['returned']);
        if (Decimal::cmp($remainingDemand, '0') < 0 || Decimal::cmp($returnable, '0') < 0
            || Decimal::cmp($source['refunded_unshipped'], $source['cancelled']) > 0
            || Decimal::cmp($source['refunded_shipped'], $source['fulfilled']) > 0
            || Decimal::cmp($source['held'], '0') > 0
            || Decimal::cmp($refundQuantity, $unrefunded) > 0
            || Decimal::cmp($physicalReturnQuantity, $returnable) > 0) {
            throw new DomainException('cash_source_quantity_exceeded');
        }
        // Refund deferred quantity first. A refunded shipped quantity does NOT prove arrival back.
        $availableDemand = Decimal::sub($remainingDemand, $source['held']);
        $cancel = Decimal::cmp($refundQuantity, $availableDemand) <= 0 ? $refundQuantity : $availableDemand;
        $released = Decimal::sub($refundQuantity, $cancel);
        if (Decimal::cmp($released, Decimal::sub($source['fulfilled'], $source['refunded_shipped'])) > 0) {
            throw new DomainException('cash_refund_source_overlap');
        }
        return [
            'cancel_unshipped_quantity' => Decimal::qty($cancel),
            'refund_shipped_quantity' => Decimal::qty($released),
            'physical_return_quantity' => Decimal::qty($physicalReturnQuantity),
            'remaining_dispatch_quantity' => Decimal::qty(Decimal::sub($availableDemand, $cancel)),
        ];
    }
}
