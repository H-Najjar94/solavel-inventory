import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState, MetricCard } from '../components/ui.jsx';

export default function SupplierDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const { data, isLoading } = useApiQuery(['supplier', id], () => api.supplier(id), { fallback: null });
    const s = data;
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!s) return <section className="page"><Breadcrumbs items={[{ label: 'Suppliers', to: '/suppliers' }, { label: 'Not found' }]} /><EmptyState title="Supplier unavailable" hint="Select a tenant to load real data." /></section>;
    const c = s.contact ?? {};
    const m = s.metrics ?? {};
    const editStyle = { marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 };
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Suppliers', to: '/suppliers' }, { label: s.name }]} />
            <header className="page-head"><h1>{s.name}</h1><StatusBadge active={s.is_active} />
                <Link to={`/suppliers/${s.id}/edit`} className="btn btn--primary" style={editStyle} title={gate.allowed ? '' : gate.reason}>Edit</Link></header>
            <div className="panel"><dl className="kv">
                <dt>Code</dt><dd>{s.code}</dd><dt>Email</dt><dd>{c.email ?? '—'}</dd>
                <dt>Phone</dt><dd>{c.phone ?? '—'}</dd><dt>Address</dt><dd>{c.address ?? '—'}</dd>
                <dt>Tax number</dt><dd>{c.tax_number ?? '—'}</dd><dt>Currency</dt><dd>{c.currency ?? '—'}</dd>
                <dt>Payment terms</dt><dd>{c.payment_terms ?? '—'}</dd>
            </dl></div>
            <div className="panel">
                <h2>Performance</h2>
                <div className="metric-cards">
                    <MetricCard label="Purchase orders" value={m.purchase_orders ?? 0} />
                    <MetricCard label="Open POs" value={m.open_purchase_orders ?? 0} />
                    <MetricCard label="Posted receipts" value={m.posted_receipts ?? 0} />
                    <MetricCard label="Average lead days" value={m.average_lead_days ?? '—'} />
                    <MetricCard label="On-time rate" value={m.on_time_rate == null ? '—' : `${m.on_time_rate}%`} tone={m.on_time_rate >= 90 ? 'ok' : (m.on_time_rate == null ? undefined : 'warn')} />
                </div>
            </div>
        </section>
    );
}
