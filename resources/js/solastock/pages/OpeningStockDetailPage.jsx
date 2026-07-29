import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, ConfirmReverseModal, LedgerPreview } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';
import { openingStockT } from '../i18n/openingStock.js';

export default function OpeningStockDetailPage() {
    const { locale } = useI18n();
    const t = (key, params) => openingStockT(locale, key, params);
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_opening_stock');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['opening', id], () => api.openingStockEntry(id), { fallback: null });
    const entry = data?.entry;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!entry) return <section className="page"><Breadcrumbs items={[{ label: t('openingStock.title'), to: '/opening-stock' }, { label: t('openingStock.notFound') }]} /><EmptyState title={t('openingStock.unavailable')} hint={t('openingStock.unavailableHint')} /></section>;

    async function act(fn, label, after) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['opening'] }); after?.(); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('openingStock.title'), to: '/opening-stock' }, { label: entry.entry_number }]} />
            <header className="page-head">
                <h1><bdi>{entry.entry_number}</bdi></h1><DocumentStatusBadge status={entry.status} />
                {isMock && <span className="badge badge--warn">{t('openingStock.sampleData')}</span>}
                {entry.status === 'draft' && (
                    <Link to={`/opening-stock/${id}/edit`} className="btn"
                        style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}>{t('openingStock.edit')}</Link>
                )}
            </header>

            <div className="panel"><dl className="kv">
                <dt>{t('openingStock.openingDate')}</dt><dd>{entry.opening_date}</dd>
                <dt>{t('openingStock.warehouse')}</dt><dd>{entry.warehouse_name ?? <bdi>#{entry.warehouse_id}</bdi>}</dd>
                <dt>{t('openingStock.totalValue')}</dt><dd>{entry.total_value}</dd>
                <dt>{t('openingStock.notes')}</dt><dd>{entry.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('openingStock.lines') }, { key: 'ledger', label: t('openingStock.ledgerResult') }, { key: 'audit', label: t('openingStock.audit') }]} active={tab} onChange={setTab} />

            {tab === 'lines' && (
                <div className="panel"><table className="data-table">
                    <thead><tr><th>{t('openingStock.item')}</th><th>{t('openingStock.bin')}</th><th>{t('openingStock.quantity')}</th><th>{t('openingStock.unitCost')}</th><th>{t('openingStock.total')}</th></tr></thead>
                    <tbody>{(entry.lines ?? []).map((l) => <tr key={l.id}><td>{l.item?.name ?? <bdi>#{l.item_id}</bdi>}{l.item?.sku && <span className="muted"> · <bdi>{l.item.sku}</bdi></span>}</td><td>{l.bin_id ? <bdi>#{l.bin_id}</bdi> : '—'}</td><td>{l.quantity}{l.entered_unit ? <span className="muted"> (<bdi>{l.entered_qty} {l.entered_unit.code}</bdi>)</span> : null}</td><td>{l.unit_cost}</td><td>{l.total_cost}</td></tr>)}</tbody>
                </table></div>
            )}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={t('openingStock.auditTimeline')} hint={t('openingStock.auditHint')} /></div>}

            <DocumentActions status={entry.status} canManage={gate.allowed}
                onPost={() => setConfirmPost(true)} onReverse={() => setConfirmReverse(true)} postLabel={t('openingStock.post')} />

            <ConfirmPostModal open={confirmPost} name="opening stock"
                onConfirm={() => { setConfirmPost(false); act(api.postOpeningStock, t('openingStock.posted')); }} onCancel={() => setConfirmPost(false)} />
            <ConfirmReverseModal open={confirmReverse} name="opening stock"
                onConfirm={() => { setConfirmReverse(false); act(api.reverseOpeningStock, t('openingStock.reversed')); }} onCancel={() => setConfirmReverse(false)} />
        </section>
    );
}
