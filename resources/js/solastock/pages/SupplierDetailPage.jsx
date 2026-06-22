import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState } from '../components/ui.jsx';

export default function SupplierDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const { data, isLoading } = useApiQuery(['supplier', id], () => api.supplier(id), { fallback: null });
    const s = data;
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!s) return <section className="page"><Breadcrumbs items={[{ label: 'Suppliers', to: '/suppliers' }, { label: 'Not found' }]} /><EmptyState title="Supplier unavailable" hint="Select a tenant to load real data." /></section>;
    const c = s.contact ?? {};
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
        </section>
    );
}
