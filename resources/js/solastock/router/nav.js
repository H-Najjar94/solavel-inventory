// Permission-aware navigation config. `perm` gates visibility against the
// current user's inventory permissions (from /meta). `icon` uses Font Awesome
// (fa-solid), matching the SolaBooks (Finance) sidebar style. Order = sidebar order.

export const NAV = [
    { key: 'dashboard', label: 'Dashboard', path: '/dashboard', icon: 'fa-solid fa-gauge-high', perm: 'inventory.view_dashboard', group: 'Overview' },
    { key: 'items', label: 'Items', path: '/items', icon: 'fa-solid fa-boxes-stacked', perm: 'inventory.view_items', group: 'Catalog' },
    { key: 'warehouses', label: 'Warehouses', path: '/warehouses', icon: 'fa-solid fa-warehouse', perm: 'inventory.view_warehouses', group: 'Catalog' },
    { key: 'balances', label: 'Current Stock', path: '/balances', icon: 'fa-solid fa-layer-group', perm: 'inventory.view_stock', group: 'Stock' },
    { key: 'ledger', label: 'Stock Ledger', path: '/ledger', icon: 'fa-solid fa-book', perm: 'inventory.view_ledger', group: 'Stock' },
    { key: 'opening', label: 'Opening Stock', path: '/opening-stock', icon: 'fa-solid fa-box-open', perm: 'inventory.view_stock', group: 'Operations' },
    { key: 'adjustments', label: 'Adjustments', path: '/adjustments', icon: 'fa-solid fa-sliders', perm: 'inventory.view_stock', group: 'Operations' },
    { key: 'transfers', label: 'Transfers', path: '/transfers', icon: 'fa-solid fa-right-left', perm: 'inventory.view_stock', group: 'Operations' },
    { key: 'counts', label: 'Stock Counts', path: '/counts', icon: 'fa-solid fa-list-check', perm: 'inventory.view_stock', group: 'Operations' },
    { key: 'suppliers', label: 'Suppliers', path: '/suppliers', icon: 'fa-solid fa-truck-field', perm: 'inventory.view_items', group: 'Purchasing' },
    { key: 'purchase-orders', label: 'Purchase Orders', path: '/purchase-orders', icon: 'fa-solid fa-file-invoice', perm: 'inventory.view_stock', group: 'Purchasing' },
    { key: 'goods-receipts', label: 'Goods Receipts', path: '/goods-receipts', icon: 'fa-solid fa-dolly', perm: 'inventory.view_stock', group: 'Purchasing' },
    { key: 'sales-orders', label: 'Sales Orders', path: '/sales-orders', icon: 'fa-solid fa-cart-shopping', perm: 'inventory.view_sales', group: 'Sales / Fulfillment' },
    { key: 'pick-lists', label: 'Picking', path: '/pick-lists', icon: 'fa-solid fa-hand', perm: 'inventory.view_sales', group: 'Sales / Fulfillment' },
    { key: 'packs', label: 'Packing', path: '/packs', icon: 'fa-solid fa-box', perm: 'inventory.view_sales', group: 'Sales / Fulfillment' },
    { key: 'shipments', label: 'Shipments', path: '/shipments', icon: 'fa-solid fa-truck-fast', perm: 'inventory.view_sales', group: 'Sales / Fulfillment' },
    { key: 'sales-returns', label: 'Sales Returns', path: '/sales-returns', icon: 'fa-solid fa-rotate-left', perm: 'inventory.view_sales', group: 'Sales / Fulfillment' },
    { key: 'traceability', label: 'Traceability', path: '/traceability', icon: 'fa-solid fa-magnifying-glass-location', perm: 'inventory.view_traceability', group: 'Traceability' },
    { key: 'lots', label: 'Lots / Batches', path: '/traceability/lots', icon: 'fa-solid fa-barcode', perm: 'inventory.view_traceability', group: 'Traceability' },
    { key: 'serials', label: 'Serial Numbers', path: '/traceability/serials', icon: 'fa-solid fa-hashtag', perm: 'inventory.view_traceability', group: 'Traceability' },
    { key: 'recalls', label: 'Recalls', path: '/recalls', icon: 'fa-solid fa-triangle-exclamation', perm: 'inventory.view_traceability', group: 'Traceability' },
    { key: 'reports', label: 'Reports', path: '/reports', icon: 'fa-solid fa-chart-line', perm: 'inventory.view_reports', group: 'Insights' },
    { key: 'integration', label: 'SolaBooks', path: '/settings/solabooks', icon: 'fa-solid fa-book-open', perm: 'inventory.integration.view', group: 'Admin' },
    { key: 'settings', label: 'Settings', path: '/settings', icon: 'fa-solid fa-gear', perm: 'inventory.manage_settings', group: 'Admin' },
];

export function visibleNav(permissions = []) {
    const set = new Set(permissions);
    return NAV.filter((n) => !n.perm || set.has(n.perm));
}
