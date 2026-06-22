import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { SerialStatusBadge } from '../components/traceability.jsx';

const STATUSES = ['available', 'reserved', 'picked', 'packed', 'shipped', 'returned', 'damaged', 'quarantined', 'retired'];

export default function SerialsPage() {
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const { data, isMock } = useApiQuery(['serials', status, q], () => api.serials({ per_page: 50, status: status || undefined, q: q || undefined }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Traceability', to: '/traceability' }, { label: 'Serials' }]} />
            <header className="page-head"><h1>Serial Numbers</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <div className="filter-bar">
                <input className="input" placeholder="Search serial…" value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">All statuses</option>
                    {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            </div>
            {rows.length === 0 ? <EmptyState title="No serials" hint="Serials are captured on inbound documents for serial-tracked items." /> : (
                <table className="data-table"><thead><tr><th>Serial</th><th>Item</th><th>Location</th><th>Status</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/traceability/serials/${s.id}`}>{s.serial}</Link></td><td>{s.item ? `${s.item.sku} · ${s.item.name}` : `#${s.item_id}`}</td><td>{s.warehouse_id ? `#${s.warehouse_id}` : '—'}</td><td><SerialStatusBadge status={s.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
