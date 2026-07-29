import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ConfirmModal } from './ui.jsx';
import { t } from '../i18n/index.js';

const STATUS = {
    draft: ['document.draft', 'badge--muted'], posted: ['document.posted', 'badge--live'], reversed: ['document.reversed', 'badge--demo'], cancelled: ['document.cancelled', 'badge--muted'], approved: ['document.approved', 'badge--live'], partially_received: ['document.partiallyReceived', 'badge--demo'], received: ['document.received', 'badge--live'], in_transit: ['document.inTransit', 'badge--demo'], counting: ['document.counting', 'badge--demo'], review: ['document.review', 'badge--demo'],
    // Sales fulfillment
    confirmed: ['document.confirmed', 'badge--demo'], partially_reserved: ['document.partiallyReserved', 'badge--demo'], reserved: ['document.reserved', 'badge--demo'], picking: ['document.picking', 'badge--demo'], partially_picked: ['document.partiallyPicked', 'badge--demo'], picked: ['document.picked', 'badge--demo'], packing: ['document.packing', 'badge--demo'], packed: ['document.packed', 'badge--demo'], partially_shipped: ['document.partiallyShipped', 'badge--demo'], shipped: ['document.shipped', 'badge--live'],
};

export function DocumentStatusBadge({ status }) {
    const [label, cls] = STATUS[status] ?? [status, 'badge--muted'];
    return <span className={`badge ${cls}`}>{t(label, status)}</span>;
}

export function SourceDocumentLink({ sourceType, sourceId, sourceDisplay, sourceRoute }) {
    const name = (sourceType ?? '').split('\\').pop();
    const map = {
        OpeningStockEntry: '/opening-stock', StockAdjustment: '/adjustments',
        GoodsReceipt: '/goods-receipts', StockTransfer: '/transfers',
        SalesOrder: '/sales-orders', Shipment: '/shipments', SalesReturn: '/sales-returns',
    };
    const base = map[name];
    const label = sourceDisplay || `${name} #${sourceId}`;
    const route = sourceRoute || (base ? `${base}/${sourceId}` : null);
    return route ? <Link to={route}>{label}</Link> : <span>{label}</span>;
}

// ── Sales fulfillment UI ──

const FULFILLMENT_STEPS = ['confirmed', 'reserved', 'picked', 'packed', 'shipped'];
const STEP_REACHED = {
    draft: -1, confirmed: 0,
    partially_reserved: 0, reserved: 1,
    picking: 1, partially_picked: 1, picked: 2,
    packing: 2, packed: 3,
    partially_shipped: 3, shipped: 4,
    cancelled: -1,
};

/** Horizontal stepper for a sales order's fulfillment lifecycle. */
export function FulfillmentProgressStepper({ status }) {
    const reached = STEP_REACHED[status] ?? -1;
    const cancelled = status === 'cancelled';
    return (
        <ol className="fulfillment-stepper">
            {FULFILLMENT_STEPS.map((step, i) => {
                const state = cancelled ? 'cancelled' : i < reached ? 'done' : i === reached ? 'current' : 'todo';
                return (
                    <li key={step} className={`fulfillment-step fulfillment-step--${state}`}>
                        <span className="fulfillment-step__dot">{i + 1}</span>
                        <span className="fulfillment-step__label">{t(`document.${step}`, step)}</span>
                    </li>
                );
            })}
        </ol>
    );
}

/** Reservation coverage badge: reserved vs ordered. */
export function ReservationStatusBadge({ reserved = 0, ordered = 0 }) {
    const r = Number(reserved); const o = Number(ordered);
    let label = t('document.reservation.none'); let cls = 'badge--muted';
    if (o > 0 && r >= o) { label = t('document.reservation.full'); cls = 'badge--live'; }
    else if (r > 0) { label = t('document.reservation.partial', undefined, { reserved: r, ordered: o }); cls = 'badge--demo'; }
    return <span className={`badge ${cls}`} title={t('document.reservation.title', undefined, { reserved: r, ordered: o })}>{label}</span>;
}

/** Pick → Pack → Ship timeline from a sales order's per-step quantities. */
export function PickPackShipTimeline({ picked = 0, packed = 0, shipped = 0, ordered = 0 }) {
    const steps = [
        { key: 'picked', qty: picked }, { key: 'packed', qty: packed }, { key: 'shipped', qty: shipped },
    ];
    return (
        <ul className="ppst">
            {steps.map((s) => {
                const done = Number(ordered) > 0 && Number(s.qty) >= Number(ordered);
                return (
                    <li key={s.key} className={done ? 'ppst__step ppst__step--done' : 'ppst__step'}>
                        <span className="ppst__label">{t(`document.${s.key}`)}</span>
                        <span className="ppst__qty">{s.qty}{Number(ordered) > 0 ? ` / ${ordered}` : ''}</span>
                    </li>
                );
            })}
        </ul>
    );
}

export function DocumentTotals({ rows }) {
    return (
        <div className="doc-totals">
            {rows.map((r) => (
                <div key={r.label} className="doc-total"><span>{r.label}</span><strong>{r.value}</strong></div>
            ))}
        </div>
    );
}

/**
 * Generic line editor table. `columns` = [{ key, label, render(line, set, i), width? }].
 * Read-only mode hides add/remove and disables inputs (callers pass disabled cols).
 */
export function DocumentLinesTable({ columns, lines, onAdd, onRemove, readOnly, addLabel, errors = {} }) {
    return (
        <div className="doc-lines">
            <table className="data-table">
                <thead>
                    <tr>
                        {columns.map((c) => <th key={c.key} style={c.width ? { width: c.width } : undefined}>{c.label}</th>)}
                        {!readOnly && <th style={{ width: 40 }} />}
                    </tr>
                </thead>
                <tbody>
                    {lines.length === 0 && (
                        <tr><td colSpan={columns.length + (readOnly ? 0 : 1)} className="muted">{t('document.noLines')}</td></tr>
                    )}
                    {lines.map((line, i) => (
                        <tr key={i} className={errors[i] ? 'row--error' : ''}>
                            {columns.map((c) => <td key={c.key}>{c.render(line, i)}</td>)}
                            {!readOnly && <td><button type="button" className="btn btn--sm btn--danger" onClick={() => onRemove(i)}>×</button></td>}
                        </tr>
                    ))}
                </tbody>
            </table>
            {!readOnly && <button type="button" className="btn btn--sm" onClick={onAdd}>+ {addLabel ?? t('document.addLine')}</button>}
        </div>
    );
}

export function DocumentActions({ status, canManage, onSave, onPost, onReverse, onApprove, saving, postLabel, extra }) {
    const isDraft = status === 'draft';
    const isPosted = status === 'posted';
    return (
        <div className="doc-actions">
            {extra}
            {isDraft && onSave && <button className="btn" disabled={!canManage || saving} onClick={onSave}>{saving ? t('document.saving') : t('document.saveDraft')}</button>}
            {isDraft && onApprove && <button className="btn btn--primary" disabled={!canManage} onClick={onApprove}>{t('document.approve')}</button>}
            {isDraft && onPost && <button className="btn btn--primary" disabled={!canManage} onClick={onPost}>{postLabel ?? t('document.post')}</button>}
            {isPosted && onReverse && <button className="btn btn--danger" disabled={!canManage} onClick={onReverse}>{t('document.reverse')}</button>}
        </div>
    );
}

export function ConfirmPostModal({ open, onConfirm, onCancel, name = 'document' }) {
    const localizedName = t(`document.kind.${name}`, name);
    return <ConfirmModal open={open} title={t('document.confirmPostTitle', undefined, { name: localizedName })}
        message={t('document.confirmPostMessage')}
        confirmLabel={t('document.post')} onConfirm={onConfirm} onCancel={onCancel} />;
}

export function ConfirmReverseModal({ open, onConfirm, onCancel, name = 'document' }) {
    const [reason, setReason] = useState('');
    useEffect(() => { if (open) setReason(''); }, [open]);
    if (!open) return null;
    return <div className="modal-overlay" onClick={onCancel}>
        <div className="modal" onClick={(e) => e.stopPropagation()}>
            <h3>{t('document.confirmReverseTitle', undefined, { name: t(`document.kind.${name}`, name) })}</h3>
            <p>{t('document.confirmReverseMessage')}</p>
            <label className="field">
                <span className="field-label">{t('document.reason')} <span className="field-req">*</span></span>
                <textarea value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} placeholder={t('document.reversePlaceholder')} />
            </label>
            <div className="modal-actions">
                <button className="btn" onClick={onCancel}>{t('document.cancel')}</button>
                <button className="btn btn--danger" disabled={reason.trim().length < 3} onClick={() => onConfirm(reason.trim())}>{t('document.reverse')}</button>
            </div>
        </div>
    </div>;
}

export function LedgerPreview({ rows }) {
    if (!rows || rows.length === 0) return <p className="muted">{t('document.noLedger')}</p>;
    return (
        <table className="data-table">
            <thead><tr><th>{t('document.date')}</th><th>{t('document.item')}</th><th>{t('document.warehouseShort')}</th><th>{t('document.direction')}</th><th>{t('document.quantity')}</th><th>{t('document.unitCost')}</th><th>{t('document.total')}</th><th>{t('document.runningQuantity')}</th></tr></thead>
            <tbody>{rows.map((r) => (
                <tr key={r.id}><td>{r.moved_at}</td><td>{r.item_name ?? `#${r.item_id}`}</td><td>{r.warehouse_name ?? `#${r.warehouse_id}`}</td>
                    <td>{t(`document.direction.${r.direction}`, r.direction)}</td><td>{r.quantity}</td><td>{r.unit_cost}</td><td>{r.total_cost}</td><td>{r.balance_qty_after}</td></tr>
            ))}</tbody>
        </table>
    );
}

export function AuditTimeline({ events }) {
    if (!events || events.length === 0) return <p className="muted">{t('document.noAudit')}</p>;
    return (
        <ul className="audit-timeline">
            {events.map((e, i) => (
                <li key={i}><span className="audit-action">{e.action}</span> <span className="muted">{e.created_at}</span>{e.document_ref ? ` · ${e.document_ref}` : ''}</li>
            ))}
        </ul>
    );
}
