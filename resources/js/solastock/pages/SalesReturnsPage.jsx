import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function SalesReturnsPage() {
    const gate = useCanCreate('inventory.manage_returns');
    const { data, isMock } = useApiQuery(['sales-returns'], () => api.salesReturns({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Returns' }]} />
            <header className="page-head"><h1>Sales Returns</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/sales-returns/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New return</Link></header>
            <p className="muted">Posting a return puts resellable/quarantine stock back IN via the ledger. Damaged units are recorded but not returned to stock.</p>
            {rows.length === 0 ? <EmptyState title="No sales returns" hint="Record a customer return to bring stock back in." /> : (
                <table className="data-table"><thead><tr><th>Return #</th><th>Customer</th><th>Date</th><th>Warehouse</th><th>Status</th></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}><td><Link to={`/sales-returns/${r.id}`}>{r.return_number}</Link></td><td>{r.customer_name ?? '—'}</td><td>{r.return_date}</td><td>#{r.warehouse_id}</td><td><DocumentStatusBadge status={r.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
