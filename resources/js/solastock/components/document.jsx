import React from 'react';
import { Link } from 'react-router-dom';
import { ConfirmModal } from './ui.jsx';

const STATUS = {
    draft: ['Draft', 'badge--muted'],
    posted: ['Posted', 'badge--live'],
    reversed: ['Reversed', 'badge--demo'],
    cancelled: ['Cancelled', 'badge--muted'],
    approved: ['Approved', 'badge--live'],
    partially_received: ['Partially received', 'badge--demo'],
    received: ['Received', 'badge--live'],
    in_transit: ['In transit', 'badge--demo'],
    counting: ['Counting', 'badge--demo'],
    review: ['In review', 'badge--demo'],
    // Sales fulfillment
    confirmed: ['Confirmed', 'badge--demo'],
    partially_reserved: ['Partially reserved', 'badge--demo'],
    reserved: ['Reserved', 'badge--demo'],
    picking: ['Picking', 'badge--demo'],
    partially_picked: ['Partially picked', 'badge--demo'],
    picked: ['Picked', 'badge--demo'],
    packing: ['Packing', 'badge--demo'],
    packed: ['Packed', 'badge--demo'],
    partially_shipped: ['Partially shipped', 'badge--demo'],
    shipped: ['Shipped', 'badge--live'],
};

export function DocumentStatusBadge({ status }) {
    const [label, cls] = STATUS[status] ?? [status, 'badge--muted'];
    return <span className={`badge ${cls}`}>{label}</span>;
}

export function SourceDocumentLink({ sourceType, sourceId }) {
    const name = (sourceType ?? '').split('\\').pop();
    const map = {
        OpeningStockEntry: '/opening-stock', StockAdjustment: '/adjustments',
        GoodsReceipt: '/goods-receipts', StockTransfer: '/transfers',
        SalesOrder: '/sales-orders', Shipment: '/shipments', SalesReturn: '/sales-returns',
    };
    const base = map[name];
    const label = `${name} #${sourceId}`;
    return base ? <Link to={`${base}/${sourceId}`}>{label}</Link> : <span>{label}</span>;
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
                        <span className="fulfillment-step__label">{step.charAt(0).toUpperCase() + step.slice(1)}</span>
                    </li>
                );
            })}
        </ol>
    );
}

/** Reservation coverage badge: reserved vs ordered. */
export function ReservationStatusBadge({ reserved = 0, ordered = 0 }) {
    const r = Number(reserved); const o = Number(ordered);
    let label = 'None'; let cls = 'badge--muted';
    if (o > 0 && r >= o) { label = 'Fully reserved'; cls = 'badge--live'; }
    else if (r > 0) { label = `Partial (${r}/${o})`; cls = 'badge--demo'; }
    return <span className={`badge ${cls}`} title={`${r} of ${o} reserved`}>{label}</span>;
}

/** Pick → Pack → Ship timeline from a sales order's per-step quantities. */
export function PickPackShipTimeline({ picked = 0, packed = 0, shipped = 0, ordered = 0 }) {
    const steps = [
        { label: 'Picked', qty: picked }, { label: 'Packed', qty: packed }, { label: 'Shipped', qty: shipped },
    ];
    return (
        <ul className="ppst">
            {steps.map((s) => {
                const done = Number(ordered) > 0 && Number(s.qty) >= Number(ordered);
                return (
                    <li key={s.label} className={done ? 'ppst__step ppst__step--done' : 'ppst__step'}>
                        <span className="ppst__label">{s.label}</span>
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
export function DocumentLinesTable({ columns, lines, onAdd, onRemove, readOnly, addLabel = 'Add line', errors = {} }) {
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
                        <tr><td colSpan={columns.length + (readOnly ? 0 : 1)} className="muted">No lines yet.</td></tr>
                    )}
                    {lines.map((line, i) => (
                        <tr key={i} className={errors[i] ? 'row--error' : ''}>
                            {columns.map((c) => <td key={c.key}>{c.render(line, i)}</td>)}
                            {!readOnly && <td><button type="button" className="btn btn--sm btn--danger" onClick={() => onRemove(i)}>×</button></td>}
                        </tr>
                    ))}
                </tbody>
            </table>
            {!readOnly && <button type="button" className="btn btn--sm" onClick={onAdd}>+ {addLabel}</button>}
        </div>
    );
}

export function DocumentActions({ status, canManage, onSave, onPost, onReverse, onApprove, saving, postLabel = 'Post', extra }) {
    const isDraft = status === 'draft';
    const isPosted = status === 'posted';
    return (
        <div className="doc-actions">
            {extra}
            {isDraft && onSave && <button className="btn" disabled={!canManage || saving} onClick={onSave}>{saving ? 'Saving…' : 'Save draft'}</button>}
            {isDraft && onApprove && <button className="btn btn--primary" disabled={!canManage} onClick={onApprove}>Approve</button>}
            {isDraft && onPost && <button className="btn btn--primary" disabled={!canManage} onClick={onPost}>{postLabel}</button>}
            {isPosted && onReverse && <button className="btn btn--danger" disabled={!canManage} onClick={onReverse}>Reverse</button>}
        </div>
    );
}

export function ConfirmPostModal({ open, onConfirm, onCancel, name = 'document' }) {
    return <ConfirmModal open={open} title={`Post ${name}?`}
        message="Posting writes stock movements to the canonical ledger and locks this document. This cannot be edited afterwards."
        confirmLabel="Post" onConfirm={onConfirm} onCancel={onCancel} />;
}

export function ConfirmReverseModal({ open, onConfirm, onCancel, name = 'document' }) {
    return <ConfirmModal open={open} danger title={`Reverse ${name}?`}
        message="Reversing appends opposite ledger entries to unwind this document's stock. The original entries are kept for audit."
        confirmLabel="Reverse" onConfirm={onConfirm} onCancel={onCancel} />;
}

export function LedgerPreview({ rows }) {
    if (!rows || rows.length === 0) return <p className="muted">No ledger entries yet. They appear here after posting.</p>;
    return (
        <table className="data-table">
            <thead><tr><th>Date</th><th>Item</th><th>WH</th><th>Dir</th><th>Qty</th><th>Unit cost</th><th>Total</th><th>Running qty</th></tr></thead>
            <tbody>{rows.map((r) => (
                <tr key={r.id}><td>{r.moved_at}</td><td>#{r.item_id}</td><td>#{r.warehouse_id}</td>
                    <td>{r.direction}</td><td>{r.quantity}</td><td>{r.unit_cost}</td><td>{r.total_cost}</td><td>{r.balance_qty_after}</td></tr>
            ))}</tbody>
        </table>
    );
}

export function AuditTimeline({ events }) {
    if (!events || events.length === 0) return <p className="muted">No audit events recorded.</p>;
    return (
        <ul className="audit-timeline">
            {events.map((e, i) => (
                <li key={i}><span className="audit-action">{e.action}</span> <span className="muted">{e.created_at}</span>{e.document_ref ? ` · ${e.document_ref}` : ''}</li>
            ))}
        </ul>
    );
}
