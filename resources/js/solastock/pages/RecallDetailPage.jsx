import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function RecallDetailPage() {
    const { locale, t } = useI18n();
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_recalls');
    const [tab, setTab] = useState('impact');
    const [confirmActivate, setConfirmActivate] = useState(false);

    const { data, isLoading, isError, refetch } = useApiQuery(['recall', id], () => api.recall(id), { fallback: null });
    const recall = data?.recall;
    const impact = data?.impact;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls'), to: '/recalls' }, { label: t('recalls.common.notFound', 'Not found') }]} />
        <EmptyState title={t('recalls.detail.loadErrorTitle', 'Recall details could not be loaded')} hint={t('recalls.detail.loadErrorHint', 'Check your connection and try again.')}
            action={<button className="btn" onClick={() => refetch()}>{t('recalls.list.retry', 'Try again')}</button>} />
    </section>;
    if (!recall) return <section className="page"><Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls'), to: '/recalls' }, { label: t('recalls.common.notFound', 'Not found') }]} /><EmptyState title={t('recalls.common.unavailable', 'Unavailable')} hint={t('recalls.common.selectOrganization', 'Select an organization to load recall data.')} /></section>;

    async function act(fn, msg) {
        try { await fn(); toast.push(msg, 'success'); qc.invalidateQueries({ queryKey: ['recall', id] }); qc.invalidateQueries({ queryKey: ['recalls'] }); }
        catch (e) { toast.push(e.message || t('recalls.messages.actionFailed', 'The recall action could not be completed.'), 'error'); }
    }

    function csvCell(value) {
        const text = value == null ? '' : String(value);
        return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
    }

    function exportCsv() {
        const rows = impact?.lines ?? [];
        const head = ['itemId', 'lotId', 'serialId', 'onHand', 'reserved', 'shipped', 'warehouses', 'customers']
            .map((key) => t(`recalls.csv.${key}`));
        const csv = [head].concat(rows.map((l) => [
            l.item_id, l.lot_id ?? '', l.serial_id ?? '', l.on_hand, l.reserved, l.shipped,
            (l.warehouses ?? []).join(' '),
            (l.shipped_documents ?? []).map((d) => d.customer).filter(Boolean).join(' | '),
        ])).map((row) => row.map(csvCell).join(',')).join('\r\n');
        const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = `${recall.recall_number}-impact.csv`; a.click();
        URL.revokeObjectURL(a.href);
    }

    function shipmentCount(count) {
        if (count === 0) return t('recalls.detail.shipmentCount.zero', 'No shipments');
        if (count === 1) return t('recalls.detail.shipmentCount.one', '1 shipment');
        if (locale === 'ar' && count === 2) return t('recalls.detail.shipmentCount.two', 'شحنتان');
        return t('recalls.detail.shipmentCount.other', ':count shipments', { count });
    }

    function actionDetail(action) {
        const known = {
            created: 'Recall case drafted.',
            activated: 'Recall activated; affected lots flagged recalled, serials quarantined.',
            closed: 'Recall closed.',
        };
        return action.detail === known[action.action] ? t(`recalls.actionDetail.${action.action}`, action.detail) : action.detail;
    }

    function displayDate(value) {
        if (!value) return '';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale === 'ar' ? 'ar-JO' : 'en', {
            dateStyle: 'medium', timeStyle: 'short',
        }).format(date);
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls'), to: '/recalls' }, { label: recall.recall_number }]} />
            <header className="page-head"><h1><bdi>{recall.recall_number}</bdi></h1><span className="badge badge--muted">{t(`recalls.status.${recall.status}`, recall.status)}</span></header>

            <div className="panel"><dl className="kv">
                <dt>{t('recalls.common.item', 'Item')}</dt><dd><bdi>{recall.item ? `${recall.item.sku} · ${recall.item.name}` : `#${recall.item_id}`}</bdi></dd>
                <dt>{t('recalls.common.scope', 'Scope')}</dt><dd>{t(`recalls.scope.${recall.scope}`, recall.scope)}</dd>
                <dt>{t('recalls.common.reason', 'Reason')}</dt><dd>{recall.reason ?? '—'}</dd>
                <dt>{t('recalls.detail.affectedOnHand', 'Affected on-hand quantity')}</dt><dd>{impact?.totals?.on_hand}</dd>
                <dt>{t('recalls.detail.affectedReserved', 'Affected reserved quantity')}</dt><dd>{impact?.totals?.reserved}</dd>
                <dt>{t('recalls.detail.affectedShipped', 'Affected shipped quantity')}</dt><dd>{impact?.totals?.shipped}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'impact', label: t('recalls.detail.impact', 'Impact') }, { key: 'actions', label: t('recalls.detail.actionsTimeline', 'Actions timeline') }]} active={tab} onChange={setTab} />
            {tab === 'impact' && <div className="panel">
                <div className="page-head" style={{ marginTop: 0 }}><h3 style={{ margin: 0 }}>{t('recalls.detail.affectedStock', 'Affected stock')}</h3>
                    <button className="btn btn--sm" style={{ marginInlineStart: 'auto' }} onClick={exportCsv}>{t('recalls.detail.exportCsv', 'Export CSV')}</button></div>
                {(impact?.lines ?? []).length === 0 ? <EmptyState title={t('recalls.detail.noImpactTitle', 'No affected stock')} hint={t('recalls.detail.noImpactHint', 'No stock impact has been recorded for this recall.')} /> : <table className="data-table">
                    <thead><tr><th>{t('recalls.common.lot', 'Lot')}</th><th>{t('recalls.common.serial', 'Serial number')}</th><th>{t('recalls.detail.onHand', 'On hand')}</th><th>{t('recalls.detail.reserved', 'Reserved')}</th><th>{t('recalls.detail.shipped', 'Shipped')}</th><th>{t('recalls.detail.warehouses', 'Warehouses')}</th><th>{t('recalls.detail.shippedDocuments', 'Shipped documents')}</th><th>{t('recalls.detail.customers', 'Customers')}</th></tr></thead>
                    <tbody>{(impact?.lines ?? []).map((l) => (
                        <tr key={l.recall_line_id}><td><bdi>{l.lot_id ? `#${l.lot_id}` : '—'}</bdi></td><td><bdi>{l.serial_id ? `#${l.serial_id}` : '—'}</bdi></td>
                            <td>{l.on_hand}</td><td>{l.reserved}</td><td>{l.shipped}</td><td><bdi>{(l.warehouses ?? []).map((w) => `#${w}`).join(', ') || '—'}</bdi></td>
                            <td>{shipmentCount((l.shipped_documents ?? []).length)}</td><td>{(l.shipped_documents ?? []).map((d) => d.customer).filter(Boolean).join(', ') || '—'}</td></tr>
                    ))}</tbody>
                </table>}
            </div>}
            {tab === 'actions' && <div className="panel">
                {(recall.actions ?? []).length === 0 ? <p className="muted">{t('recalls.detail.noActions', 'No actions recorded.')}</p> : (
                    <ul className="audit-timeline">{recall.actions.map((a) => <li key={a.id}><span className="audit-action">{t(`recalls.action.${a.action}`, a.action)}</span> <span className="muted">{displayDate(a.created_at)}</span>{a.detail ? <span> · {actionDetail(a)}</span> : null}</li>)}</ul>
                )}
            </div>}

            <div className="doc-actions">
                {recall.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmActivate(true)}>{t('recalls.detail.activate', 'Activate recall')}</button>}
                {recall.status === 'active' && <button className="btn btn--danger" disabled={!gate.allowed} onClick={() => act(() => api.closeRecall(id), t('recalls.messages.closed', 'Recall closed.'))}>{t('recalls.detail.close', 'Close recall')}</button>}
            </div>
            <ConfirmModal open={confirmActivate} danger title={t('recalls.detail.activateTitle', 'Activate recall?')}
                message={t('recalls.detail.activateMessage', 'Activating the recall marks affected lots as recalled and serial numbers as quarantined. They cannot be shipped without an override.')}
                confirmLabel={t('recalls.detail.activateConfirm', 'Activate')} onConfirm={() => { setConfirmActivate(false); act(() => api.activateRecall(id), t('recalls.messages.activated', 'Recall activated.')); }} onCancel={() => setConfirmActivate(false)} />
        </section>
    );
}
