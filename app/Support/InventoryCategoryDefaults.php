<?php

namespace App\Support;

/** Stable cross-product category contract; must match SolaBooks byte-for-byte by name. */
final class InventoryCategoryDefaults
{
    public const VERSION = 'sola-inventory-category-defaults.v2';

    /** @return list<array{name: string, description: string}> */
    public static function all(): array
    {
        return [
            ['name' => 'General Merchandise', 'description' => 'General goods that do not yet need a more specific category'],
            ['name' => 'Food & Beverages', 'description' => 'Food products, ingredients, and beverages'],
            ['name' => 'Apparel & Accessories', 'description' => 'Clothing, footwear, textiles, and accessories'],
            ['name' => 'Electronics & Appliances', 'description' => 'Electronic equipment, devices, and appliances'],
            ['name' => 'Home, Furniture & Garden', 'description' => 'Home, furniture, décor, and garden products'],
            ['name' => 'Health, Beauty & Personal Care', 'description' => 'Health, beauty, hygiene, and personal-care products'],
            ['name' => 'Office, School & Stationery', 'description' => 'Office, school, printing, and stationery supplies'],
            ['name' => 'Automotive Parts & Accessories', 'description' => 'Vehicle parts, accessories, and maintenance products'],
            ['name' => 'Tools, Hardware & Building Supplies', 'description' => 'Tools, hardware, electrical, plumbing, and building supplies'],
            ['name' => 'Industrial & Maintenance Supplies', 'description' => 'Industrial components, equipment, and maintenance supplies'],
            ['name' => 'Raw Materials', 'description' => 'Materials consumed in manufacturing or production'],
            ['name' => 'Packaging & Consumables', 'description' => 'Packaging, disposable supplies, and operating consumables'],
            ['name' => 'Finished Goods', 'description' => 'Completed goods held for sale or distribution'],
            ['name' => 'Services & Non-Inventory', 'description' => 'Services and records that do not represent stocked goods'],
            ['name' => 'Other Inventory', 'description' => 'Inventory that does not fit another current category'],
        ];
    }
}
