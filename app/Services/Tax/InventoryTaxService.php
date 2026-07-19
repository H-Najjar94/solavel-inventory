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
            return ['code' => null, 'rate' => Decimal::cost($rate ?? '0')];
        }
        $tax = collect($this->definitions())->firstWhere('code', $code);
        if (! $tax && $rate !== null) {
            return ['code' => $code, 'rate' => Decimal::cost($rate)];
        }
        if (! $tax || ! ($tax['active'] ?? false) || ! ($tax[$use] ?? false)) {
            throw ValidationException::withMessages(['tax_code' => 'The selected tax is inactive or not applicable.']);
        }

        return ['code' => $tax['code'], 'rate' => Decimal::cost((string) $tax['rate'])];
    }

    public function amount(string $base, string $rate): string
    {
        return Decimal::money(Decimal::div(Decimal::mul($base, $rate), '100'));
    }
}
