import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockItems } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

export default function ItemsPage() {
    const gate = useCanCreate('inventory.manage_items');
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const { data, isMock } = useApiQuery(
        ['items', search, type, status],
        () => api.items({ search, item_type: type || undefined, stock_status: status || undefined, per_page: 25 }),
        { fallback: mockItems }
    );
    const items = Array.isArray(data) ? data : (data?.data ?? mockItems);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Items' }]} />
            <header className="page-head">
                <h1>Items</h1>
                {isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/items/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New item</Link>
            </header>
            <div className="toolbar" style={{ display: 'flex', gap: 8 }}>
                <input className="input" placeholder="Search name or SKU…" value={search}
                    onChange={(e) => setSearch(e.target.value)} />
                <select className="input" value={type} onChange={(e) => setType(e.target.value)}>
                    <option value="">All types</option><option value="inventory">Inventory</option>
                    <option value="non_inventory">Non-inventory</option><option value="service">Service</option>
                </select>
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">Any stock</option><option value="in">In stock</option>
                    <option value="low">Low</option><option value="out">Out</option>
                </select>
            </div>
            <table className="data-table">
                <thead><tr><th></th><th>SKU</th><th>Name</th><th>Type</th><th>Category</th><th>Sales Price</th><th>Status</th></tr></thead>
                <tbody>
                    {items.map((it) => (
                        <tr key={it.id}>
                            <td className="item-thumb-cell">
                                {it.primary_image_url
                                    ? <img className="item-thumb" src={it.primary_image_url} alt="" loading="lazy" />
                                    : <span className="item-thumb item-thumb--ph">📦</span>}
                            </td>
                            <td><Link to={`/items/${it.id}`}>{it.sku}</Link></td>
                            <td>{it.name}</td>
                            <td>{it.item_type}</td>
                            <td>{it.category?.name ?? '—'}</td>
                            <td>{it.sales_price ?? '—'}</td>
                            <td>{it.is_active ? 'Active' : 'Inactive'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}
