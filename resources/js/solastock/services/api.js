// SolaStock API client. All calls hit the versioned JSON API mounted under
// /inventory/api/v1 (Apache serves the app beneath /inventory). The Finance-style
// envelope is { success, data, meta? } / { success:false, error }.

const BASE = '/inventory/api/v1';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function request(path, { method = 'GET', body, params } = {}) {
    const url = new URL(BASE + path, window.location.origin);
    if (params) {
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
    }

    const res = await fetch(url.toString(), {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

    let json = null;
    try {
        json = await res.json();
    } catch {
        // non-JSON (e.g. 500 HTML) — surface a generic error below
    }

    if (!res.ok || (json && json.success === false)) {
        const err = new Error(json?.error?.message || `Request failed (${res.status})`);
        err.status = res.status;
        err.code = json?.error?.code;
        err.payload = json?.error;
        throw err;
    }

    return json; // { success, data, meta? }
}

// Multipart form upload (e.g. images). No JSON Content-Type — the browser sets
// the multipart boundary itself. Same envelope + error handling as request().
async function requestForm(path, formData) {
    const res = await fetch(BASE + path, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: formData,
    });

    let json = null;
    try { json = await res.json(); } catch { /* non-JSON */ }

    if (!res.ok || (json && json.success === false)) {
        const err = new Error(json?.error?.message || `Upload failed (${res.status})`);
        err.status = res.status;
        err.payload = json?.error;
        throw err;
    }

    return json;
}

export const api = {
    meta: () => request('/meta'),

    // Tenant (live SSO status + demo selection; not tenant-gated)
    tenantStatus: () => request('/tenant/status'),
    selectDemoTenant: () => request('/tenant/select-demo', { method: 'POST' }),
    clearTenant: () => request('/tenant/clear', { method: 'POST' }),
    provisionTenant: () => request('/tenant/provision', { method: 'POST' }),

    // Org switcher
    listOrganizations: () => request('/tenant/organizations'),
    selectOrganization: (organizationId) =>
        request('/tenant/select-org', { method: 'POST', body: { organization_id: organizationId } }),

    dashboard: () => request('/dashboard'),
    getDashboardLayout: () => request('/dashboard/layout'),
    saveDashboardLayout: (layout) => request('/dashboard/layout', { method: 'PUT', body: { layout } }),

    items: (params) => request('/items', { params }),
    item: (id) => request(`/items/${id}`),
    createItem: (body) => request('/items', { method: 'POST', body }),
    updateItem: (id, body) => request(`/items/${id}`, { method: 'PUT', body }),
    itemMovements: (id, params) => request(`/items/${id}/movements`, { params }),
    itemValuation: (id) => request(`/items/${id}/valuation`),
    movementConsumedLayers: (ledgerId) => request(`/movements/${ledgerId}/consumed-layers`),
    itemImages: (id) => request(`/items/${id}/images`),
    uploadItemImage: (id, file) => {
        const fd = new FormData();
        fd.append('image', file);
        return requestForm(`/items/${id}/images`, fd);
    },
    setItemImagePrimary: (imageId) => request(`/item-images/${imageId}/primary`, { method: 'POST' }),
    deleteItemImage: (imageId) => request(`/item-images/${imageId}`, { method: 'DELETE' }),

    warehouses: (params) => request('/warehouses', { params }),
    warehouse: (id) => request(`/warehouses/${id}`),
    createWarehouse: (body) => request('/warehouses', { method: 'POST', body }),
    updateWarehouse: (id, body) => request(`/warehouses/${id}`, { method: 'PUT', body }),
    warehouseImages: (id) => request(`/warehouses/${id}/images`),
    uploadWarehouseImage: (id, file) => {
        const fd = new FormData();
        fd.append('image', file);
        return requestForm(`/warehouses/${id}/images`, fd);
    },
    setWarehouseImagePrimary: (imageId) => request(`/warehouse-images/${imageId}/primary`, { method: 'POST' }),
    deleteWarehouseImage: (imageId) => request(`/warehouse-images/${imageId}`, { method: 'DELETE' }),

    openingStock: (params) => request('/opening-stock', { params }),
    openingStockEntry: (id) => request(`/opening-stock/${id}`),
    createOpeningStock: (body) => request('/opening-stock', { method: 'POST', body }),
    updateOpeningStock: (id, body) => request(`/opening-stock/${id}`, { method: 'PUT', body }),
    postOpeningStock: (id) => request(`/opening-stock/${id}/post`, { method: 'POST' }),
    reverseOpeningStock: (id) => request(`/opening-stock/${id}/reverse`, { method: 'POST' }),

    adjustments: (params) => request('/adjustments', { params }),
    adjustment: (id) => request(`/adjustments/${id}`),
    createAdjustment: (body) => request('/adjustments', { method: 'POST', body }),
    updateAdjustment: (id, body) => request(`/adjustments/${id}`, { method: 'PUT', body }),
    postAdjustment: (id) => request(`/adjustments/${id}/post`, { method: 'POST' }),
    reverseAdjustment: (id) => request(`/adjustments/${id}/reverse`, { method: 'POST' }),

    ledger: (params) => request('/ledger', { params }),
    balances: (params) => request('/balances', { params }),

    // Suppliers
    suppliers: (params) => request('/suppliers', { params }),
    createSupplier: (body) => request('/suppliers', { method: 'POST', body }),

    // Purchase Orders
    purchaseOrders: (params) => request('/purchase-orders', { params }),
    purchaseOrder: (id) => request(`/purchase-orders/${id}`),
    createPurchaseOrder: (body) => request('/purchase-orders', { method: 'POST', body }),
    updatePurchaseOrder: (id, body) => request(`/purchase-orders/${id}`, { method: 'PUT', body }),
    approvePurchaseOrder: (id) => request(`/purchase-orders/${id}/approve`, { method: 'POST' }),
    cancelPurchaseOrder: (id) => request(`/purchase-orders/${id}/cancel`, { method: 'POST' }),
    grnDraftFromPo: (poId) => request(`/purchase-orders/${poId}/grn-draft`),

    // Goods Receipts
    goodsReceipts: (params) => request('/goods-receipts', { params }),
    goodsReceipt: (id) => request(`/goods-receipts/${id}`),
    createGoodsReceipt: (body) => request('/goods-receipts', { method: 'POST', body }),
    updateGoodsReceipt: (id, body) => request(`/goods-receipts/${id}`, { method: 'PUT', body }),
    postGoodsReceipt: (id) => request(`/goods-receipts/${id}/post`, { method: 'POST' }),

    // Transfers
    transfers: (params) => request('/transfers', { params }),
    transfer: (id) => request(`/transfers/${id}`),
    createTransfer: (body) => request('/transfers', { method: 'POST', body }),
    updateTransfer: (id, body) => request(`/transfers/${id}`, { method: 'PUT', body }),
    postTransfer: (id) => request(`/transfers/${id}/post`, { method: 'POST' }),
    transferAvailable: (itemId, warehouseId) => request('/transfers-available', { params: { item_id: itemId, warehouse_id: warehouseId } }),

    // Counts
    counts: (params) => request('/counts', { params }),
    count: (id) => request(`/counts/${id}`),
    createCount: (body) => request('/counts', { method: 'POST', body }),
    updateCount: (id, body) => request(`/counts/${id}`, { method: 'PUT', body }),
    postCount: (id) => request(`/counts/${id}/post`, { method: 'POST' }),
    countPrefill: (warehouseId, binId) => request('/counts-prefill', { params: { warehouse_id: warehouseId, bin_id: binId } }),

    // ── Sales Fulfillment ──
    salesOrders: (params) => request('/sales-orders', { params }),
    salesOrder: (id) => request(`/sales-orders/${id}`),
    createSalesOrder: (body) => request('/sales-orders', { method: 'POST', body }),
    updateSalesOrder: (id, body) => request(`/sales-orders/${id}`, { method: 'PUT', body }),
    confirmSalesOrder: (id) => request(`/sales-orders/${id}/confirm`, { method: 'POST' }),
    reserveSalesOrder: (id) => request(`/sales-orders/${id}/reserve`, { method: 'POST' }),
    releaseSalesOrderReservation: (id) => request(`/sales-orders/${id}/release-reservation`, { method: 'POST' }),
    cancelSalesOrder: (id) => request(`/sales-orders/${id}/cancel`, { method: 'POST' }),

    pickLists: (params) => request('/pick-lists', { params }),
    pickList: (id) => request(`/pick-lists/${id}`),
    createPickList: (body) => request('/pick-lists', { method: 'POST', body }),
    updatePickList: (id, body) => request(`/pick-lists/${id}`, { method: 'PUT', body }),
    markPickListPicked: (id) => request(`/pick-lists/${id}/picked`, { method: 'POST' }),

    packs: (params) => request('/packs', { params }),
    pack: (id) => request(`/packs/${id}`),
    createPack: (body) => request('/packs', { method: 'POST', body }),
    updatePack: (id, body) => request(`/packs/${id}`, { method: 'PUT', body }),
    markPackPacked: (id) => request(`/packs/${id}/packed`, { method: 'POST' }),

    shipments: (params) => request('/shipments', { params }),
    shipment: (id) => request(`/shipments/${id}`),
    shipmentDraftFromSo: (soId) => request(`/sales-orders/${soId}/shipment-draft`),
    createShipment: (body) => request('/shipments', { method: 'POST', body }),
    updateShipment: (id, body) => request(`/shipments/${id}`, { method: 'PUT', body }),
    postShipment: (id) => request(`/shipments/${id}/post`, { method: 'POST' }),

    salesReturns: (params) => request('/sales-returns', { params }),
    salesReturn: (id) => request(`/sales-returns/${id}`),
    createSalesReturn: (body) => request('/sales-returns', { method: 'POST', body }),
    updateSalesReturn: (id, body) => request(`/sales-returns/${id}`, { method: 'PUT', body }),
    postSalesReturn: (id) => request(`/sales-returns/${id}/post`, { method: 'POST' }),

    // Traceability — lots
    lots: (params) => request('/lots', { params }),
    lot: (id) => request(`/lots/${id}`),
    lotMovements: (id) => request(`/lots/${id}/movements`),
    lotAvailability: (itemId, warehouseId) => request('/lots-availability', { params: { item_id: itemId, warehouse_id: warehouseId } }),
    setLotStatus: (id, body) => request(`/lots/${id}/status`, { method: 'PUT', body }),

    // Traceability — serials
    serials: (params) => request('/serials', { params }),
    serial: (id) => request(`/serials/${id}`),
    serialLifecycle: (id) => request(`/serials/${id}/lifecycle`),
    serialAvailability: (itemId, warehouseId) => request('/serials-availability', { params: { item_id: itemId, warehouse_id: warehouseId } }),
    setSerialStatus: (id, body) => request(`/serials/${id}/status`, { method: 'PUT', body }),

    // Traceability — helpers
    validateSerials: (serials, expectedQty) => request('/traceability/validate-serials', { method: 'POST', body: { serials, expected_qty: expectedQty } }),
    validateLot: (body) => request('/traceability/validate-lot', { method: 'POST', body }),
    validateCapture: (body) => request('/traceability/validate-capture', { method: 'POST', body }),
    suggestOutboundLots: (itemId, warehouseId, quantity) => request('/traceability/suggest-outbound-lots', { params: { item_id: itemId, warehouse_id: warehouseId, quantity } }),
    expiryRisk: (params) => request('/traceability/expiry-risk', { params }),

    // Recalls
    recalls: (params) => request('/recalls', { params }),
    recall: (id) => request(`/recalls/${id}`),
    recallImpact: (id) => request(`/recalls/${id}/impact`),
    createRecall: (body) => request('/recalls', { method: 'POST', body }),
    updateRecall: (id, body) => request(`/recalls/${id}`, { method: 'PUT', body }),
    activateRecall: (id) => request(`/recalls/${id}/activate`, { method: 'POST' }),
    closeRecall: (id, notes) => request(`/recalls/${id}/close`, { method: 'POST', body: { notes } }),

    // Reports
    reportsList: () => request('/reports'),
    report: (name, params) => request(`/reports/${name}`, { params }),
    // CSV export is a browser download (not JSON) — build the URL with filters.
    reportExportUrl: (name, params = {}) => {
        const url = new URL(`${BASE}/reports/${name}/export`, window.location.origin);
        url.searchParams.set('format', 'csv');
        Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v); });
        return url.toString();
    },

    // Settings + integration
    settings: () => request('/settings'),
    updateSettings: (body) => request('/settings', { method: 'PUT', body }),
    // SolaBooks integration (foundation)
    integrationStatus: () => request('/integration/solabooks/status'),
    integrationAccountMappings: () => request('/integration/solabooks/mappings/accounts'),
    saveIntegrationAccountMappings: (mappings) => request('/integration/solabooks/mappings/accounts', { method: 'PUT', body: { mappings } }),
    integrationItemMappings: (params) => request('/integration/solabooks/mappings/items', { params }),
    saveIntegrationItemMapping: (itemId, body) => request(`/integration/solabooks/mappings/items/${itemId}`, { method: 'PUT', body }),
    integrationEvents: (params) => request('/integration/solabooks/events', { params }),
    integrationEvent: (id) => request(`/integration/solabooks/events/${id}`),
    retryIntegrationEvent: (id) => request(`/integration/solabooks/events/${id}/retry-placeholder`, { method: 'POST' }),
    ignoreIntegrationEvent: (id) => request(`/integration/solabooks/events/${id}/ignore-placeholder`, { method: 'POST' }),

    // Quick-create master data
    createCategory: (name) => request('/settings/categories', { method: 'POST', body: { name } }),
    createBrand: (name) => request('/settings/brands', { method: 'POST', body: { name } }),
    createUnit: (name) => request('/settings/units', { method: 'POST', body: { name, code: name.slice(0, 8).toUpperCase() } }),

    // Supplier detail/update + quick-create
    supplier: (id) => request(`/suppliers/${id}`),
    createSupplierFull: (body) => request('/suppliers', { method: 'POST', body }),
    updateSupplier: (id, body) => request(`/suppliers/${id}`, { method: 'PUT', body }),
    createSupplier: (name) => request('/suppliers', { method: 'POST', body: { name, code: name.slice(0, 10).toUpperCase().replace(/\s+/g, '-') } }),

    // Warehouse structure
    createZone: (warehouseId, body) => request(`/warehouses/${warehouseId}/zones`, { method: 'POST', body }),
    updateZone: (zoneId, body) => request(`/zones/${zoneId}`, { method: 'PUT', body }),
    createBin: (warehouseId, body) => request(`/warehouses/${warehouseId}/bins`, { method: 'POST', body }),
    updateBin: (binId, body) => request(`/bins/${binId}`, { method: 'PUT', body }),
};
