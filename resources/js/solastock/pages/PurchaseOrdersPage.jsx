import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function PurchaseOrdersPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['pos'], () => api.purchaseOrders({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Purchase Orders' }]} />
            <header className="page-head"><h1>Purchase Orders</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/purchase-orders/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New PO</Link></header>
            <p className="muted">Purchase orders do not move stock — receive against them via Goods Receipts.</p>
            {rows.length === 0 ? <EmptyState title="No purchase orders" hint="Create one to order from a supplier." /> : (
                <table className="data-table"><thead><tr><th>PO #</th><th>Supplier</th><th>WH</th><th>Order date</th><th>Expected</th><th>Status</th><th>Total</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/purchase-orders/${p.id}`}>{p.po_number}</Link></td><td>{p.supplier_id ? `#${p.supplier_id}` : '—'}</td><td>#{p.warehouse_id}</td><td>{p.order_date}</td><td>{p.expected_date ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td><td>{p.total}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
