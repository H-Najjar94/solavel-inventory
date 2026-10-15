<?php

namespace App\Services\Integration;

use App\Services\Stock\Support\Decimal;
use RuntimeException;

/** Finance presentation/posting precision is independent of native Stock cost precision. */
final class FinanceMoneyPrecision
{
    public static function supported(mixed $scale): bool
    {
        return in_array($scale, [2, 3, '2', '3'], true);
    }

    public static function reversalContract(array $current, ?array $original, string $trustedVersion): array
    {
        $currency = $original['currency'] ?? null;
        if (($original['version'] ?? null) !== $trustedVersion || !is_array($currency)
            || !self::supported($currency['money_scale'] ?? null)
            || !is_int($currency['rate_scale'] ?? null)
            || $currency['rate_scale'] < 0 || $currency['rate_scale'] > 10
            || ($currency['rounding_mode'] ?? null) !== 'HALF_UP'
            || ($currency['base_code'] ?? null) !== ($current['base_currency_code'] ?? null)) {
            throw new RuntimeException('A trusted original journal precision snapshot is required for reversal.');
        }
        return array_replace($current, ['money_scale'=>$currency['money_scale'], 'rate_scale'=>$currency['rate_scale']]);
    }

    public static function assertBalancedInventory(array $lines, int $scale): void
    {
        if (!self::supported($scale)) throw new RuntimeException('Unsupported Finance money precision.');
        $debit = $credit = '0';
        foreach ($lines as $line) {
            $debit = Decimal::add($debit, (string)$line['base_debit']);
            $credit = Decimal::add($credit, (string)$line['base_credit']);
        }
        if (!Decimal::isZero(Decimal::sub($debit, $credit), $scale)) {
            throw new RuntimeException('Inventory carrying values do not balance exactly; automatic rounding changes are prohibited.');
        }
    }
}
