import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

export default function CustomersPage() {
    const gate = useCanCreate('inventory.manage_sales_orders');
    const [search, setSearch] = useState('');
    const { data, isMock } = useApiQuery(['customers', search], () => api.customers({ search, per_page: 50 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Customers' }]} />
            <header className="page-head"><h1>Customers</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/customers/new" className="btn btn--primary" style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }} title={gate.allowed ? '' : gate.reason}>New customer</Link></header>
            <div className="toolbar"><input className="input" placeholder="Search name or code…" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            {list.length === 0 ? <EmptyState title="No customers" hint="Add customers for sales orders, returns and recall impact." /> : (
                <table className="data-table"><thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Status</th></tr></thead>
                <tbody>{list.map((c) => <tr key={c.id}><td><Link to={`/customers/${c.id}`}>{c.code}</Link></td><td>{c.name}</td><td>{c.contact?.email ?? '—'}</td><td>{c.is_active ? 'Active' : 'Inactive'}</td></tr>)}</tbody></table>
            )}
        </section>
    );
}
