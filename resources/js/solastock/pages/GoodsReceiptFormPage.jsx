import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput, MoneyInput } from '../components/pickers.jsx';
import { LotCapture, SerialNumberListInput, TraceabilityRequiredBadge } from '../components/traceability.jsx';

const emptyLine = () => ({ item_id: null, received_qty: '', accepted_qty: '', unit_cost: '', bin_id: null, purchase_order_line_id: null, ordered_qty: null, remaining_qty: null, lot_code: '', expiry_date: '', serials: [] });

export default function GoodsReceiptFormPage() {
    const { id, poId } = useParams();
    const isEdit = !!id;
    const fromPo = !!poId;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ grn_number: '', purchase_order_id: poId ? Number(poId) : null, supplier_id: null, warehouse_id: null, receipt_date: new Date().toISOString().slice(0, 10), notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    // Prefill from PO
    const poDraft = useApiQuery(['grn-from-po', poId], () => api.grnDraftFromPo(poId), { fallback: null, enabled: fromPo });
    useEffect(() => {
        if (fromPo && poDraft.data) {
            const po = poDraft.data.purchase_order;
            setHeader((h) => ({ ...h, purchase_order_id: po.id, supplier_id: po.supplier_id, warehouse_id: po.warehouse_id }));
            setLines((poDraft.data.lines ?? []).map((l) => ({
                item_id: l.item_id, purchase_order_line_id: l.purchase_order_line_id,
                ordered_qty: l.ordered_qty, remaining_qty: l.remaining_qty,
                received_qty: l.received_qty, accepted_qty: l.received_qty, unit_cost: l.unit_cost, bin_id: null,
            })));
        }
    }, [fromPo, poDraft.data]);

    // Prefill from existing draft
    const existing = useApiQuery(['grn', id], () => api.goodsReceipt(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.grn) {
            const g = existing.data.grn;
            if (g.status !== 'draft') { toast.push('Only draft GRNs can be edited.', 'error'); nav(`/goods-receipts/${id}`); return; }
            setHeader({ grn_number: g.grn_number, purchase_order_id: g.purchase_order_id, supplier_id: g.supplier_id, warehouse_id: g.warehouse_id, receipt_date: g.receipt_date, notes: g.notes ?? '' });
            setLines((g.lines ?? []).map((l) => ({ item_id: l.item_id, purchase_order_line_id: l.purchase_order_line_id, received_qty: l.received_qty, accepted_qty: l.accepted_qty, unit_cost: l.unit_cost, bin_id: l.bin_id, ordered_qty: null, remaining_qty: null })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    // Item tracking lookup so capture columns appear only for tracked items.
    const itemsList = useApiQuery(['items-picker'], () => api.items({ per_page: 200, is_active: true }), { fallback: [] });
    const itemsArr = Array.isArray(itemsList.data) ? itemsList.data : (itemsList.data?.data ?? []);
    const trackingOf = (id) => itemsArr.find((it) => it.id === id) ?? {};

    async function save(post = false) {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines.filter((l) => l.item_id && (Number(l.received_qty) > 0 || (l.serials ?? []).length > 0)).map((l) => {
                    const t = trackingOf(l.item_id);
                    const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
                    return {
                        item_id: l.item_id, purchase_order_line_id: l.purchase_order_line_id,
                        received_qty: l.received_qty, accepted_qty: l.accepted_qty || l.received_qty,
                        unit_cost: l.unit_cost, bin_id: l.bin_id,
                        lot_code: l.lot_code || undefined,
                        expiry_date: l.expiry_date || undefined,
                        serials: tracksSerial && (l.serials ?? []).length > 0 ? l.serials : undefined,
                    };
                }),
            };
            if (payload.lines.length === 0) { toast.push('Add at least one line with received qty.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateGoodsReceipt(id, payload) : await api.createGoodsReceipt(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postGoodsReceipt(docId); toast.push('GRN posted — stock received.', 'success'); }
            else toast.push(isEdit ? 'Draft updated.' : 'Draft saved.', 'success');
            qc.invalidateQueries({ queryKey: ['grns'] }); qc.invalidateQueries({ queryKey: ['po'] });
            nav(`/goods-receipts/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if ((fromPo && poDraft.isLoading) || (isEdit && existing.isLoading)) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} disabled={fromPo || isEdit} /> },
        ...(fromPo ? [
            { key: 'ord', label: 'Ordered', width: 90, render: (l) => <span>{l.ordered_qty}</span> },
            { key: 'rem', label: 'Remaining', width: 90, render: (l) => <span>{l.remaining_qty}</span> },
        ] : []),
        { key: 'recv', label: 'Received', width: 110, render: (l, i) => {
            const t = trackingOf(l.item_id);
            const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
            return tracksSerial
                ? <span className="muted" title="Quantity is the serial count">{(l.serials ?? []).length}</span>
                : <QuantityInput value={l.received_qty} onChange={(v) => setLine(i, { received_qty: v, accepted_qty: v })} />;
        } },
        { key: 'trace', label: 'Lot / Serial / Expiry', render: (l, i) => {
            const t = trackingOf(l.item_id);
            if (!t.tracking_type || t.tracking_type === 'none') return <span className="muted">—</span>;
            const tracksLot = t.tracking_type === 'lot' || t.tracking_type === 'lot_serial';
            const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} />
                    {tracksLot && <LotCapture value={{ lot_code: l.lot_code, expiry_date: l.expiry_date }}
                        requireExpiry={!!t.tracks_expiry}
                        onChange={(v) => setLine(i, { lot_code: v.lot_code, expiry_date: v.expiry_date })} />}
                    {tracksSerial && <SerialNumberListInput value={l.serials ?? []} autoFocus={false}
                        onChange={(arr) => setLine(i, { serials: arr, received_qty: String(arr.length), accepted_qty: String(arr.length) })} />}
                </div>
            );
        } },
        { key: 'bin', label: 'Bin', render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'cost', label: 'Unit cost', width: 110, render: (l, i) => <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Goods Receipts', to: '/goods-receipts' }, { label: fromPo ? `From PO #${poId}` : (isEdit ? 'Edit draft' : 'New') }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit goods receipt' : 'New goods receipt'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="GRN number" required error={errors.grn_number}><input className="input" value={header.grn_number} onChange={(e) => setHeader({ ...header, grn_number: e.target.value })} /></Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} disabled={fromPo} /></Field>
                <Field label="Received date" error={errors.receipt_date}><input className="input" type="date" value={header.receipt_date} onChange={(e) => setHeader({ ...header, receipt_date: e.target.value })} /></Field>
                <Field label="Source PO">{header.purchase_order_id ? `#${header.purchase_order_id}` : <span className="muted">none (ad-hoc receipt)</span>}</Field>
                <Field label="Notes"><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Lines</h2>
                <DocumentLinesTable columns={columns} lines={lines}
                    onAdd={fromPo ? undefined : () => setLines([...lines, emptyLine()])}
                    onRemove={fromPo ? () => {} : (i) => setLines(lines.filter((_, idx) => idx !== i))}
                    readOnly={false} />
                {fromPo && <p className="muted">Lines come from the PO's remaining quantities. Adjust received amounts for partial receipts.</p>}
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/goods-receipts')}>Cancel</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? 'Saving…' : 'Save draft'}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => save(true)}>Save & post</button>
            </div>
        </section>
    );
}
