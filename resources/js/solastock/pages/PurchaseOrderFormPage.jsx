import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable, DocumentTotals } from '../components/document.jsx';
import { ItemPicker, SupplierPicker, WarehousePicker, QuantityInput, MoneyInput } from '../components/pickers.jsx';

const emptyLine = () => ({ item_id: null, ordered_qty: '', unit_price: '', notes: '' });

export default function PurchaseOrderFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ po_number: '', supplier_id: null, warehouse_id: null, order_date: new Date().toISOString().slice(0, 10), expected_date: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['po', id], () => api.purchaseOrder(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.purchase_order) {
            const po = existing.data.purchase_order;
            if (po.status !== 'draft') { toast.push('Only draft POs can be edited.', 'error'); nav(`/purchase-orders/${id}`); return; }
            setHeader({ po_number: po.po_number, supplier_id: po.supplier_id, warehouse_id: po.warehouse_id, order_date: po.order_date, expected_date: po.expected_date ?? '', notes: po.notes ?? '' });
            setLines((existing.data.lines ?? po.lines ?? []).map((l) => ({ item_id: l.item_id, ordered_qty: l.ordered_qty, unit_price: l.unit_price, notes: l.notes ?? '' })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const total = lines.reduce((s, l) => s + Number(l.ordered_qty || 0) * Number(l.unit_price || 0), 0);

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = { ...header, expected_date: header.expected_date || null, lines: lines.filter((l) => l.item_id && Number(l.ordered_qty) > 0) };
            if (payload.lines.length === 0) { toast.push('Add at least one line.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updatePurchaseOrder(id, payload) : await api.createPurchaseOrder(payload);
            toast.push(isEdit ? 'PO updated.' : 'PO created.', 'success');
            qc.invalidateQueries({ queryKey: ['pos'] });
            nav(`/purchase-orders/${res?.data?.id ?? id}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'qty', label: 'Quantity', width: 120, render: (l, i) => <QuantityInput value={l.ordered_qty} onChange={(v) => setLine(i, { ordered_qty: v })} /> },
        { key: 'price', label: 'Unit cost', width: 120, render: (l, i) => <MoneyInput value={l.unit_price} onChange={(v) => setLine(i, { unit_price: v })} /> },
        { key: 'line_total', label: 'Line total', width: 110, render: (l) => <span>{(Number(l.ordered_qty || 0) * Number(l.unit_price || 0)).toFixed(2)}</span> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Purchase Orders', to: '/purchase-orders' }, { label: isEdit ? 'Edit draft' : 'New' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit purchase order' : 'New purchase order'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="PO number" error={errors.po_number}><input className="input" placeholder="Auto-generated if left blank" value={header.po_number} onChange={(e) => setHeader({ ...header, po_number: e.target.value })} /></Field>
                <Field label="Supplier" error={errors.supplier_id}><SupplierPicker value={header.supplier_id} onChange={(v) => setHeader({ ...header, supplier_id: v })} /></Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label="Order date" error={errors.order_date}><input className="input" type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} /></Field>
                <Field label="Expected date" error={errors.expected_date}><input className="input" type="date" value={header.expected_date} onChange={(e) => setHeader({ ...header, expected_date: e.target.value })} /></Field>
                <Field label="Notes"><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Lines</h2>
                <DocumentLinesTable columns={columns} lines={lines} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <DocumentTotals rows={[{ label: 'Total', value: total.toFixed(2) }]} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/purchase-orders')}>Cancel</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : (isEdit ? 'Save changes' : 'Create PO')}</button>
            </div>
        </section>
    );
}
