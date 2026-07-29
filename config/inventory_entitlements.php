<?php

return [
    'project_slug' => 'inventory',
    'valid_for_minutes' => (int) env('ENTITLEMENT_SNAPSHOT_VALID_FOR_MINUTES', 240),
    'max_stale_minutes' => (int) env('ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Feature enforcement master switch (DARK-LAUNCH)
    |--------------------------------------------------------------------------
    |
    | When false (default) the commercial (paid-plan) layer in
    | EnsureInventoryPermission is a pass-through: routes keep their perm: gate
    | but no request is denied on plan grounds. Role/permission auth ALWAYS runs
    | regardless. This lets the corrected stock.* map ship and be audited against
    | real tenants before anyone is gated. Flip SOLASTOCK_FEATURE_ENFORCEMENT to
    | activate.
    */
    'feature_enforcement' => (bool) env('SOLASTOCK_FEATURE_ENFORCEMENT', false),

    /*
    |--------------------------------------------------------------------------
    | free_permissions — the base product, gated on ROLE only, never on plan
    |--------------------------------------------------------------------------
    | Core capabilities every tier gets. Present in EVERY plan's flags, so they
    | would pass the plan gate anyway; listing them here keeps them working even
    | for a tenant with no snapshot (fail-open on the base product).
    */
    'free_permissions' => [
        'inventory.view_dashboard',
        'inventory.view_items',
        'inventory.manage_items',
        'inventory.view_warehouses',
        'inventory.manage_warehouses',
        'inventory.view_stock',
        'inventory.manage_opening_stock',
        'inventory.manage_adjustments',
        'inventory.view_ledger',
        // Read-only visibility must survive a plan change so an existing
        // SolaBooks connection never becomes an opaque "feature unavailable"
        // error. Manage/retry/export remain gated by stock.finance_integration.
        'inventory.integration.view',
    ],

    'restricted_safe_permissions' => [
        'inventory.view_dashboard',
        'inventory.view_items',
        'inventory.view_warehouses',
        'inventory.view_stock',
        'inventory.view_ledger',
        'inventory.view_reports',
        'inventory.view_settings',
        'inventory.view_sales',
        'inventory.view_traceability',
        'inventory.integration.view',
    ],

    /*
    |--------------------------------------------------------------------------
    | permission_features — maps a PERMISSION to the stock.* CATALOG feature key
    |--------------------------------------------------------------------------
    |
    | REBUILT 2026-07-15 to the real stock.* catalog (the previous map pointed at
    | an inventory.* taxonomy that central never pushes — every lookup missed and
    | nothing was actually gated). Central emits the stock.* keys verbatim in the
    | snapshot flags (EntitlementResolver::mergeProjectFlags), so these are the
    | keys the gate must check.
    |
    | ONLY permissions whose ENTIRE surface is one paid feature appear here. A
    | permission shared across base + paid surfaces (e.g. manage_adjustments backs
    | transfers/counts/PO/GRN — a core capability) is NOT mapped here; those paid
    | features are gated per-ROUTE in routes/api.php via 'feature:<key>' so the
    | base surface stays open. See the evidence map in
    | ~/feature-enforcement-mapping-2026-07-15.md.
    |
    | Permission-level (whole-permission == one feature):
    */
    'permission_features' => [
        // Reports & analytics + their export are the reports feature.
        'inventory.view_reports' => 'stock.reports',
        'inventory.export_reports' => 'stock.reports',

        // Traceability module == batch/expiry tracking. One module (one controller,
        // one nav group): lots + serials + recalls + expiry all governed by the
        // stock.batch_expiry key (owner decision 2026-07-15, model B). view_traceability
        // is the shared read gate; manage_lots/serials/recalls are the write gates.
        'inventory.view_traceability' => 'stock.batch_expiry',
        'inventory.manage_lots' => 'stock.batch_expiry',
        'inventory.manage_serials' => 'stock.batch_expiry',
        'inventory.manage_recalls' => 'stock.batch_expiry',

        // Sales Fulfillment == orders → reserve → pick → pack → ship → returns.
        // One coherent module (one "Sales / Fulfillment" nav group), governed by the
        // new stock.sales_fulfillment key (owner decision 2026-07-15). Every write
        // permission in the flow maps to it; view_sales is the read gate.
        'inventory.view_sales' => 'stock.sales_fulfillment',
        'inventory.manage_sales_orders' => 'stock.sales_fulfillment',
        'inventory.manage_reservations' => 'stock.sales_fulfillment',
        'inventory.manage_picking' => 'stock.sales_fulfillment',
        'inventory.manage_packing' => 'stock.sales_fulfillment',
        'inventory.manage_shipments' => 'stock.sales_fulfillment',
        'inventory.manage_returns' => 'stock.sales_fulfillment',

        // Solavel Finance (SolaBooks) integration. Read-only status/events are
        // intentionally a free permission above; all mutations and delivery
        // operations remain paid-feature gated.
        'inventory.integration.view' => 'stock.finance_integration',
        'inventory.integration.manage' => 'stock.finance_integration',
        'inventory.integration.retry' => 'stock.finance_integration',
        'inventory.integration.export_events' => 'stock.finance_integration',

        // NOTE deliberately NOT mapped, and why:
        //  - manage_settings/view_settings: cross-cutting (units, categories,
        //    reorder rules, roles); no single stock.* key. Left ungated (base).
        //  - override_quarantine/override_expired_lot: cross-cutting post-time policy
        //    toggles, permission-gated; base capability of the stock engine.
    ],

    /*
    |--------------------------------------------------------------------------
    | route_features — stock.* features gated per ROUTE (shared-perm surfaces)
    |--------------------------------------------------------------------------
    | These features share a base/free permission, so they are gated by ROUTE
    | NAME instead. EnsureInventoryFeature reads this by the route's name.
    | Key = route name, value = the stock.* feature key it requires.
    */
    'route_features' => [
        // Inter-warehouse transfers (writes ride manage_adjustments = core).
        'api.v1.transfers.store' => 'stock.transfers',
        'api.v1.transfers.update' => 'stock.transfers',
        'api.v1.transfers.post' => 'stock.transfers',
        'api.v1.transfers.ship' => 'stock.transfers',
        'api.v1.transfers.receive' => 'stock.transfers',

        // Purchase orders.
        'api.v1.po.store' => 'stock.purchase_orders',
        'api.v1.po.update' => 'stock.purchase_orders',
        'api.v1.po.cancel' => 'stock.purchase_orders',
        // PO approval workflow is its own paid sub-feature.
        'api.v1.po.approve' => 'stock.po_approvals',

        // Goods receipt against a PO (route group is named grn.*).
        'api.v1.grn.store' => 'stock.goods_receipt',
        'api.v1.grn.update' => 'stock.goods_receipt',
        'api.v1.grn.post' => 'stock.goods_receipt',
        'api.v1.grn.from-po' => 'stock.goods_receipt',

        // Stock counts & reconciliation.
        'api.v1.counts.store' => 'stock.counts',
        'api.v1.counts.update' => 'stock.counts',
        'api.v1.counts.post' => 'stock.counts',

        // Suppliers directory & price lists (ride the item perms = core).
        'api.v1.suppliers.store' => 'stock.suppliers',
        'api.v1.suppliers.update' => 'stock.suppliers',

        // Locations & bins (ride manage_warehouses = core).
        'api.v1.zones.store' => 'stock.locations_bins',
        'api.v1.zones.update' => 'stock.locations_bins',
        'api.v1.bins.store' => 'stock.locations_bins',
        'api.v1.bins.update' => 'stock.locations_bins',

        // Barcodes & label printing (ride manage_items = core).
        'api.v1.items.barcodes.store' => 'stock.barcodes',
        'api.v1.items.barcodes.primary' => 'stock.barcodes',
        'api.v1.items.barcodes.destroy' => 'stock.barcodes',

        // Variants & units of measure (ride manage_items / manage_settings).
        'api.v1.items.variants.store' => 'stock.variants',
        'api.v1.items.variants.update' => 'stock.variants',
        'api.v1.items.variants.destroy' => 'stock.variants',

        // Bulk import / export.
        'api.v1.opening.import' => 'stock.import_export',
        'api.v1.items.bulk-update' => 'stock.import_export',
    ],

    /*
    |--------------------------------------------------------------------------
    | limit_features — numeric ceilings, enforced with a grandfathered count gate
    |--------------------------------------------------------------------------
    | key = stock.* limit feature; value = the tenant model whose org-scoped count
    | is compared. Enforced in the controller store() via InventoryLimitService.
    | Grandfather rule: existing records are kept & usable; only NEW creation past
    | the limit is blocked. Never deletes data. (Clients 2 & 18 already exceed the
    | Free warehouse cap — this is why the gate must count-and-block-new-only.)
    */
    'limit_features' => [
        'stock.max_items' => \App\Models\Tenant\Item::class,
        'stock.max_warehouses' => \App\Models\Tenant\Warehouse::class,
    ],

    /*
    | catalog_only — stock.* keys intentionally NOT route/limit-gated, each with a
    | resolved reason (owner decision 2026-07-15). NONE is an accidental gap:
    |  - stock.items / stock.movements: base product (model C, every tier).
    |  - stock.reorder_alerts: dashboard alerts on view_dashboard (base, every tier).
    |  - stock.costing: per-item costing_method property + read-only valuation, not a
    |    gateable module (model C, every tier).
    |  - stock.api: Enterprise-only entitlement MARKER; there is no per-tenant API gate
    |    (the whole v1 group is the API). Enforced as a plan flag, not a route.
    | (stock.reorder_suggestions was REMOVED here — it is now disabled in every plan
    |  at the catalog level until a suggested-PO endpoint exists, so it is not a
    |  catalog-only-but-sold key any more.)
    */
    'catalog_only' => [
        'stock.items',
        'stock.movements',
        'stock.reorder_alerts',
        'stock.api',
        'stock.costing',
    ],
];
