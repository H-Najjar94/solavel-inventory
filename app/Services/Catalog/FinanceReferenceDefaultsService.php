<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceReferenceDefaultsService
{
    public const VERSION = 'solabooks-inventory-reference-defaults.v1';

    private const UNITS = [
        ['Piece', 'pcs', 'count'], ['Box', 'bx', 'count'], ['Pack', 'pk', 'count'], ['Dozen', 'dz', 'count'], ['Unit', 'u', 'count'], ['Set', 'set', 'count'],
        ['Millimeter', 'mm', 'length'], ['Centimeter', 'cm', 'length'], ['Meter', 'm', 'length'], ['Kilometer', 'km', 'length'], ['Inch', 'in', 'length'], ['Foot', 'ft', 'length'], ['Yard', 'yd', 'length'], ['Mile', 'mi', 'length'],
        ['Square Meter', 'm²', 'count'], ['Square Foot', 'ft²', 'count'],
        ['Milliliter', 'ml', 'volume'], ['Centiliter', 'cl', 'volume'], ['Liter', 'ltr', 'volume'], ['Cubic Meter', 'm³', 'volume'], ['Cubic Foot', 'ft³', 'volume'], ['Gallon', 'gal', 'volume'],
        ['Milligram', 'mg', 'weight'], ['Gram', 'g', 'weight'], ['Kilogram', 'kg', 'weight'], ['Ton', 't', 'weight'], ['Pound', 'lb', 'weight'], ['Ounce', 'oz', 'weight'],
        ['Second', 's', 'count'], ['Minute', 'min', 'count'], ['Hour', 'h', 'count'], ['Day', 'd', 'count'],
        ['Byte', 'B', 'count'], ['Kilobyte', 'KB', 'count'], ['Megabyte', 'MB', 'count'], ['Gigabyte', 'GB', 'count'], ['Terabyte', 'TB', 'count'],
    ];

    public function sync(int $organizationId, bool $apply = false): array
    {
        if ($organizationId <= 0 || ! Schema::connection('tenant')->hasTable('inventory_units')
            || ! Schema::connection('tenant')->hasTable('units')) {
            return ['version' => self::VERSION, 'organization_id' => $organizationId,
                'status' => 'reference_tables_unavailable', 'units' => ['created' => 0, 'existing' => 0],
                'categories' => ['created' => 0, 'existing' => 0]];
        }

        $financeUnits = DB::connection('tenant')->table('inventory_units')->get()->keyBy(
            fn ($unit) => mb_strtolower(trim((string) ($unit->symbol ?? '')))
        );
        $result = ['version' => self::VERSION, 'organization_id' => $organizationId,
            'units' => ['created' => 0, 'existing' => 0, 'missing_in_finance' => []],
            'categories' => ['created' => 0, 'existing' => 0, 'policy' => 'organization_owned_not_seeded']];

        DB::connection('tenant')->transaction(function () use ($organizationId, $apply, $financeUnits, &$result): void {
            foreach (self::UNITS as [$name, $symbol, $kind]) {
                if (! $financeUnits->has(mb_strtolower($symbol))) {
                    $result['units']['missing_in_finance'][] = $symbol;
                    continue;
                }
                $code = mb_strtoupper($symbol);
                $existing = DB::connection('tenant')->table('units')->where('organization_id', $organizationId)
                    ->where(fn ($query) => $query->where('code', $code)->orWhere('symbol', $symbol)->orWhere('name', $name))->first();
                if ($existing) {
                    $result['units']['existing']++;
                } elseif ($apply) {
                    DB::connection('tenant')->table('units')->insert(['organization_id' => $organizationId, 'code' => $code,
                        'name' => $name, 'symbol' => $symbol, 'kind' => $kind, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now()]);
                    $result['units']['created']++;
                }
            }

        });

        return $result;
    }
}
