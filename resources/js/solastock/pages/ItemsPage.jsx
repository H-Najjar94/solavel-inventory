import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockItems } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Field } from '../components/ui.jsx';
import { useMeta } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';

export default function ItemsPage() {
    const gate = useCanCreate('inventory.manage_items');
    const meta = useMeta();
    const toast = useToast();
    const qc = useQueryClient();
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [selected, setSelected] = useState([]);
    const [bulk, setBulk] = useState({ field: 'is_active', value: '1' });
    const { data, isMock } = useApiQuery(
        ['items', search, type, status],
        () => api.items({ search, item_type: type || undefined, stock_status: status || undefined, per_page: 25 }),
        { fallback: mockItems }
    );
    const items = Array.isArray(data) ? data : (data?.data ?? mockItems);
    const allSelected = items.length > 0 && selected.length === items.length;

    function toggle(id) {
        setSelected((ids) => ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]);
    }

    async function applyBulk(e) {
        e.preventDefault();
        if (selected.length === 0) return;
        const body = { item_ids: selected };
        if (bulk.field === 'is_active' || bulk.field === 'enable_reorder_alert') body[bulk.field] = bulk.value === '1';
        else if (['category_id', 'brand_id'].includes(bulk.field)) body[bulk.field] = bulk.value ? Number(bulk.value) : null;
        else body[bulk.field] = bulk.value;
        try {
            const res = await api.bulkUpdateItems(body);
            setSelected([]);
            await qc.invalidateQueries({ queryKey: ['items'] });
            toast.push(`Updated ${res.data.updated} item(s).`, 'success');
        } catch (err) {
            toast.push(err.message || 'Bulk update failed.', 'error');
        }
    }

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
            {gate.allowed && <form className="panel report-filters" onSubmit={applyBulk}>
                <Field label="Selected"><input className="input" value={`${selected.length} item(s)`} readOnly /></Field>
                <Field label="Bulk field"><select className="input" value={bulk.field} onChange={(e) => setBulk({ field: e.target.value, value: e.target.value === 'is_active' || e.target.value === 'enable_reorder_alert' ? '1' : '' })}>
                    <option value="is_active">Status</option>
                    <option value="category_id">Category</option>
                    <option value="brand_id">Brand</option>
                    <option value="enable_reorder_alert">Reorder alerts</option>
                    <option value="reorder_point">Reorder point</option>
                    <option value="reorder_qty">Reorder quantity</option>
                </select></Field>
                {(bulk.field === 'is_active' || bulk.field === 'enable_reorder_alert') && <Field label="Value"><select className="input" value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })}><option value="1">Yes / Active</option><option value="0">No / Inactive</option></select></Field>}
                {bulk.field === 'category_id' && <Field label="Category"><select className="input" value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })}><option value="">Clear category</option>{(meta.lookups?.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>}
                {bulk.field === 'brand_id' && <Field label="Brand"><select className="input" value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })}><option value="">Clear brand</option>{(meta.lookups?.brands ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>}
                {['reorder_point', 'reorder_qty'].includes(bulk.field) && <Field label="Value"><input className="input" type="number" step="0.0001" min="0" value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })} required /></Field>}
                <div style={{ alignSelf: 'end' }}><button className="btn btn--primary" disabled={selected.length === 0}>Apply</button></div>
            </form>}
            <table className="data-table">
                <thead><tr><th><input type="checkbox" checked={allSelected} onChange={(e) => setSelected(e.target.checked ? items.map((it) => it.id) : [])} /></th><th></th><th>SKU</th><th>Name</th><th>Type</th><th>Category</th><th>Sales Price</th><th>Status</th></tr></thead>
                <tbody>
                    {items.length === 0 && <tr><td colSpan="8"><EmptyState title="No items" /></td></tr>}
                    {items.map((it) => (
                        <tr key={it.id}>
                            <td><input type="checkbox" checked={selected.includes(it.id)} onChange={() => toggle(it.id)} disabled={!gate.allowed} /></td>
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
