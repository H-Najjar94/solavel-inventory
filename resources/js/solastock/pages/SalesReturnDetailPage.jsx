import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, LedgerPreview, ConfirmPostModal } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SalesReturnDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_returns');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['sales-return', id], () => api.salesReturn(id), { fallback: null });
    const r = data?.sales_return;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!r) return <section className="page"><Breadcrumbs items={[{ label: t('returns.list.title', 'Sales Returns'), to: '/sales-returns' }, { label: t('returns.common.notFound', 'Not found') }]} /><EmptyState title={t('returns.common.unavailable', 'Unavailable')} hint={t('returns.common.selectOrganization', 'Select an organization to load data.')} /></section>;

    async function action(fn, msg) {
        try { await fn(); toast.push(msg, 'success'); qc.invalidateQueries({ queryKey: ['sales-return', id] }); qc.invalidateQueries({ queryKey: ['sales-returns'] }); }
        catch (e) { toast.push(e.message || t('returns.messages.actionFailed', 'The return action could not be completed.'), 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('returns.list.title', 'Sales Returns'), to: '/sales-returns' }, { label: r.return_number }]} />
            <header className="page-head"><h1>{r.return_number}</h1><DocumentStatusBadge status={r.status} />{isMock && <span className="badge badge--warn">{t('returns.common.sampleData', 'Sample data')}</span>}
                {r.status === 'draft' && <Link to={`/sales-returns/${id}/edit`} className="btn" style={{ marginInlineStart: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>{t('returns.common.edit', 'Edit')}</Link>}</header>

            <div className="panel"><dl className="kv">
                <dt>{t('returns.common.customer', 'Customer')}</dt><dd>{r.customer_name ?? '—'}</dd>
                <dt>{t('returns.detail.returnDate', 'Return date')}</dt><dd>{r.return_date}</dd>
                <dt>{t('returns.common.warehouse', 'Warehouse')}</dt><dd>#{r.warehouse_id}</dd>
                <dt>{t('returns.detail.sourceShipment', 'Source shipment')}</dt><dd>{r.shipment_id ? <Link to={`/shipments/${r.shipment_id}`}>#{r.shipment_id}</Link> : '—'}</dd>
                <dt>{t('returns.detail.authorized', 'Authorized')}</dt><dd>{r.authorized_at ?? '—'}</dd>
                <dt>{t('returns.detail.inspected', 'Inspected')}</dt><dd>{r.inspected_at ?? '—'}</dd>
                <dt>{t('returns.common.reason', 'Reason')}</dt><dd>{r.reason ?? '—'}</dd>
                <dt>{t('returns.common.notes', 'Notes')}</dt><dd>{r.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('returns.common.lines', 'Lines') }, { key: 'ledger', label: t('returns.detail.ledgerResult', 'Ledger result') }, { key: 'audit', label: t('returns.common.audit', 'Audit') }]} active={tab} onChange={setTab} />
            {tab === 'lines' && ((r.lines ?? []).length === 0
                ? <div className="panel"><EmptyState title={t('returns.detail.noLinesTitle', 'No return lines')} hint={t('returns.detail.noLinesHint', 'This return does not contain any item lines.')} /></div>
                : <div className="panel"><table className="data-table">
                    <thead><tr><th>{t('returns.common.item', 'Item')}</th><th>{t('returns.detail.returned', 'Returned')}</th><th>{t('returns.detail.condition', 'Condition')}</th><th>{t('returns.detail.inspection', 'Inspection')}</th><th>{t('returns.detail.disposition', 'Disposition')}</th><th>{t('returns.common.bin', 'Bin')}</th><th>{t('returns.form.traceability', 'Lot / Serial')}</th><th>{t('returns.common.unitCost', 'Unit cost')}</th></tr></thead>
                    <tbody>{r.lines.map((l) => <tr key={l.id}>
                        <td>{l.item?.name ?? `#${l.item_id}`}{l.item?.sku && <span className="muted"> · {l.item.sku}</span>}</td>
                        <td>{l.returned_qty}</td>
                        <td>{t(`returns.condition.${l.condition}`, l.condition)}</td>
                        <td>{t(`returns.inspection.${l.inspection_status ?? 'pending'}`, l.inspection_status ?? 'pending')}</td>
                        <td>{l.disposition ? t(`returns.disposition.${l.disposition}`, l.disposition) : '—'}</td>
                        <td>{l.bin_id ? `#${l.bin_id}` : '—'}</td>
                        <td>{l.lot_id ? t('returns.form.lotReference', 'Lot #:reference', { reference: l.lot_id }) : (l.serial_id ? t('returns.form.serialReference', 'Serial #:reference', { reference: l.serial_id }) : t('returns.form.traceabilityNone', 'Not tracked'))}</td>
                        <td>{l.unit_cost}</td>
                    </tr>)}</tbody>
                </table></div>)}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={t('returns.common.auditTimeline', 'Audit timeline')} hint={t('returns.detail.auditHint', 'Return creation, authorization, inspection, and posting events are recorded in the audit log.')} /></div>}

            <div className="doc-actions">
                {r.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => action(() => api.authorizeSalesReturn(id), t('returns.messages.authorized', 'RMA authorized.'))}>{t('returns.actions.authorize', 'Authorize RMA')}</button>}
                {r.status === 'authorized' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => action(() => api.inspectSalesReturn(id), t('returns.messages.inspected', 'Return inspected.'))}>{t('returns.actions.inspect', 'Mark inspected')}</button>}
                {['inspected', 'authorized', 'draft'].includes(r.status) && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmPost(true)}>{t('returns.actions.post', 'Post return')}</button>}
            </div>
            <ConfirmPostModal open={confirmPost} name={t('returns.detail.confirmPostName', 'sales return')}
                onConfirm={() => { setConfirmPost(false); action(() => api.postSalesReturn(id), t('returns.messages.posted', 'Return posted. Eligible units have been returned to stock.')); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
