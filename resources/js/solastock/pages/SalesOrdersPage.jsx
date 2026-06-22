import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function SalesOrdersPage() {
    const gate = useCanCreate('inventory.manage_sales_orders');
    const { data, isMock } = useApiQuery(['sales-orders'], () => api.salesOrders({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Orders' }]} />
            <header className="page-head"><h1>Sales Orders</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/sales-orders/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New sales order</Link></header>
            <p className="muted">Fulfillment documents only — SolaStock reserves and ships stock. Invoices &amp; accounting stay in SolaBooks.</p>
            {rows.length === 0 ? <EmptyState title="No sales orders" hint="Create a sales order to reserve, pick, pack and ship stock." /> : (
                <table className="data-table"><thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Warehouse</th><th>Status</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/sales-orders/${s.id}`}>{s.order_number}</Link></td><td>{s.customer_name ?? '—'}</td><td>{s.order_date}</td><td>#{s.warehouse_id}</td><td><DocumentStatusBadge status={s.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
