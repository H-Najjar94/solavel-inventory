import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockOpening } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function OpeningStockPage() {
    const gate = useCanCreate('inventory.manage_opening_stock');
    const { data, isMock } = useApiQuery(['opening'], () => api.openingStock({ per_page: 50 }), { fallback: mockOpening });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockOpening);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Opening Stock' }]} />
            <header className="page-head"><h1>Opening Stock</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/opening-stock/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New entry</Link></header>
            <p className="muted">Posting/reversal delegate to OpeningStockService → the canonical stock ledger. Posted entries are immutable.</p>
            {rows.length === 0 ? <EmptyState title="No opening-stock entries" hint="Create one to set starting quantities." /> : (
                <table className="data-table"><thead><tr><th>Number</th><th>Date</th><th>WH</th><th>Status</th><th>Value</th></tr></thead>
                <tbody>{rows.map((e) => (<tr key={e.id}><td><Link to={`/opening-stock/${e.id}`}>{e.entry_number}</Link></td><td>{e.opening_date}</td><td>#{e.warehouse_id}</td><td><DocumentStatusBadge status={e.status} /></td><td>{e.total_value}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
