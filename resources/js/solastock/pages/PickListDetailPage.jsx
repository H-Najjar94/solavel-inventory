import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { QuantityInput } from '../components/pickers.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PickListDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_picking');
    const canPack = useCanCreate('inventory.manage_packing');
    const [picks, setPicks] = useState({});
    const [busy, setBusy] = useState(false);

    const { data, isLoading } = useApiQuery(['pick-list', id], () => api.pickList(id), { fallback: null });
    const pl = data?.pick_list;

    useEffect(() => {
        if (pl?.lines) setPicks(Object.fromEntries(pl.lines.map((l) => [l.id, String(l.picked_qty ?? '')])));
    }, [pl]);

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!pl) return <section className="page"><Breadcrumbs items={[{ label: t('fulfillment.picking.breadcrumb', 'Picking'), to: '/pick-lists' }, { label: t('fulfillment.common.notFound', 'Not found') }]} /><EmptyState title={t('fulfillment.common.unavailable', 'Unavailable')} hint={t('fulfillment.common.selectOrganization', 'Select an organization to load data.')} /></section>;

    const editable = !['picked', 'cancelled'].includes(pl.status);

    async function savePicks() {
        setBusy(true);
        try { await api.updatePickList(id, { picks }); toast.push(t('fulfillment.picking.picksSaved', 'Picks saved.'), 'success'); qc.invalidateQueries({ queryKey: ['pick-list', id] }); }
        catch (e) { toast.push(e.message || t('fulfillment.picking.saveFailed', 'The picks could not be saved.'), 'error'); } finally { setBusy(false); }
    }
    async function finalize() {
        setBusy(true);
        try { await api.markPickListPicked(id); toast.push(t('fulfillment.picking.markedPicked', 'Pick list marked picked.'), 'success'); qc.invalidateQueries({ queryKey: ['pick-list', id] }); }
        catch (e) { toast.push(e.message || t('fulfillment.picking.finalizeFailed', 'The pick list could not be marked picked.'), 'error'); } finally { setBusy(false); }
    }
    async function makePack() {
        setBusy(true);
        try {
            const res = await api.createPack({ pick_list_id: pl.id, pack_number: `PACK-${pl.pick_number}` });
            toast.push(t('fulfillment.picking.packCreated', 'Pack created.'), 'success'); nav(`/packs/${res?.data?.id}`);
        } catch (e) { toast.push(e.message || t('fulfillment.picking.packCreateFailed', 'The pack could not be created.'), 'error'); } finally { setBusy(false); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('fulfillment.picking.breadcrumb', 'Picking'), to: '/pick-lists' }, { label: pl.pick_number }]} />
            <header className="page-head"><h1>{pl.pick_number}</h1><DocumentStatusBadge status={pl.status} /></header>
            <div className="panel"><dl className="kv">
                <dt>{t('fulfillment.common.salesOrder', 'Sales order')}</dt><dd><Link to={`/sales-orders/${pl.sales_order_id}`}>#{pl.sales_order_id}</Link></dd>
                <dt>{t('fulfillment.common.warehouse', 'Warehouse')}</dt><dd>#{pl.warehouse_id}</dd>
            </dl></div>

            <div className="panel"><table className="data-table">
                <thead><tr><th>{t('fulfillment.common.item', 'Item')}</th><th>{t('fulfillment.common.bin', 'Bin')}</th><th>{t('fulfillment.common.traceability', 'Lot / Serial')}</th><th>{t('fulfillment.common.reserved', 'Reserved')}</th><th>{t('fulfillment.common.picked', 'Picked')}</th></tr></thead>
                <tbody>{(pl.lines ?? []).map((l) => (
                    <tr key={l.id}><td>#{l.item_id}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.lot_id ? t('fulfillment.common.lotReference', 'Lot #:reference', { reference: l.lot_id }) : ''}{l.lot_id && l.serial_id ? ' · ' : ''}{l.serial_id ? t('fulfillment.common.serialReference', 'Serial #:reference', { reference: l.serial_id }) : ''}{!l.lot_id && !l.serial_id ? '—' : ''}</td><td>{l.reserved_qty}</td>
                        <td>{editable ? <QuantityInput value={picks[l.id] ?? ''} onChange={(v) => setPicks({ ...picks, [l.id]: v })} /> : l.picked_qty}</td></tr>
                ))}</tbody>
            </table></div>

            <div className="doc-actions">
                {editable && <button className="btn" disabled={!gate.allowed || busy} onClick={savePicks}>{t('fulfillment.picking.savePicks', 'Save picks')}</button>}
                {editable && <button className="btn btn--primary" disabled={!gate.allowed || busy} onClick={finalize}>{t('fulfillment.picking.markPicked', 'Mark picked')}</button>}
                {pl.status === 'picked' && <button className="btn btn--primary" disabled={!canPack.allowed || busy} onClick={makePack}>{t('fulfillment.picking.createPack', 'Create pack')}</button>}
            </div>
        </section>
    );
}
