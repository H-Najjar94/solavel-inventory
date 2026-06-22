import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { LotStatusBadge } from '../components/traceability.jsx';

export default function LotsPage() {
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const { data, isMock } = useApiQuery(['lots', status, q], () => api.lots({ per_page: 50, status: status || undefined, q: q || undefined }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Traceability', to: '/traceability' }, { label: 'Lots' }]} />
            <header className="page-head"><h1>Lots / Batches</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <div className="filter-bar">
                <input className="input" placeholder="Search lot code…" value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">All statuses</option>
                    {['active', 'expired', 'quarantined', 'consumed', 'recalled'].map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            </div>
            {rows.length === 0 ? <EmptyState title="No lots" hint="Lots are captured on inbound documents for lot-tracked items." /> : (
                <table className="data-table"><thead><tr><th>Lot code</th><th>Item</th><th>Expiry</th><th>Status</th></tr></thead>
                <tbody>{rows.map((l) => (<tr key={l.id}><td><Link to={`/traceability/lots/${l.id}`}>{l.lot_code}</Link></td><td>{l.item ? `${l.item.sku} · ${l.item.name}` : `#${l.item_id}`}</td><td>{l.expiry_date ?? '—'}</td><td><LotStatusBadge status={l.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
