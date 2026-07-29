import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';
import { translateCounts } from '../i18n/counts.js';

export default function CountDetailPage() {
    const { id } = useParams();
    const { locale } = useI18n();
    const t = (key, fallback, params) => translateCounts(locale, key, fallback, params);
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['count', id], () => api.count(id), { fallback: null });
    const c = data?.count;
    const adjustment = data?.adjustment;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb'), to: '/counts' }, { label: t('counts.detail.notFound') }]} />
            <EmptyState title={t('counts.detail.errorTitle')} hint={t('counts.detail.errorHint')}
                action={<button className="btn" onClick={() => refetch()}>{t('counts.retry')}</button>} />
        </section>
    );
    if (!c) return <section className="page"><Breadcrumbs items={[{ label: t('counts.breadcrumb'), to: '/counts' }, { label: t('counts.detail.notFound') }]} /><EmptyState title={t('counts.detail.unavailable')} hint={t('counts.detail.unavailableHint')} /></section>;

    async function post() {
        try { await api.postCount(id); toast.push(t('counts.detail.posted'), 'success'); qc.invalidateQueries({ queryKey: ['count'] }); }
        catch { toast.push(t('counts.detail.postFailed'), 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb'), to: '/counts' }, { label: c.count_number }]} />
            <header className="page-head">
                <h1><bdi>{c.count_number}</bdi></h1><DocumentStatusBadge status={c.status} />
                {isMock && <span className="badge badge--warn">{t('counts.sampleData')}</span>}
                {c.status === 'draft' && <Link to={`/counts/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }} title={gate.allowed ? '' : gate.reason}>{t('counts.detail.edit')}</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>{t('counts.detail.type')}</dt><dd>{t(`counts.type.${c.count_type}`, c.count_type)}</dd>
                <dt>{t('counts.detail.mode')}</dt><dd>{t(c.blind_count ? 'counts.detail.blindMode' : 'counts.detail.visibleMode')}</dd>
                <dt>{t('counts.detail.warehouse')}</dt><dd>{c.warehouse_name ?? <bdi>#{c.warehouse_id}</bdi>}</dd>
                <dt>{t('counts.detail.scheduled')}</dt><dd><bdi>{c.scheduled_for ?? '—'}</bdi>{c.recurrence ? ` · ${t(`counts.recurrence.${c.recurrence}`, c.recurrence)}` : ''}</dd>
                <dt>{t('counts.detail.abcClass')}</dt><dd><bdi>{c.abc_class ?? '—'}</bdi></dd>
                <dt>{t('counts.detail.snapshot')}</dt><dd><bdi>{c.snapshot_at ?? '—'}</bdi></dd>
                <dt>{t('counts.detail.adjustment')}</dt><dd>{adjustment ? <Link to={`/adjustments/${adjustment.id}`}><bdi>{adjustment.adjustment_number}</bdi></Link> : '—'}</dd>
                <dt>{t('counts.detail.notes')}</dt><dd>{c.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('counts.detail.linesTab') }, { key: 'ledger', label: t('counts.detail.ledgerTab') }, { key: 'audit', label: t('counts.detail.auditTab') }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>{t('counts.detail.item')}</th><th>{t('counts.detail.bin')}</th><th>{t('counts.detail.snapshotQty')}</th><th>{t('counts.detail.postingExpected')}</th><th>{t('counts.detail.counted')}</th><th>{t('counts.detail.variance')}</th></tr></thead>
                <tbody>
                    {(c.lines ?? []).length === 0 && <tr><td colSpan={6} className="muted">{t('counts.detail.linesEmpty')}</td></tr>}
                    {(c.lines ?? []).map((l) => <tr key={l.id}><td>{l.item?.name ?? <bdi>#{l.item_id}</bdi>}{l.item?.sku && <span className="muted"> · <bdi>{l.item.sku}</bdi></span>}</td><td>{l.bin_id ? <bdi>#{l.bin_id}</bdi> : '—'}</td><td>{c.blind_count && c.status !== 'posted' ? t('counts.form.hidden') : <bdi>{l.snapshot_qty ?? '—'}</bdi>}</td><td>{c.blind_count && c.status !== 'posted' ? t('counts.form.hidden') : <bdi>{l.system_qty}</bdi>}</td><td><bdi>{l.counted_qty ?? '—'}</bdi></td><td><bdi>{c.blind_count && c.status !== 'posted' ? '—' : l.variance_qty}</bdi></td></tr>)}
                </tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel">
                {ledger.length === 0 ? <p className="muted">{t('counts.detail.ledgerEmpty')}</p> : (
                    <table className="data-table">
                        <thead><tr><th>{t('counts.detail.ledgerDate')}</th><th>{t('counts.detail.ledgerItem')}</th><th>{t('counts.detail.ledgerWarehouse')}</th><th>{t('counts.detail.ledgerDirection')}</th><th>{t('counts.detail.ledgerQuantity')}</th><th>{t('counts.detail.ledgerUnitCost')}</th><th>{t('counts.detail.ledgerTotal')}</th><th>{t('counts.detail.ledgerRunningQuantity')}</th></tr></thead>
                        <tbody>{ledger.map((r) => <tr key={r.id}>
                            <td><bdi>{r.moved_at}</bdi></td>
                            <td>{r.item_name ?? <bdi>#{r.item_id}</bdi>}</td>
                            <td>{r.warehouse_name ?? <bdi>#{r.warehouse_id}</bdi>}</td>
                            <td>{t(`counts.detail.direction${r.direction === 'in' ? 'In' : r.direction === 'out' ? 'Out' : ''}`, r.direction)}</td>
                            <td><bdi>{r.quantity}</bdi></td><td><bdi>{r.unit_cost}</bdi></td><td><bdi>{r.total_cost}</bdi></td><td><bdi>{r.balance_qty_after}</bdi></td>
                        </tr>)}</tbody>
                    </table>
                )}
            </div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={t('counts.detail.auditTitle')} hint={t('counts.detail.auditHint')} /></div>}

            <DocumentActions status={c.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} postLabel={t('counts.detail.postVariance')} />
            <ConfirmModal open={confirmPost} title={t('counts.detail.confirmTitle')} message={t('counts.detail.confirmMessage')}
                confirmLabel={t('counts.detail.confirm')} onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
