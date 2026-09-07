<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationSetting;
use App\Services\Stock\Support\Decimal;
use Illuminate\Validation\ValidationException;

/** New connected movements use one base-currency cost pool, independent of sales currency. */
final class FinanceBaseValuation
{
    public const BASIS = 'finance_base.v1';

    public function contract(int $organizationId): ?array
    {
        $setting = IntegrationSetting::query()->where('integration', IntegrationEvents::INTEGRATION)
            ->where('organization_id', $organizationId)
            ->whereIn('mode', ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'])->first();
        if (! $setting) {
            return null;
        }
        $contract = (array) data_get($setting->meta, 'finance_currency_contract', []);
        // Selection of this basis requires a reviewed opening valuation/cutoff.
        // Deployment must never reinterpret an existing mixed-currency cost pool.
        if (($contract['inventory_valuation_basis'] ?? null) !== self::BASIS) {
            throw ValidationException::withMessages(['valuation' => 'Reviewed Finance-base inventory valuation and cutoff are required before connected stock posting.']);
        }
        if ((int) ($contract['money_scale'] ?? -1) !== 2) {
            throw ValidationException::withMessages(['valuation' => 'The current Stock ledger supports base money scale 2. A qualified schema upgrade is required for another base scale.']);
        }
        return $contract;
    }

    public function receiptUnitCost(object $receipt, string $transactionUnitCost): string
    {
        if ($this->contract((int) $receipt->organization_id) === null) {
            return $transactionUnitCost;
        }
        $currency = app(WorkflowCurrencyResolver::class)->resolve($receipt, 'goods_receipt', $receipt->receipt_date?->toDateString());
        return self::toBaseCost($transactionUnitCost, (string) $currency['exchange_rate']);
    }

    public static function toBaseCost(string $transactionCost, string $rate): string
    {
        if (Decimal::cmp($rate, '0') <= 0) {
            throw new \InvalidArgumentException('A positive authoritative exchange rate is required.');
        }
        return Decimal::cost(Decimal::div($transactionCost, $rate));
    }
}
