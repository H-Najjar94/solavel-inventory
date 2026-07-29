import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, EmptyState, Field } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { QuantityInput } from '../components/pickers.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PackDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_packing');
    const canShip = useCanCreate('inventory.manage_shipments');
    const [packs, setPacks] = useState({});
    const [meta, setMeta] = useState({ carrier: '', tracking_number: '', package_count: 1 });
    const [busy, setBusy] = useState(false);

    const { data, isLoading } = useApiQuery(['pack', id], () => api.pack(id), { fallback: null });
    const pk = data?.pack;

    useEffect(() => {
        if (pk?.lines) setPacks(Object.fromEntries(pk.lines.map((l) => [l.id, String(l.packed_qty || l.picked_qty || '')])));
        if (pk) setMeta({ carrier: pk.carrier ?? '', tracking_number: pk.tracking_number ?? '', package_count: pk.package_count ?? 1 });
    }, [pk]);

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!pk) return <section className="page"><Breadcrumbs items={[{ label: t('fulfillment.packing.breadcrumb', 'Packing'), to: '/packs' }, { label: t('fulfillment.common.notFound', 'Not found') }]} /><EmptyState title={t('fulfillment.common.unavailable', 'Unavailable')} hint={t('fulfillment.common.selectOrganization', 'Select an organization to load data.')} /></section>;

    const editable = !['packed', 'cancelled'].includes(pk.status);

    async function savePacks() {
        setBusy(true);
        try { await api.updatePack(id, { packs, ...meta }); toast.push(t('fulfillment.packing.saved', 'Pack saved.'), 'success'); qc.invalidateQueries({ queryKey: ['pack', id] }); }
        catch (e) { toast.push(e.message || t('fulfillment.packing.saveFailed', 'The pack could not be saved.'), 'error'); } finally { setBusy(false); }
    }
    async function finalize() {
        setBusy(true);
        try { await api.markPackPacked(id); toast.push(t('fulfillment.packing.markedPacked', 'Pack marked packed.'), 'success'); qc.invalidateQueries({ queryKey: ['pack', id] }); }
        catch (e) { toast.push(e.message || t('fulfillment.packing.finalizeFailed', 'The pack could not be marked packed.'), 'error'); } finally { setBusy(false); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('fulfillment.packing.breadcrumb', 'Packing'), to: '/packs' }, { label: pk.pack_number }]} />
            <header className="page-head"><h1>{pk.pack_number}</h1><DocumentStatusBadge status={pk.status} /></header>
            <div className="panel"><dl className="kv">
                <dt>{t('fulfillment.common.salesOrder', 'Sales order')}</dt><dd><Link to={`/sales-orders/${pk.sales_order_id}`}>#{pk.sales_order_id}</Link></dd>
                <dt>{t('fulfillment.packing.pickList', 'Pick list')}</dt><dd>{pk.pick_list_id ? `#${pk.pick_list_id}` : '—'}</dd>
            </dl></div>

            {editable && <div className="form-grid">
                <Field label={t('fulfillment.common.carrier', 'Carrier')}><input className="input" value={meta.carrier} onChange={(e) => setMeta({ ...meta, carrier: e.target.value })} /></Field>
                <Field label={t('fulfillment.common.trackingNumber', 'Tracking #')}><input className="input" value={meta.tracking_number} onChange={(e) => setMeta({ ...meta, tracking_number: e.target.value })} /></Field>
                <Field label={t('fulfillment.packing.packages', 'Packages')}><input className="input" type="number" min="1" value={meta.package_count} onChange={(e) => setMeta({ ...meta, package_count: e.target.value })} /></Field>
            </div>}

            <div className="panel"><table className="data-table">
                <thead><tr><th>{t('fulfillment.common.item', 'Item')}</th><th>{t('fulfillment.common.traceability', 'Lot / Serial')}</th><th>{t('fulfillment.common.picked', 'Picked')}</th><th>{t('fulfillment.common.packed', 'Packed')}</th></tr></thead>
                <tbody>{(pk.lines ?? []).map((l) => (
                    <tr key={l.id}><td>#{l.item_id}</td><td>{l.lot_id ? t('fulfillment.common.lotReference', 'Lot #:reference', { reference: l.lot_id }) : ''}{l.lot_id && l.serial_id ? ' · ' : ''}{l.serial_id ? t('fulfillment.common.serialReference', 'Serial #:reference', { reference: l.serial_id }) : ''}{!l.lot_id && !l.serial_id ? '—' : ''}</td><td>{l.picked_qty}</td>
                        <td>{editable ? <QuantityInput value={packs[l.id] ?? ''} onChange={(v) => setPacks({ ...packs, [l.id]: v })} /> : l.packed_qty}</td></tr>
                ))}</tbody>
            </table></div>

            <div className="doc-actions">
                {editable && <button className="btn" disabled={!gate.allowed || busy} onClick={savePacks}>{t('fulfillment.packing.savePack', 'Save pack')}</button>}
                {editable && <button className="btn btn--primary" disabled={!gate.allowed || busy} onClick={finalize}>{t('fulfillment.packing.markPacked', 'Mark packed')}</button>}
                {pk.status === 'packed' && <Link to={`/sales-orders/${pk.sales_order_id}`} className="btn btn--primary" style={{ opacity: canShip.allowed ? 1 : 0.5, pointerEvents: canShip.allowed ? 'auto' : 'none' }}>{t('fulfillment.packing.goToOrder', 'Go to order to ship')}</Link>}
            </div>
        </section>
    );
}
