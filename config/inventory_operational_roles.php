<?php

$read = ['inventory.view_dashboard', 'inventory.view_items', 'inventory.view_warehouses', 'inventory.view_stock', 'inventory.view_sales'];
$operate = array_merge($read, ['inventory.receive_goods', 'inventory.transfer_stock', 'inventory.manage_picking', 'inventory.manage_packing', 'inventory.manage_shipments', 'inventory.manage_reservations']);
$warehouse = array_merge($operate, ['inventory.manage_warehouse_structure']);

return [
    'scoped_inventory_manager' => ['label' => 'Inventory Manager', 'ar' => 'مدير المخزون', 'permissions' => array_merge($warehouse, ['inventory.view_ledger', 'inventory.view_reports', 'inventory.manage_purchase_orders'])],
    'warehouse_manager' => ['label' => 'Warehouse Manager', 'ar' => 'مدير المستودع', 'permissions' => $warehouse],
    'warehouse_operator' => ['label' => 'Warehouse Operator', 'ar' => 'مشغل المستودع', 'permissions' => $operate],
    'scoped_inventory_viewer' => ['label' => 'Inventory Viewer', 'ar' => 'مشاهد المخزون', 'permissions' => $read],
];
