import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function CountsPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['counts'], () => api.counts({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Stock Counts' }]} />
            <header className="page-head"><h1>Stock Counts</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/counts/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New count</Link></header>
            <p className="muted">Posting variances creates a single StockAdjustment → ledger. Cycle & full counts supported.</p>
            {rows.length === 0 ? <EmptyState title="No counts" hint="Count physical stock and post variances." /> : (
                <table className="data-table"><thead><tr><th>Count #</th><th>Type</th><th>WH</th><th>Status</th><th>Adjustment</th></tr></thead>
                <tbody>{rows.map((c) => (<tr key={c.id}><td><Link to={`/counts/${c.id}`}>{c.count_number}</Link></td><td>{c.count_type}</td><td>#{c.warehouse_id}</td><td><DocumentStatusBadge status={c.status} /></td><td>{c.adjustment_id ? `#${c.adjustment_id}` : '—'}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
