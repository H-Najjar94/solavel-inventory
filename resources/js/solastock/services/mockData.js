// No mock/sample data. SolaStock only ever shows the active organization's REAL
// data. These exports are kept (empty) only so existing imports keep working as
// neutral loading placeholders — there is no fake data to flash before the real
// API response arrives.

export const mockDashboard = {
    inventory_value: 0, total_skus: 0, active_items: 0, low_stock: 0, out_of_stock: 0,
    dead_stock: 0, movements_today: 0, pending_pos: 0, pending_grns: 0, pending_transfers: 0,
    pending_counts: 0, warehouses: 0, pending_sales_orders: 0, reserved_stock_qty: 0,
    awaiting_pick: 0, awaiting_pack: 0, awaiting_ship: 0, shipments_today: 0,
    top_moving: [], recent_movements: [], generated_at: null,
};

export const mockItems = [];
export const mockWarehouses = [];
export const mockBalances = [];
export const mockLedger = [];
export const mockOpening = [];
export const mockAdjustments = [];
