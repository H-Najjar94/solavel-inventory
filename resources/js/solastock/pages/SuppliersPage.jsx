import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

export default function SuppliersPage() {
    const gate = useCanCreate('inventory.manage_items');
    const [search, setSearch] = useState('');
    const { data, isMock } = useApiQuery(['suppliers', search], () => api.suppliers({ search, per_page: 50 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Suppliers' }]} />
            <header className="page-head">
                <h1>Suppliers</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/suppliers/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New supplier</Link>
            </header>
            <div className="toolbar"><input className="input" placeholder="Search name or code…" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            {list.length === 0 ? <EmptyState title="No suppliers" hint="Add a supplier to use on items and purchase orders." /> : (
                <table className="data-table"><thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>{list.map((s) => <tr key={s.id}><td><Link to={`/suppliers/${s.id}`}>{s.code}</Link></td><td>{s.name}</td><td>{s.is_active ? 'Active' : 'Inactive'}</td></tr>)}</tbody></table>
            )}
        </section>
    );
}
