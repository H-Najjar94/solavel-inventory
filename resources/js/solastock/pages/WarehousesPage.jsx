import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockWarehouses } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

export default function WarehousesPage() {
    const gate = useCanCreate('inventory.manage_warehouses');
    const { data, isMock } = useApiQuery(['warehouses'], () => api.warehouses({ per_page: 50 }), { fallback: mockWarehouses });
    const list = Array.isArray(data) ? data : (data?.data ?? mockWarehouses);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Warehouses' }]} />
            <header className="page-head">
                <h1>Warehouses</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/warehouses/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New warehouse</Link>
            </header>
            {list.length === 0 ? <EmptyState title="No warehouses" hint="Create your first warehouse." /> : (
                <table className="data-table"><thead><tr><th></th><th>Code</th><th>Name</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>{list.map((w) => (<tr key={w.id}>
                    <td className="wh-thumb-cell">{w.primary_image_url
                        ? <img className="wh-banner-thumb" src={w.primary_image_url} alt="" loading="lazy" />
                        : <span className="wh-banner-thumb wh-banner-thumb--ph">🏬</span>}</td>
                    <td><Link to={`/warehouses/${w.id}`}>{w.code}</Link></td><td>{w.name}</td><td>{w.type}</td><td>{w.is_active ? 'Active' : 'Inactive'}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
