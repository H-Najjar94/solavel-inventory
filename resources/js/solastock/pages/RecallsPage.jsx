import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function RecallsPage() {
    const gate = useCanCreate('inventory.manage_recalls');
    const { data, isMock } = useApiQuery(['recalls'], () => api.recalls({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Recalls' }]} />
            <header className="page-head"><h1>Recalls</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/recalls/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New recall</Link></header>
            <p className="muted">A recall flags affected lots/serials and computes impact from the canonical ledger. No customer notification or accounting is performed.</p>
            {rows.length === 0 ? <EmptyState title="No recalls" hint="Open a recall case to trace and quarantine affected stock." /> : (
                <table className="data-table"><thead><tr><th>Recall #</th><th>Item</th><th>Scope</th><th>Reason</th><th>Status</th></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}><td><Link to={`/recalls/${r.id}`}>{r.recall_number}</Link></td><td>{r.item ? `${r.item.sku} · ${r.item.name}` : `#${r.item_id}`}</td><td>{r.scope}</td><td>{r.reason ?? '—'}</td><td><DocumentStatusBadge status={r.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
