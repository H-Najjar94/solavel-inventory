import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState } from '../components/ui.jsx';

export default function CustomerDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_sales_orders');
    const { data, isLoading } = useApiQuery(['customer', id], () => api.customer(id), { fallback: null });
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!data) return <section className="page"><Breadcrumbs items={[{ label: 'Customers', to: '/customers' }, { label: 'Not found' }]} /><EmptyState title="Customer unavailable" hint="Select a tenant to load real data." /></section>;
    const c = data.contact ?? {};
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Customers', to: '/customers' }, { label: data.name }]} />
            <header className="page-head"><h1>{data.name}</h1><StatusBadge active={data.is_active} />
                <Link to={`/customers/${data.id}/edit`} className="btn btn--primary" style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }} title={gate.allowed ? '' : gate.reason}>Edit</Link></header>
            <div className="panel"><dl className="kv">
                <dt>Code</dt><dd>{data.code}</dd><dt>Email</dt><dd>{c.email ?? '—'}</dd>
                <dt>Phone</dt><dd>{c.phone ?? '—'}</dd><dt>Address</dt><dd>{c.address ?? '—'}</dd>
                <dt>Tax number</dt><dd>{c.tax_number ?? '—'}</dd><dt>Currency</dt><dd>{c.currency ?? '—'}</dd>
                <dt>Payment terms</dt><dd>{c.payment_terms ?? '—'}</dd><dt>Notes</dt><dd>{c.notes ?? '—'}</dd>
            </dl></div>
        </section>
    );
}
