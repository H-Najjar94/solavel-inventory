<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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

    private const CATEGORIES = [
        ['Electrical', ['Switches', 'Cables']],
        ['Plumbing', ['Valves', 'Pipes']],
        ['Mechanical', ['Bearings', 'Bolts']],
    ];

    public function sync(int $organizationId, bool $apply = false): array
    {
        if ($organizationId <= 0 || ! Schema::connection('tenant')->hasTable('inventory_units')
            || ! Schema::connection('tenant')->hasTable('inventory_categories')
            || ! Schema::connection('tenant')->hasTable('units')
            || ! Schema::connection('tenant')->hasTable('item_categories')) {
            throw new RuntimeException('Finance and SolaStock reference tables are required.');
        }

        $financeUnits = DB::connection('tenant')->table('inventory_units')->get()->keyBy(
            fn ($unit) => mb_strtolower(trim((string) ($unit->symbol ?? '')))
        );
        $financeCategoryNames = DB::connection('tenant')->table('inventory_categories')
            ->pluck('name')->map(fn ($name) => mb_strtolower(trim((string) $name)))->unique()->flip();
        $result = ['version' => self::VERSION, 'organization_id' => $organizationId,
            'units' => ['created' => 0, 'existing' => 0, 'missing_in_finance' => []],
            'categories' => ['created' => 0, 'existing' => 0, 'missing_in_finance' => []]];

        DB::connection('tenant')->transaction(function () use ($organizationId, $apply, $financeUnits, $financeCategoryNames, &$result): void {
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

            foreach (self::CATEGORIES as [$parentName, $children]) {
                if (! $financeCategoryNames->has(mb_strtolower($parentName))) {
                    $result['categories']['missing_in_finance'][] = $parentName;
                    continue;
                }
                $parent = DB::connection('tenant')->table('item_categories')->where('organization_id', $organizationId)
                    ->whereNull('parent_id')->where('name', $parentName)->first();
                if ($parent) {
                    $result['categories']['existing']++;
                } elseif ($apply) {
                    $parentId = DB::connection('tenant')->table('item_categories')->insertGetId([
                        'organization_id' => $organizationId, 'parent_id' => null, 'name' => $parentName,
                        'level' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $parent = (object) ['id' => $parentId];
                    $result['categories']['created']++;
                }
                foreach ($children as $childName) {
                    if (! $financeCategoryNames->has(mb_strtolower($childName))) {
                        $result['categories']['missing_in_finance'][] = $childName;
                        continue;
                    }
                    $child = $parent ? DB::connection('tenant')->table('item_categories')->where('organization_id', $organizationId)
                        ->where('parent_id', $parent->id)->where('name', $childName)->first() : null;
                    if ($child) {
                        $result['categories']['existing']++;
                    } elseif ($apply && $parent) {
                        DB::connection('tenant')->table('item_categories')->insert(['organization_id' => $organizationId,
                            'parent_id' => $parent->id, 'name' => $childName, 'level' => 1, 'is_active' => true,
                            'created_at' => now(), 'updated_at' => now()]);
                        $result['categories']['created']++;
                    }
                }
            }
        });

        return $result;
    }
}
