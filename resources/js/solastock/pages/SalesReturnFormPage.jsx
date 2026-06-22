import React, { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput, MoneyInput } from '../components/pickers.jsx';

const emptyLine = () => ({ item_id: null, returned_qty: '', unit_cost: '', condition: 'resellable', bin_id: null });

export default function SalesReturnFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const [sp] = useSearchParams();
    const shipmentId = sp.get('shipment_id');
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_returns');

    const [header, setHeader] = useState({ return_number: '', shipment_id: shipmentId ? Number(shipmentId) : null, customer_name: '', warehouse_id: null, return_date: new Date().toISOString().slice(0, 10), reason: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    // Prefill warehouse + lines from the source shipment.
    const ship = useApiQuery(['shipment-for-return', shipmentId], () => api.shipment(shipmentId), { fallback: null, enabled: !!shipmentId && !isEdit });
    useEffect(() => {
        if (!isEdit && shipmentId && ship.data?.shipment) {
            const s = ship.data.shipment;
            setHeader((h) => ({ ...h, warehouse_id: s.warehouse_id }));
            // Preserve the shipped lot/serial identity on the return lines.
            setLines((s.lines ?? []).map((l) => ({ item_id: l.item_id, returned_qty: l.quantity, unit_cost: l.unit_cost ?? '', condition: 'resellable', bin_id: l.bin_id, lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, is_manual: false })));
        }
    }, [isEdit, shipmentId, ship.data]);

    const existing = useApiQuery(['sales-return', id], () => api.salesReturn(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.sales_return) {
            const r = existing.data.sales_return;
            if (r.status !== 'draft') { toast.push('Only draft returns can be edited.', 'error'); nav(`/sales-returns/${id}`); return; }
            setHeader({ return_number: r.return_number, shipment_id: r.shipment_id, customer_name: r.customer_name ?? '', warehouse_id: r.warehouse_id, return_date: r.return_date?.slice(0, 10), reason: r.reason ?? '', notes: r.notes ?? '' });
            setLines((r.lines ?? []).map((l) => ({ item_id: l.item_id, returned_qty: l.returned_qty, unit_cost: l.unit_cost, condition: l.condition, bin_id: l.bin_id, lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, is_manual: !r.shipment_id })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines.filter((l) => l.item_id && Number(l.returned_qty) > 0).map((l) => ({ item_id: l.item_id, returned_qty: l.returned_qty, unit_cost: l.unit_cost || 0, condition: l.condition, bin_id: l.bin_id, lot_id: l.lot_id || undefined, serial_id: l.serial_id || undefined, lot_code: l.lot_code || undefined, is_manual: !header.shipment_id })),
            };
            if (payload.lines.length === 0) { toast.push('Add at least one line with returned qty.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateSalesReturn(id, payload) : await api.createSalesReturn(payload);
            const docId = res?.data?.id ?? id;
            toast.push(isEdit ? 'Draft updated.' : 'Return created.', 'success');
            qc.invalidateQueries({ queryKey: ['sales-returns'] });
            nav(`/sales-returns/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if ((isEdit && existing.isLoading) || (shipmentId && !isEdit && ship.isLoading)) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'qty', label: 'Returned qty', width: 110, render: (l, i) => <QuantityInput value={l.returned_qty} onChange={(v) => setLine(i, { returned_qty: v })} /> },
        { key: 'cond', label: 'Condition', width: 140, render: (l, i) => (
            <select className="input" value={l.condition} onChange={(e) => setLine(i, { condition: e.target.value })}>
                <option value="resellable">Resellable</option>
                <option value="quarantine">Quarantine</option>
                <option value="damaged">Damaged (no restock)</option>
                <option value="retired">Retired (no restock)</option>
            </select>) },
        { key: 'bin', label: 'Bin', render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'cost', label: 'Unit cost', width: 110, render: (l, i) => <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Returns', to: '/sales-returns' }, { label: isEdit ? 'Edit draft' : 'New' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit sales return' : 'New sales return'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="Return number" required error={errors.return_number}><input className="input" value={header.return_number} onChange={(e) => setHeader({ ...header, return_number: e.target.value })} /></Field>
                <Field label="Customer name" error={errors.customer_name}><input className="input" value={header.customer_name} onChange={(e) => setHeader({ ...header, customer_name: e.target.value })} /></Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label="Return date" error={errors.return_date}><input className="input" type="date" value={header.return_date} onChange={(e) => setHeader({ ...header, return_date: e.target.value })} /></Field>
                <Field label="Source shipment">{header.shipment_id ? `#${header.shipment_id}` : <span className="muted">none</span>}</Field>
                <Field label="Reason"><input className="input" value={header.reason} onChange={(e) => setHeader({ ...header, reason: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Lines</h2>
                <DocumentLinesTable columns={columns} lines={lines}
                    onAdd={() => setLines([...lines, emptyLine()])}
                    onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} readOnly={false} />
                <p className="muted">Resellable &amp; quarantine units re-enter stock at the unit cost they shipped at. Damaged units are recorded only.</p>
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/sales-returns')}>Cancel</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : 'Save draft'}</button>
            </div>
        </section>
    );
}
