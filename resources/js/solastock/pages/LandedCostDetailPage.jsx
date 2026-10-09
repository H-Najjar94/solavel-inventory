import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, ConfirmReverseModal } from '../components/document.jsx';
import LandedCostConnectionPanel from '../components/LandedCostConnectionPanel.jsx';
import { t } from '../i18n/index.js';

export default function LandedCostDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [confirmPost, setConfirmPost] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);
    const { data, isLoading } = useApiQuery(['landed-cost', id], () => api.landedCost(id), { fallback: null });
    const doc = data?.landed_cost;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!doc) return <section className="page"><Breadcrumbs items={[{ label: t('landedCosts.title'), to: '/landed-costs' }, { label: t('landedCosts.notFound') }]} />
        <EmptyState title={t('landedCosts.unavailable')} hint={t('landedCosts.unavailableHint')} /></section>;

    const preview = data?.preview;
    const previewByLine = Object.fromEntries((preview?.lines ?? []).map((l) => [l.landed_cost_line_id, l]));
    const events = data?.accounting_events ?? [];
    const draft = doc.status === 'draft';

    async function act(fn, label) {
        try { await fn(); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['landed-cost'] }); qc.invalidateQueries({ queryKey: ['landed-costs'] }); return true; }
        catch (e) { toast.failure(e, e.message); return false; }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('landedCosts.title'), to: '/landed-costs' }, { label: doc.landed_cost_number }]} />
            <header className="page-head">
                <h1>{doc.landed_cost_number}</h1><DocumentStatusBadge status={doc.status} />
                {draft && <Link to={`/landed-costs/${id}/edit`} className="btn" style={{ marginInlineStart: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>{t('landedCosts.edit')}</Link>}
            </header>
            <LandedCostConnectionPanel status={data?.connection} />

            <div className="panel"><dl className="kv">
                <dt>{t('landedCosts.date')}</dt><dd>{doc.landed_cost_date}</dd>
                <dt>{t('landedCosts.reference')}</dt><dd>{doc.supplier_reference ?? '—'}</dd>
                <dt>{t('landedCosts.method')}</dt><dd>{t(`landedCosts.method.${doc.allocation_method}`, doc.allocation_method)}</dd>
                <dt>{t('landedCosts.currency')}</dt><dd>{doc.currency_code} · {t('landedCosts.rate')} {Number(doc.exchange_rate)}</dd>
                <dt>{t('landedCosts.total')}</dt><dd>{doc.total_amount} {doc.currency_code}</dd>
                <dt>{t('landedCosts.totalBase')}</dt><dd>{doc.total_base_amount}{doc.base_currency_code ? ` ${doc.base_currency_code}` : ''}</dd>
                {!draft && <><dt>{t('landedCosts.onHand')}</dt><dd>{doc.inventory_base_amount}</dd>
                    <dt>{t('landedCosts.consumed')}</dt><dd>{doc.consumed_base_amount}</dd></>}
                <dt>{t('landedCosts.reversal')}</dt><dd>{doc.reversal ? `${doc.reversal.reversal_number} — ${doc.reversal.reason}` : '—'}</dd>
                {doc.notes && <><dt>{t('landedCosts.notes')}</dt><dd>{doc.notes}</dd></>}
            </dl></div>

            <div className="panel"><h2>{t('landedCosts.charges')}</h2>
                <table className="data-table"><thead><tr><th>{t('landedCosts.chargeType')}</th><th>{t('landedCosts.description')}</th><th>{t('landedCosts.amount')}</th><th>{t('landedCosts.totalBase')}</th></tr></thead>
                    <tbody>{(doc.charges ?? []).map((c) => <tr key={c.id}><td>{t(`landedCosts.chargeType.${c.charge_type}`, c.charge_type)}</td><td>{c.description ?? '—'}</td><td>{c.amount}</td><td>{c.base_amount}</td></tr>)}</tbody></table>
            </div>

            <div className="panel"><h2>{draft ? t('landedCosts.preview') : t('landedCosts.receiptLines')}</h2>
                {draft && preview && !preview.ready && <div className="banner banner--warn" role="alert"><strong>{t('landedCosts.previewBlocked')}</strong> {preview.message}</div>}
                <table className="data-table"><thead><tr><th>{t('landedCosts.receipt')}</th><th>{t('landedCosts.item')}</th><th>{t('landedCosts.warehouse')}</th><th>{t('landedCosts.quantity')}</th>
                    <th>{t('landedCosts.allocated')}</th><th>{t('landedCosts.onHand')}</th><th>{t('landedCosts.cogs')}</th><th>{t('landedCosts.adjustmentLoss')}</th></tr></thead>
                <tbody>{(doc.lines ?? []).map((l) => {
                    const p = previewByLine[l.id];
                    const role = (r) => (doc.components ?? []).filter((c) => c.landed_cost_line_id === l.id && c.destination_role === r).reduce((s, c) => s + Number(c.posted_base_amount), 0).toFixed(2);
                    return <tr key={l.id}>
                        <td>{l.receipt?.grn_number ?? `#${l.goods_receipt_id}`}</td>
                        <td>{l.item?.name ?? `#${l.item_id}`}{l.item?.sku && <span className="muted"> · {l.item.sku}</span>}</td>
                        <td>{l.warehouse?.name ?? `#${l.warehouse_id}`}</td><td>{l.quantity}</td>
                        <td>{draft ? (p?.allocated_base_amount ?? l.allocated_base_amount) : l.allocated_base_amount}</td>
                        <td>{draft ? (p?.inventory_base_amount ?? '—') : role('inventory_asset')}</td>
                        <td>{draft ? (p?.cogs_base_amount ?? '—') : role('cogs')}</td>
                        <td>{draft ? (p?.adjustment_loss_base_amount ?? '—') : role('adjustment_loss')}</td>
                    </tr>;
                })}</tbody></table>
            </div>

            <div className="panel"><h2>{t('landedCosts.events')}</h2>
                <p className="muted">{t('landedCosts.journalHint')}</p>
                {events.length === 0 ? <p className="muted">{t('landedCosts.noEvents')}</p> : (
                    <table className="data-table"><thead><tr><th>{t('landedCosts.number')}</th><th>{t('landedCosts.eventType')}</th><th>{t('landedCosts.eventStatus')}</th><th>{t('landedCosts.date')}</th></tr></thead>
                        <tbody>{events.map((e) => <tr key={e.id}><td>{e.aggregate_number}</td><td><bdi>{e.event_type}</bdi></td><td>{e.status}</td><td>{e.occurred_at}</td></tr>)}</tbody></table>
                )}
            </div>

            <DocumentActions status={doc.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} onReverse={() => setConfirmReverse(true)} />
            <ConfirmPostModal open={confirmPost} name="landed cost"
                onConfirm={async () => { if (await act(() => api.postLandedCost(id), t('landedCosts.posted'))) setConfirmPost(false); }} onCancel={() => setConfirmPost(false)} />
            <ConfirmReverseModal open={confirmReverse} name="landed cost"
                onConfirm={async (reason) => { if (await act(() => api.reverseLandedCost(id, reason), t('landedCosts.reversed'))) setConfirmReverse(false); }} onCancel={() => setConfirmReverse(false)} />
        </section>
    );
}
