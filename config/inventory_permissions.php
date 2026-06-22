<?php

/*
|--------------------------------------------------------------------------
| SolaStock Inventory Permissions
|--------------------------------------------------------------------------
| Follows the Solavel permission naming convention (module.action). Applied to
| routes (middleware), policies, and UI navigation gating. Seeded into the
| permission store per organization by InventoryRolePermissionSeeder.
*/

return [
    'permissions' => [
        'inventory.view_dashboard' => 'View the inventory dashboard',
        'inventory.view_items' => 'View items',
        'inventory.manage_items' => 'Create / edit / deactivate items',
        'inventory.view_warehouses' => 'View warehouses, zones, bins',
        'inventory.manage_warehouses' => 'Create / edit warehouses, zones, bins',
        'inventory.view_stock' => 'View stock balances',
        'inventory.manage_opening_stock' => 'Create / post / reverse opening stock',
        'inventory.manage_adjustments' => 'Create / post / reverse stock adjustments',
        'inventory.view_ledger' => 'View the stock ledger',
        'inventory.view_reports' => 'View inventory reports',
        'inventory.export_reports' => 'Export inventory reports',
        'inventory.manage_settings' => 'Manage inventory settings',
        'inventory.integration.view' => 'View SolaBooks integration status & events',
        'inventory.integration.manage' => 'Manage integration mappings & settings',
        'inventory.integration.retry' => 'Retry / ignore integration events',
        'inventory.integration.export_events' => 'Export integration events',

        // ── Sales fulfillment ──
        'inventory.view_sales' => 'View sales orders & fulfillment',
        'inventory.manage_sales_orders' => 'Create / edit / confirm / cancel sales orders',
        'inventory.manage_reservations' => 'Reserve / release stock for sales orders',
        'inventory.manage_picking' => 'Create pick lists & record picks',
        'inventory.manage_packing' => 'Create packs & record packing',
        'inventory.manage_shipments' => 'Create & post shipments (stock OUT)',
        'inventory.manage_returns' => 'Create & post sales returns (stock IN)',

        // ── Traceability & recall ──
        'inventory.view_traceability' => 'View lots, serials, expiry & traceability',
        'inventory.manage_lots' => 'Create / edit lots & lot status',
        'inventory.manage_serials' => 'Create / edit serials & serial status',
        'inventory.manage_recalls' => 'Create / activate / close recall cases',
        'inventory.override_quarantine' => 'Ship quarantined / recalled lots (override)',
        'inventory.override_expired_lot' => 'Ship expired lots (override)',
    ],

    // Default role → permission bundles (seeded per org).
    'roles' => [
        'inventory_admin' => '*', // all permissions
        'inventory_manager' => [
            'inventory.view_dashboard', 'inventory.view_items', 'inventory.manage_items',
            'inventory.view_warehouses', 'inventory.manage_warehouses', 'inventory.view_stock',
            'inventory.manage_opening_stock', 'inventory.manage_adjustments',
            'inventory.view_ledger', 'inventory.view_reports', 'inventory.export_reports',
            'inventory.integration.view',
            'inventory.view_sales', 'inventory.manage_sales_orders', 'inventory.manage_reservations',
            'inventory.manage_picking', 'inventory.manage_packing',
            'inventory.manage_shipments', 'inventory.manage_returns',
            'inventory.view_traceability', 'inventory.manage_lots', 'inventory.manage_serials',
            'inventory.manage_recalls', 'inventory.override_quarantine', 'inventory.override_expired_lot',
        ],
        'inventory_viewer' => [
            'inventory.view_dashboard', 'inventory.view_items', 'inventory.view_warehouses',
            'inventory.view_stock', 'inventory.view_ledger', 'inventory.view_reports',
            'inventory.view_sales', 'inventory.view_traceability',
        ],
    ],
];
