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
import { getLocale, t } from '../i18n/index.js';

const BIN_TYPES = ['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'damaged'];

function warehouseTypeLabel(value) {
    return t(`warehouseDetail.type.${value}`, value);
}

function binTypeLabel(value) {
    return t(`warehouseDetail.binType.${value}`, value);
}

function movementDirectionLabel(value) {
    return t(`warehouseDetail.direction.${value}`, value);
}

function formatMovementDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(getLocale() === 'ar' ? 'ar-JO' : 'en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export default function WarehouseDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_warehouses');
    const toast = useToast();
    const qc = useQueryClient();
    const [tab, setTab] = useState('overview');
    const [zoneForm, setZoneForm] = useState({ code: '', name: '' });
    const [binForm, setBinForm] = useState({ zone_id: '', code: '', bin_type: 'storage', capacity: '' });
    const [labels, setLabels] = useState(null);

    const { data, isLoading, isMock } = useApiQuery(['warehouse', id], () => api.warehouse(id), { fallback: null });
    const w = data?.warehouse;
    const zones = data?.zones ?? [];
    const bins = data?.bins ?? [];
    const stock = data?.stock ?? [];
    const recent = data?.recent_movements ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!w) return <section className="page"><Breadcrumbs items={[{ label: t('warehouses'), to: '/warehouses' }, { label: t('warehouseDetail.notFound') }]} /><EmptyState title={t('warehouseDetail.unavailable')} hint={t('warehouseDetail.unavailableHint')} /></section>;

    async function addZone(e) {
        e.preventDefault();
        try { await api.createZone(id, zoneForm); toast.push(t('warehouseDetail.zoneCreated'), 'success'); setZoneForm({ code: '', name: '' }); qc.invalidateQueries({ queryKey: ['warehouse', id] }); }
        catch { toast.push(t('warehouseDetail.zoneCreateFailed'), 'error'); }
    }
    async function addBin(e) {
        e.preventDefault();
        try { await api.createBin(id, binForm); toast.push(t('warehouseDetail.binCreated'), 'success'); setBinForm({ zone_id: '', code: '', bin_type: 'storage', capacity: '' }); qc.invalidateQueries({ queryKey: ['warehouse', id] }); }
        catch { toast.push(t('warehouseDetail.binCreateFailed'), 'error'); }
    }
    async function loadLabels() {
        try { const res = await api.warehouseLabels(id); setLabels(res.data); toast.push(t('warehouseDetail.binLabelsGenerated'), 'success'); }
        catch { toast.push(t('warehouseDetail.binLabelsFailed'), 'error'); }
    }

    const tabs = [
        { key: 'overview', label: t('warehouseDetail.overview') }, { key: 'zones', label: t('warehouseDetail.zones', undefined, { count: zones.length }) },
        { key: 'bins', label: t('warehouseDetail.bins', undefined, { count: bins.length }) }, { key: 'stock', label: t('warehouseDetail.stock') },
        { key: 'movements', label: t('warehouseDetail.movements') }, { key: 'media', label: t('warehouseDetail.media') }, { key: 'floor', label: t('warehouseDetail.floorMap') }, { key: 'audit', label: t('warehouseDetail.audit') },
    ];
    const editStyle = { marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 };

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('warehouses'), to: '/warehouses' }, { label: w.name }]} />

            {/* Banner hero — wide primary image (or placeholder). */}
            <div className="wh-hero">
                {data.primary_image_url
                    ? <img src={data.primary_image_url} alt={w.name} className="wh-hero-img" />
                    : <div className="wh-hero-ph"><span>🏬</span><span className="wh-hero-ph-txt">{t('warehouseDetail.noImage')}</span></div>}
                <div className="wh-hero-overlay">
                    <span className="wh-hero-name">{w.name}</span>
                    <span className="wh-hero-sub"><bdi>{w.code}</bdi> · {warehouseTypeLabel(w.type)}</span>
                </div>
            </div>

            <header className="page-head">
                <h1>{w.name}</h1><StatusBadge active={w.is_active} />
                {isMock && <span className="badge badge--warn">{t('warehouses.sampleData')}</span>}
                <Link to={`/warehouses/${w.id}/edit`} className="btn btn--primary" style={editStyle} title={gate.allowed ? '' : t('warehouseDetail.manageDenied')}>{t('warehouses.edit')}</Link>
            </header>

            <Tabs tabs={tabs} active={tab} onChange={setTab} />

            {tab === 'overview' && <div className="panel"><dl className="kv">
                <dt>{t('warehouses.code')}</dt><dd><bdi>{w.code}</bdi></dd><dt>{t('warehouses.type')}</dt><dd>{warehouseTypeLabel(w.type)}</dd>
                <dt>{t('warehouses.capacity')}</dt><dd>{w.max_capacity_units ?? '—'}</dd>
                <dt>{t('warehouseDetail.lowStockItems')}</dt><dd>{data?.low_stock_count ?? 0}</dd>
            </dl></div>}

            {tab === 'zones' && <div className="panel">
                {gate.allowed && <form className="inline-form" onSubmit={addZone}>
                    <input className="input" placeholder={t('warehouseDetail.zoneCode')} aria-label={t('warehouseDetail.zoneCode')} value={zoneForm.code} onChange={(e) => setZoneForm({ ...zoneForm, code: e.target.value })} required />
                    <input className="input" placeholder={t('warehouseDetail.zoneName')} aria-label={t('warehouseDetail.zoneName')} value={zoneForm.name} onChange={(e) => setZoneForm({ ...zoneForm, name: e.target.value })} required />
                    <button className="btn btn--primary btn--sm">{t('warehouseDetail.addZone')}</button>
                </form>}
                {zones.length === 0 ? <EmptyState title={t('warehouseDetail.noZones')} hint={t('warehouseDetail.noZonesHint')} /> : (
                    <table className="data-table"><thead><tr><th>{t('warehouseDetail.code')}</th><th>{t('warehouseDetail.name')}</th><th>{t('warehouseDetail.status')}</th></tr></thead>
                    <tbody>{zones.map((z) => <tr key={z.id}><td><bdi>{z.code}</bdi></td><td>{z.name}</td><td>{z.is_active ? t('warehouseDetail.active') : t('warehouseDetail.inactive')}</td></tr>)}</tbody></table>
                )}
            </div>}

            {tab === 'bins' && <div className="panel">
                {gate.allowed && <form className="inline-form" onSubmit={addBin}>
                    <select className="input" value={binForm.zone_id} onChange={(e) => setBinForm({ ...binForm, zone_id: e.target.value })} required>
                        <option value="">{t('warehouseDetail.selectZone')}</option>{zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                    </select>
                    <input className="input" placeholder={t('warehouseDetail.binCode')} aria-label={t('warehouseDetail.binCode')} value={binForm.code} onChange={(e) => setBinForm({ ...binForm, code: e.target.value })} required />
                    <select className="input" aria-label={t('warehouseDetail.binType')} value={binForm.bin_type} onChange={(e) => setBinForm({ ...binForm, bin_type: e.target.value })}>
                        {BIN_TYPES.map((type) => <option key={type} value={type}>{binTypeLabel(type)}</option>)}
                    </select>
                    <input className="input" type="number" placeholder={t('warehouseDetail.capacity')} aria-label={t('warehouseDetail.capacity')} value={binForm.capacity} onChange={(e) => setBinForm({ ...binForm, capacity: e.target.value })} />
                    <button className="btn btn--primary btn--sm">{t('warehouseDetail.addBin')}</button>
                </form>}
                <div className="toolbar"><button className="btn btn--sm" onClick={loadLabels}>{t('warehouseDetail.printBinLabels')}</button></div>
                {bins.length === 0 ? <EmptyState title={t('warehouseDetail.noBins')} hint={t('warehouseDetail.noBinsHint')} /> : (
                    <table className="data-table"><thead><tr><th>{t('warehouseDetail.code')}</th><th>{t('warehouseDetail.zone')}</th><th>{t('warehouseDetail.binType')}</th><th>{t('warehouseDetail.capacity')}</th><th>{t('warehouseDetail.status')}</th></tr></thead>
                    <tbody>{bins.map((b) => <tr key={b.id}><td><bdi>{b.code}</bdi></td><td><bdi>#{b.zone_id}</bdi></td><td>{binTypeLabel(b.coords?.bin_type ?? 'storage')}</td><td>{b.capacity ?? '—'}</td><td>{b.is_active ? t('warehouseDetail.active') : t('warehouseDetail.inactive')}</td></tr>)}</tbody></table>
                )}
                {labels && <div className="label-sheet">
                    {(labels.labels ?? []).map((l) => <div className="label-card" key={l.bin_id}>
                        <strong><bdi>{l.warehouse_code} / {l.bin_code}</bdi></strong>
                        <span><bdi>{l.zone_code ?? t('warehouseDetail.labelZoneFallback')}</bdi> · {binTypeLabel(l.bin_type)}</span>
                        <code dir="ltr">{l.barcode}</code>
                        <span className="qr-preview" dangerouslySetInnerHTML={{ __html: l.qr_svg }} />
                    </div>)}
                </div>}
            </div>}

            {tab === 'stock' && <div className="panel">{stock.length === 0 ? <EmptyState title={t('warehouseDetail.noStock')} hint={t('warehouseDetail.noStockHint')} /> : (
                <table className="data-table"><thead><tr><th>{t('warehouseDetail.item')}</th><th>{t('warehouseDetail.onHand')}</th><th>{t('warehouseDetail.available')}</th><th>{t('warehouseDetail.value')}</th></tr></thead>
                <tbody>{stock.map((s) => <tr key={s.id}><td>{s.item_name ?? <bdi>#{s.item_id}</bdi>}{s.item_sku && <span className="muted"> · <bdi>{s.item_sku}</bdi></span>}</td><td>{s.on_hand_qty}</td><td>{s.available_qty}</td><td>{s.total_value}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'movements' && <div className="panel">{recent.length === 0 ? <EmptyState title={t('warehouseDetail.noRecentMovements')} hint={t('warehouseDetail.noRecentMovementsHint')} /> : (
                <table className="data-table"><thead><tr><th>{t('warehouseDetail.date')}</th><th>{t('warehouseDetail.item')}</th><th>{t('warehouseDetail.direction')}</th><th>{t('warehouseDetail.quantity')}</th></tr></thead>
                <tbody>{recent.map((m) => <tr key={m.id}><td><bdi>{formatMovementDate(m.moved_at)}</bdi></td><td>{m.item_name ?? <bdi>#{m.item_id}</bdi>}{m.item_sku && <span className="muted"> · <bdi>{m.item_sku}</bdi></span>}</td><td>{movementDirectionLabel(m.direction)}</td><td>{m.quantity}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'media' && <div className="panel"><WarehouseImages warehouseId={w.id} canManage={gate.allowed} /></div>}

            {tab === 'floor' && <div className="panel"><WarehouseFloorMap zones={zones} bins={bins} balances={stock} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={t('warehouseDetail.auditTimeline')} hint={t('warehouseDetail.auditHint')} /></div>}
        </section>
    );
}
