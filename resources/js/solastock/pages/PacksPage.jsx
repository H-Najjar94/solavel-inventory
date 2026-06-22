import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function PacksPage() {
    const { data, isMock } = useApiQuery(['packs'], () => api.packs({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Packing' }]} />
            <header className="page-head"><h1>Packs</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <p className="muted">Packs cartonize picked stock. Packing does not move stock; the stock OUT happens at shipment.</p>
            {rows.length === 0 ? <EmptyState title="No packs" hint="Create a pack from a picked pick list." /> : (
                <table className="data-table"><thead><tr><th>Pack #</th><th>Sales order</th><th>Carrier</th><th>Status</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/packs/${p.id}`}>{p.pack_number}</Link></td><td>#{p.sales_order_id}</td><td>{p.carrier ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
