<?php

return [
    'project_slug' => 'inventory',
    'valid_for_minutes' => (int) env('ENTITLEMENT_SNAPSHOT_VALID_FOR_MINUTES', 240),
    'max_stale_minutes' => (int) env('ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES', 1440),

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
    ],

    'restricted_safe_permissions' => [
        'inventory.view_dashboard',
        'inventory.view_items',
        'inventory.view_warehouses',
        'inventory.view_stock',
        'inventory.view_ledger',
        'inventory.view_reports',
        'inventory.view_sales',
        'inventory.view_traceability',
        'inventory.integration.view',
    ],

    'permission_features' => [
        'inventory.view_reports' => 'inventory.reports',
        'inventory.export_reports' => 'inventory.report_exports',
        'inventory.manage_settings' => 'inventory.advanced_settings',
        'inventory.integration.view' => 'inventory.solabooks_integration',
        'inventory.integration.manage' => 'inventory.solabooks_integration',
        'inventory.integration.retry' => 'inventory.solabooks_integration',
        'inventory.integration.export_events' => 'inventory.solabooks_integration',
        'inventory.view_sales' => 'inventory.sales_fulfillment',
        'inventory.manage_sales_orders' => 'inventory.sales_fulfillment',
        'inventory.manage_reservations' => 'inventory.sales_fulfillment',
        'inventory.manage_picking' => 'inventory.sales_fulfillment',
        'inventory.manage_packing' => 'inventory.sales_fulfillment',
        'inventory.manage_shipments' => 'inventory.sales_fulfillment',
        'inventory.manage_returns' => 'inventory.sales_fulfillment',
        'inventory.view_traceability' => 'inventory.traceability',
        'inventory.manage_lots' => 'inventory.traceability',
        'inventory.manage_serials' => 'inventory.traceability',
        'inventory.manage_recalls' => 'inventory.recalls',
        'inventory.override_quarantine' => 'inventory.traceability_overrides',
        'inventory.override_expired_lot' => 'inventory.traceability_overrides',
    ],
];
