<?php

namespace App\Services\Tax;

use App\Models\Tenant\InventorySetting;
use App\Services\Stock\Support\Decimal;
use Illuminate\Validation\ValidationException;

class InventoryTaxService
{
    public function definitions(): array
    {
        return array_values((array) (InventorySetting::query()->first()?->taxes ?? []));
    }

    public function resolve(?string $code, ?string $rate, string $use): array
    {
        if ($code === null || $code === '') {
            return ['code' => null, 'treatment' => 'standard', 'rate' => Decimal::cost($rate ?? '0')];
        }
        $tax = collect($this->definitions())->firstWhere('code', $code);
        if (! $tax && $rate !== null && $this->definitions() === []) {
            return ['code' => $code, 'treatment' => 'standard', 'rate' => Decimal::cost($rate)];
        }
        if (! $tax || ! ($tax['active'] ?? false) || ! ($tax[$use] ?? false)) {
            throw ValidationException::withMessages(['tax_code' => __('inventory.validation.tax_inactive')]);
        }

        $resolvedRate = in_array($tax['treatment'] ?? 'standard', ['zero', 'exempt'], true)
            ? '0'
            : (string) $tax['rate'];

        return [
            'code' => $tax['code'],
            'treatment' => $tax['treatment'] ?? 'standard',
            'rate' => Decimal::cost($resolvedRate),
        ];
    }

    public function amount(string $base, string $rate): string
    {
        return Decimal::money(Decimal::div(Decimal::mul($base, $rate), '100'));
    }
}
