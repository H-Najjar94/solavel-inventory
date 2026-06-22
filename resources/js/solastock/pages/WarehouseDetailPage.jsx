import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, StatusBadge, EmptyState } from '../components/ui.jsx';
import WarehouseFloorMap from '../components/WarehouseFloorMap.jsx';
import WarehouseImages from '../components/WarehouseImages.jsx';

export default function WarehouseDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_warehouses');
    const toast = useToast();
    const qc = useQueryClient();
    const [tab, setTab] = useState('overview');
    const [zoneForm, setZoneForm] = useState({ code: '', name: '' });
    const [binForm, setBinForm] = useState({ zone_id: '', code: '', bin_type: 'storage', capacity: '' });

    const { data, isLoading, isMock } = useApiQuery(['warehouse', id], () => api.warehouse(id), { fallback: null });
    const w = data?.warehouse;
    const zones = data?.zones ?? [];
    const bins = data?.bins ?? [];
    const stock = data?.stock ?? [];
    const recent = data?.recent_movements ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!w) return <section className="page"><Breadcrumbs items={[{ label: 'Warehouses', to: '/warehouses' }, { label: 'Not found' }]} /><EmptyState title="Warehouse unavailable" hint="Select a tenant to load real data." /></section>;

    async function addZone(e) {
        e.preventDefault();
        try { await api.createZone(id, zoneForm); toast.push('Zone created.', 'success'); setZoneForm({ code: '', name: '' }); qc.invalidateQueries({ queryKey: ['warehouse', id] }); }
        catch (err) { toast.push(err.message, 'error'); }
    }
    async function addBin(e) {
        e.preventDefault();
        try { await api.createBin(id, binForm); toast.push('Bin created.', 'success'); setBinForm({ zone_id: '', code: '', bin_type: 'storage', capacity: '' }); qc.invalidateQueries({ queryKey: ['warehouse', id] }); }
        catch (err) { toast.push(err.message, 'error'); }
    }

    const tabs = [
        { key: 'overview', label: 'Overview' }, { key: 'zones', label: `Zones (${zones.length})` },
        { key: 'bins', label: `Bins (${bins.length})` }, { key: 'stock', label: 'Stock' },
        { key: 'movements', label: 'Movements' }, { key: 'media', label: 'Media' }, { key: 'floor', label: 'Floor map' }, { key: 'audit', label: 'Audit' },
    ];
    const editStyle = { marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 };

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Warehouses', to: '/warehouses' }, { label: w.name }]} />

            {/* Banner hero — wide primary image (or placeholder). */}
            <div className="wh-hero">
                {data.primary_image_url
                    ? <img src={data.primary_image_url} alt={w.name} className="wh-hero-img" />
                    : <div className="wh-hero-ph"><span>🏬</span><span className="wh-hero-ph-txt">No warehouse image</span></div>}
                <div className="wh-hero-overlay">
                    <span className="wh-hero-name">{w.name}</span>
                    <span className="wh-hero-sub">{w.code} · {w.type}</span>
                </div>
            </div>

            <header className="page-head">
                <h1>{w.name}</h1><StatusBadge active={w.is_active} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                <Link to={`/warehouses/${w.id}/edit`} className="btn btn--primary" style={editStyle} title={gate.allowed ? '' : gate.reason}>Edit</Link>
            </header>

            <Tabs tabs={tabs} active={tab} onChange={setTab} />

            {tab === 'overview' && <div className="panel"><dl className="kv">
                <dt>Code</dt><dd>{w.code}</dd><dt>Type</dt><dd>{w.type}</dd>
                <dt>Capacity</dt><dd>{w.max_capacity_units ?? '—'}</dd>
                <dt>Low-stock items</dt><dd>{data?.low_stock_count ?? 0}</dd>
            </dl></div>}

            {tab === 'zones' && <div className="panel">
                {gate.allowed && <form className="inline-form" onSubmit={addZone}>
                    <input className="input" placeholder="Zone code" value={zoneForm.code} onChange={(e) => setZoneForm({ ...zoneForm, code: e.target.value })} required />
                    <input className="input" placeholder="Zone name" value={zoneForm.name} onChange={(e) => setZoneForm({ ...zoneForm, name: e.target.value })} required />
                    <button className="btn btn--primary btn--sm">Add zone</button>
                </form>}
                {zones.length === 0 ? <EmptyState title="No zones" /> : (
                    <table className="data-table"><thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead>
                    <tbody>{zones.map((z) => <tr key={z.id}><td>{z.code}</td><td>{z.name}</td><td>{z.is_active ? 'Active' : 'Inactive'}</td></tr>)}</tbody></table>
                )}
            </div>}

            {tab === 'bins' && <div className="panel">
                {gate.allowed && <form className="inline-form" onSubmit={addBin}>
                    <select className="input" value={binForm.zone_id} onChange={(e) => setBinForm({ ...binForm, zone_id: e.target.value })} required>
                        <option value="">Zone…</option>{zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                    </select>
                    <input className="input" placeholder="Bin code" value={binForm.code} onChange={(e) => setBinForm({ ...binForm, code: e.target.value })} required />
                    <select className="input" value={binForm.bin_type} onChange={(e) => setBinForm({ ...binForm, bin_type: e.target.value })}>
                        {['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'damaged'].map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                    <input className="input" type="number" placeholder="Capacity" value={binForm.capacity} onChange={(e) => setBinForm({ ...binForm, capacity: e.target.value })} />
                    <button className="btn btn--primary btn--sm">Add bin</button>
                </form>}
                {bins.length === 0 ? <EmptyState title="No bins" /> : (
                    <table className="data-table"><thead><tr><th>Code</th><th>Zone</th><th>Type</th><th>Capacity</th><th>Status</th></tr></thead>
                    <tbody>{bins.map((b) => <tr key={b.id}><td>{b.code}</td><td>#{b.zone_id}</td><td>{b.coords?.bin_type ?? 'storage'}</td><td>{b.capacity ?? '—'}</td><td>{b.is_active ? 'Active' : 'Inactive'}</td></tr>)}</tbody></table>
                )}
            </div>}

            {tab === 'stock' && <div className="panel">{stock.length === 0 ? <EmptyState title="No stock" /> : (
                <table className="data-table"><thead><tr><th>Item</th><th>On hand</th><th>Available</th><th>Value</th></tr></thead>
                <tbody>{stock.map((s) => <tr key={s.id}><td>#{s.item_id}</td><td>{s.on_hand_qty}</td><td>{s.available_qty}</td><td>{s.total_value}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'movements' && <div className="panel">{recent.length === 0 ? <EmptyState title="No recent movements" /> : (
                <table className="data-table"><thead><tr><th>Date</th><th>Item</th><th>Dir</th><th>Qty</th></tr></thead>
                <tbody>{recent.map((m) => <tr key={m.id}><td>{m.moved_at}</td><td>#{m.item_id}</td><td>{m.direction}</td><td>{m.quantity}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'media' && <div className="panel"><WarehouseImages warehouseId={w.id} canManage={gate.allowed} /></div>}

            {tab === 'floor' && <div className="panel"><WarehouseFloorMap zones={zones} bins={bins} balances={stock} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="Warehouse change events appear here." /></div>}
        </section>
    );
}
