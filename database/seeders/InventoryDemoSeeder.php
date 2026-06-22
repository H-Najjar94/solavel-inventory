<?php

namespace Database\Seeders;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBrand;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\PackService;
use App\Services\Documents\PickListService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use App\Services\Documents\StockTransferService;
use App\Services\Tenancy\TenantManager;
use App\Services\Traceability\LotService;
use App\Services\Traceability\RecallService;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SolaStock demo seeder — populates the SAFE demo tenant with realistic, real
 * database data so the app runs without sample fallback.
 *
 * SAFETY (fail closed):
 *   - target org + database must be explicitly provided (env INVENTORY_DEMO_ORG /
 *     INVENTORY_DEMO_DB, defaulting to the configured demo tenant);
 *   - the database is checked against config('inventory.forbidden_demo_databases')
 *     AND an allow-list — it refuses Finance (tenant_990001), Projects
 *     (tenant_990002), and any production database;
 *   - ALL stock movement goes through the domain services
 *     (OpeningStock/Adjustment/GRN/Transfer/Count/Shipment/Return) →
 *     StockLedgerService. This seeder NEVER writes stock_ledger / stock_balances /
 *     cost_layers directly.
 *
 * Run via scripts/setup-demo-tenant.sh, or:
 *   INVENTORY_DEMO_ORG=990010 INVENTORY_DEMO_DB=tenant_990010 \
 *     php artisan db:seed --class=Database\\Seeders\\InventoryDemoSeeder
 */
class InventoryDemoSeeder extends Seeder
{
    /**
     * Databases this seeder is explicitly allowed to touch. tenant_000008 is the
     * internal/test "Solavel Internal Workspace" (operator-approved for SolaStock
     * demo data). The forbidden deny-list (Finance/Projects/production) still
     * applies on top of this allow-list.
     */
    private const ALLOWED_DEMO_DATABASES = ['tenant_990010', 'tenant_990011', 'tenant_000008'];

    public function run(): void
    {
        $orgId = (int) (env('INVENTORY_DEMO_ORG') ?: config('inventory.demo_tenant.organization_id'));
        $database = (string) (env('INVENTORY_DEMO_DB') ?: config('inventory.demo_tenant.database'));

        $this->assertSafeTarget($orgId, $database);

        // Activate the tenant connection + org context.
        app(TenantManager::class)->useTenant($orgId, $database);
        app(OrganizationContext::class)->set($orgId);

        if (Item::query()->count() > 0) {
            $this->command?->warn("Tenant {$database} already has items — demo seeding skipped (idempotent). Truncate the tenant DB to reseed.");

            return;
        }

        $this->command?->info("Seeding SolaStock demo into {$database} (org {$orgId})…");

        InventorySetting::query()->updateOrCreate(
            ['organization_id' => $orgId],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false, 'picking_policy' => 'manual']
        );

        [$cats, $brands, $units] = $this->masterData($orgId);
        $suppliers = $this->suppliers();
        [$wh1, $wh2, $bins] = $this->warehouses($orgId);
        $items = $this->items($cats, $brands, $units);

        $this->openingStock($wh1, $wh2, $bins, $items);
        $this->adjustment($wh1, $items);
        $this->purchaseToReceipt($wh1, $suppliers, $items);
        $this->transfer($wh1, $wh2, $bins, $items);
        $this->stockCount($wh1, $items);
        $this->salesFulfillment($wh1, $items);
        $this->recall($items);

        $this->command?->info("✓ SolaStock demo data seeded into {$database}.");
    }

    // ── Safety ────────────────────────────────────────────────────────────────

    private function assertSafeTarget(int $orgId, string $database): void
    {
        if ($orgId <= 0 || $database === '') {
            throw new RuntimeException('InventoryDemoSeeder requires an explicit demo org id + database.');
        }
        $forbidden = (array) config('inventory.forbidden_demo_databases', []);
        if (in_array($database, $forbidden, true)) {
            throw new RuntimeException("Refusing to seed FORBIDDEN database '{$database}' (Finance/Projects/production).");
        }
        if (! in_array($database, self::ALLOWED_DEMO_DATABASES, true)) {
            throw new RuntimeException(
                "Refusing to seed '{$database}': not in the demo allow-list ("
                .implode(', ', self::ALLOWED_DEMO_DATABASES).').'
            );
        }
    }

    // ── Master data ─────────────────────────────────────────────────────────

    private function masterData(int $orgId): array
    {
        $cats = [
            'electronics' => ItemCategory::query()->create(['name' => 'Electronics', 'is_active' => true]),
            'apparel' => ItemCategory::query()->create(['name' => 'Apparel', 'is_active' => true]),
            'food' => ItemCategory::query()->create(['name' => 'Food & Beverage', 'is_active' => true]),
            'services' => ItemCategory::query()->create(['name' => 'Services', 'is_active' => true]),
        ];
        $brands = [
            'aurora' => ItemBrand::query()->create(['name' => 'Aurora', 'is_active' => true]),
            'nimbus' => ItemBrand::query()->create(['name' => 'Nimbus', 'is_active' => true]),
        ];
        $units = [
            'ea' => Unit::query()->create(['code' => 'EA', 'name' => 'Each', 'symbol' => 'ea', 'kind' => 'count']),
            'box' => Unit::query()->create(['code' => 'BOX', 'name' => 'Box', 'symbol' => 'box', 'kind' => 'count']),
            'kg' => Unit::query()->create(['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'kind' => 'weight']),
        ];

        return [$cats, $brands, $units];
    }

    private function suppliers(): array
    {
        return [
            'globex' => Supplier::query()->create(['code' => 'SUP-GLOBEX', 'name' => 'Globex Trading', 'is_active' => true, 'contact' => ['email' => 'sales@globex.example']]),
            'initech' => Supplier::query()->create(['code' => 'SUP-INITECH', 'name' => 'Initech Supply Co', 'is_active' => true, 'contact' => ['email' => 'orders@initech.example']]),
        ];
    }

    private function warehouses(int $orgId): array
    {
        $wh1 = Warehouse::query()->create(['code' => 'WH-CENTRAL', 'name' => 'Central Fulfillment', 'type' => 'warehouse', 'is_active' => true]);
        $wh2 = Warehouse::query()->create(['code' => 'WH-RETAIL', 'name' => 'Downtown Retail', 'type' => 'retail', 'is_active' => true]);

        $z1 = WarehouseZone::query()->create(['warehouse_id' => $wh1->id, 'code' => 'Z-A', 'name' => 'Receiving', 'is_active' => true]);
        $z2 = WarehouseZone::query()->create(['warehouse_id' => $wh1->id, 'code' => 'Z-B', 'name' => 'Bulk Storage', 'is_active' => true]);
        $z3 = WarehouseZone::query()->create(['warehouse_id' => $wh2->id, 'code' => 'Z-S', 'name' => 'Shop Floor', 'is_active' => true]);

        $bins = [
            'a1' => WarehouseBin::query()->create(['warehouse_id' => $wh1->id, 'zone_id' => $z1->id, 'code' => 'A1-01', 'capacity' => '500', 'is_active' => true]),
            'b1' => WarehouseBin::query()->create(['warehouse_id' => $wh1->id, 'zone_id' => $z2->id, 'code' => 'B1-01', 'capacity' => '2000', 'is_active' => true]),
            's1' => WarehouseBin::query()->create(['warehouse_id' => $wh2->id, 'zone_id' => $z3->id, 'code' => 'S1-01', 'capacity' => '300', 'is_active' => true]),
        ];

        return [$wh1, $wh2, $bins];
    }

    private function items(array $cats, array $brands, array $units): array
    {
        $mk = fn (array $a) => Item::query()->create(array_merge([
            'item_type' => 'inventory', 'tracking_type' => 'none', 'base_unit_id' => $units['ea']->id,
            'costing_method' => 'average', 'is_active' => true, 'tracks_expiry' => false,
        ], $a));

        return [
            // normal items
            'headphones' => $mk(['sku' => 'AUR-HP-01', 'name' => 'Aurora Wireless Headphones', 'category_id' => $cats['electronics']->id, 'brand_id' => $brands['aurora']->id, 'purchase_price' => '45.00', 'sales_price' => '89.00', 'reorder_point' => '20', 'enable_reorder_alert' => true]),
            'tshirt' => $mk(['sku' => 'APP-TS-01', 'name' => 'Classic T-Shirt', 'category_id' => $cats['apparel']->id, 'costing_method' => 'fifo', 'purchase_price' => '6.00', 'sales_price' => '19.00', 'reorder_point' => '50']),
            'cable' => $mk(['sku' => 'AUR-CB-01', 'name' => 'USB-C Cable 2m', 'category_id' => $cats['electronics']->id, 'brand_id' => $brands['nimbus']->id, 'purchase_price' => '2.50', 'sales_price' => '9.00', 'reorder_point' => '100']),
            'mug' => $mk(['sku' => 'GEN-MUG-01', 'name' => 'Ceramic Mug', 'category_id' => $cats['apparel']->id, 'purchase_price' => '1.80', 'sales_price' => '7.50']),
            // low-stock candidate (small opening, high reorder point)
            'charger' => $mk(['sku' => 'AUR-CH-01', 'name' => 'Fast Charger 65W', 'category_id' => $cats['electronics']->id, 'brand_id' => $brands['aurora']->id, 'purchase_price' => '12.00', 'sales_price' => '29.00', 'reorder_point' => '40', 'enable_reorder_alert' => true]),
            // out-of-stock (no opening stock)
            'standlamp' => $mk(['sku' => 'NIM-LMP-01', 'name' => 'Desk Lamp', 'category_id' => $cats['electronics']->id, 'brand_id' => $brands['nimbus']->id, 'purchase_price' => '8.00', 'sales_price' => '24.00', 'reorder_point' => '15', 'enable_reorder_alert' => true]),
            // lot-tracked
            'coffee' => $mk(['sku' => 'FB-COF-01', 'name' => 'Single-Origin Coffee 1kg', 'category_id' => $cats['food']->id, 'tracking_type' => 'lot', 'base_unit_id' => $units['kg']->id, 'purchase_price' => '14.00', 'sales_price' => '32.00', 'reorder_point' => '30']),
            // lot + expiry tracked
            'vitamin' => $mk(['sku' => 'FB-VIT-01', 'name' => 'Vitamin C Tablets', 'category_id' => $cats['food']->id, 'tracking_type' => 'lot', 'tracks_expiry' => true, 'shelf_life_days' => 365, 'purchase_price' => '3.00', 'sales_price' => '11.00', 'reorder_point' => '25']),
            // serial-tracked
            'router' => $mk(['sku' => 'NIM-RT-01', 'name' => 'Nimbus Router X1', 'category_id' => $cats['electronics']->id, 'brand_id' => $brands['nimbus']->id, 'tracking_type' => 'serial', 'purchase_price' => '38.00', 'sales_price' => '79.00']),
            // service / non-inventory
            'install' => $mk(['sku' => 'SVC-INST-01', 'name' => 'On-site Installation', 'item_type' => 'service', 'category_id' => $cats['services']->id, 'purchase_price' => '0.00', 'sales_price' => '120.00']),
        ];
    }

    // ── Stock + operations (all via services) ─────────────────────────────────

    private function openingStock(Warehouse $wh1, Warehouse $wh2, array $bins, array $items): void
    {
        $svc = app(OpeningStockService::class);
        $e1 = $svc->createDraft(
            ['entry_number' => 'OPEN-0001', 'warehouse_id' => $wh1->id],
            [
                // Warehouse-level (no bin) so downstream adjustment/transfer/ship
                // OUT movements resolve the same balance coordinate.
                ['item_id' => $items['headphones']->id, 'quantity' => '120', 'unit_cost' => '45.00'],
                ['item_id' => $items['tshirt']->id, 'quantity' => '500', 'unit_cost' => '6.00'],
                ['item_id' => $items['cable']->id, 'quantity' => '800', 'unit_cost' => '2.50'],
                ['item_id' => $items['mug']->id, 'quantity' => '300', 'unit_cost' => '1.80'],
                ['item_id' => $items['charger']->id, 'quantity' => '8', 'unit_cost' => '12.00'], // low stock
                // lot-tracked coffee in two lots
                ['item_id' => $items['coffee']->id, 'quantity' => '40', 'unit_cost' => '14.00', 'lot_code' => 'COF-2026-A'],
                ['item_id' => $items['coffee']->id, 'quantity' => '25', 'unit_cost' => '14.50', 'lot_code' => 'COF-2026-B'],
                // lot+expiry vitamin: one near-expiry lot, one fresh
                ['item_id' => $items['vitamin']->id, 'quantity' => '60', 'unit_cost' => '3.00', 'lot_code' => 'VIT-NEAREXP', 'expiry_date' => now()->addDays(20)->toDateString()],
                ['item_id' => $items['vitamin']->id, 'quantity' => '90', 'unit_cost' => '3.10', 'lot_code' => 'VIT-FRESH', 'expiry_date' => now()->addDays(300)->toDateString()],
                // serial-tracked router: 5 serials
                ['item_id' => $items['router']->id, 'unit_cost' => '38.00', 'serials' => ['RT-0001', 'RT-0002', 'RT-0003', 'RT-0004', 'RT-0005']],
            ]
        );
        $svc->post($e1);

        // A little stock in the retail warehouse too.
        $e2 = $svc->createDraft(
            ['entry_number' => 'OPEN-0002', 'warehouse_id' => $wh2->id],
            [
                ['item_id' => $items['mug']->id, 'quantity' => '60', 'unit_cost' => '1.80', 'bin_id' => $bins['s1']->id],
                ['item_id' => $items['cable']->id, 'quantity' => '120', 'unit_cost' => '2.50', 'bin_id' => $bins['s1']->id],
            ]
        );
        $svc->post($e2);
    }

    private function adjustment(Warehouse $wh1, array $items): void
    {
        $svc = app(StockAdjustmentService::class);
        $adj = $svc->createDraft(
            ['adjustment_number' => 'ADJ-0001', 'warehouse_id' => $wh1->id, 'reason_code' => 'cycle_count'],
            [
                ['item_id' => $items['headphones']->id, 'direction' => 'decrease', 'quantity' => '3'],
                ['item_id' => $items['mug']->id, 'direction' => 'increase', 'quantity' => '15', 'unit_cost' => '1.80'],
            ]
        );
        $svc->post($adj);
    }

    private function purchaseToReceipt(Warehouse $wh1, array $suppliers, array $items): void
    {
        // PO (approved) → GRN (posted) for the out-of-stock lamp + a cable top-up.
        // forceFill bypasses the BelongsToOrganization fillable/guarded interplay
        // (the trait pins $fillable to organization_id), so all header fields persist.
        $orgId = app(OrganizationContext::class)->idOrFail();
        $po = (new PurchaseOrder)->forceFill([
            'organization_id' => $orgId, 'po_number' => 'PO-0001', 'supplier_id' => $suppliers['globex']->id,
            'warehouse_id' => $wh1->id, 'order_date' => now()->subDays(5)->toDateString(),
            'expected_date' => now()->addDays(2)->toDateString(), 'status' => 'draft', 'currency_code' => 'SAR',
        ]);
        $po->save();
        foreach ([['standlamp', '30', '8.00'], ['cable', '200', '2.40']] as [$k, $qty, $price]) {
            $line = (new \App\Models\Tenant\PurchaseOrderLine)->forceFill([
                'organization_id' => $orgId, 'purchase_order_id' => $po->id, 'item_id' => $items[$k]->id,
                'ordered_qty' => $qty, 'unit_price' => $price,
            ]);
            $line->save();
        }
        $po->forceFill(['status' => 'approved'])->save();
        $po->load('lines');

        $grnSvc = app(GoodsReceiptService::class);
        $grn = $grnSvc->createDraft(
            ['grn_number' => 'GRN-0001', 'purchase_order_id' => $po->id, 'supplier_id' => $suppliers['globex']->id, 'warehouse_id' => $wh1->id],
            [
                ['purchase_order_line_id' => $po->lines[0]->id, 'item_id' => $items['standlamp']->id, 'received_qty' => '30', 'accepted_qty' => '30', 'unit_cost' => '8.00'],
                ['purchase_order_line_id' => $po->lines[1]->id, 'item_id' => $items['cable']->id, 'received_qty' => '200', 'accepted_qty' => '200', 'unit_cost' => '2.40'],
            ]
        );
        $grnSvc->post($grn);
    }

    private function transfer(Warehouse $wh1, Warehouse $wh2, array $bins, array $items): void
    {
        $svc = app(StockTransferService::class);
        $t = $svc->createDraft(
            ['transfer_number' => 'TRF-0001', 'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id],
            [
                ['item_id' => $items['headphones']->id, 'quantity' => '20', 'to_bin_id' => $bins['s1']->id],
                ['item_id' => $items['tshirt']->id, 'quantity' => '100', 'to_bin_id' => $bins['s1']->id],
            ]
        );
        $svc->post($t);
    }

    private function stockCount(Warehouse $wh1, array $items): void
    {
        $svc = app(StockCountService::class);
        // Counted slightly under system for cable → negative variance → adjustment.
        $count = $svc->createDraft(
            ['count_number' => 'CNT-0001', 'count_type' => 'cycle', 'warehouse_id' => $wh1->id],
            [
                ['item_id' => $items['cable']->id, 'system_qty' => '1000', 'counted_qty' => '994'],
                ['item_id' => $items['mug']->id, 'system_qty' => '315', 'counted_qty' => '315'],
            ]
        );
        $svc->post($count);
    }

    private function salesFulfillment(Warehouse $wh1, array $items): void
    {
        $soSvc = app(SalesOrderService::class);
        $so = $soSvc->createDraft(
            ['order_number' => 'SO-0001', 'customer_name' => 'Acme Retail', 'warehouse_id' => $wh1->id],
            [
                ['item_id' => $items['headphones']->id, 'ordered_qty' => '10', 'unit_price' => '89.00'],
                ['item_id' => $items['tshirt']->id, 'ordered_qty' => '40', 'unit_price' => '19.00'],
            ]
        );
        $soSvc->confirm($so);
        $soSvc->reserve($so);

        // Pick → pack.
        $pickSvc = app(PickListService::class);
        $pick = $pickSvc->createFromSalesOrder($so, ['pick_number' => 'PICK-SO-0001']);
        $picks = [];
        foreach ($pick->lines as $pl) {
            $picks[$pl->id] = (string) $pl->reserved_qty;
        }
        $pickSvc->updatePicks($pick, $picks);
        $pickSvc->markPicked($pick);

        $packSvc = app(PackService::class);
        $pack = $packSvc->createFromPickList($pick, ['pack_number' => 'PACK-SO-0001']);
        $packs = [];
        foreach ($pack->lines as $pkl) {
            $packs[$pkl->id] = (string) $pkl->picked_qty;
        }
        $packSvc->updatePacks($pack, $packs);
        $packSvc->markPacked($pack);

        // Shipment (stock OUT) from the SO's outstanding lines.
        $shipSvc = app(ShipmentService::class);
        $shipLines = $shipSvc->fromSalesOrder($so->fresh('lines'));
        $shipment = $shipSvc->createDraft(
            ['shipment_number' => 'SHIP-0001', 'sales_order_id' => $so->id, 'warehouse_id' => $wh1->id, 'carrier' => 'DHL', 'tracking_number' => 'DHLDEMO001'],
            $shipLines
        );
        $shipSvc->post($shipment);

        // A partial return of headphones (resellable back IN).
        $retSvc = app(SalesReturnService::class);
        $ret = $retSvc->createDraft(
            ['return_number' => 'RET-0001', 'shipment_id' => $shipment->id, 'customer_name' => 'Acme Retail', 'warehouse_id' => $wh1->id, 'reason' => 'customer_changed_mind'],
            [['item_id' => $items['headphones']->id, 'returned_qty' => '2', 'unit_cost' => '45.00', 'condition' => 'resellable']]
        );
        $retSvc->post($ret);
    }

    private function recall(array $items): void
    {
        // A recall case on the near-expiry vitamin lot (draft → active flags it).
        $lot = \App\Models\Tenant\Lot::query()->where('lot_code', 'VIT-NEAREXP')->first();
        if (! $lot) {
            return;
        }
        $svc = app(RecallService::class);
        $recall = $svc->createDraft(
            ['recall_number' => 'RECALL-0001', 'item_id' => $items['vitamin']->id, 'scope' => 'lot', 'reason' => 'supplier_quality_alert'],
            [['item_id' => $items['vitamin']->id, 'lot_id' => $lot->id, 'disposition' => 'quarantine']]
        );
        $svc->activate($recall);
    }
}
