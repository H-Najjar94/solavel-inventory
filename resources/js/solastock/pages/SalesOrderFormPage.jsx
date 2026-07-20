import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, CustomerPicker, QuantityInput, MoneyInput } from '../components/pickers.jsx';

const emptyLine = () => ({ item_id: null, ordered_qty: '', unit_price: '', discount_rate: '', tax_rate: '', tax_code: '' });

export default function SalesOrderFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_sales_orders');

    const [header, setHeader] = useState({ order_number: '', customer_id: null, customer_name: '', warehouse_id: null, order_date: new Date().toISOString().slice(0, 10), requested_ship_date: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['sales-order', id], () => api.salesOrder(id), { fallback: null, enabled: isEdit });
    const settings = useApiQuery(['settings'], api.settings, { fallback: { settings: { taxes: [] } } });
    const taxOptions = (settings.data?.settings?.taxes ?? []).filter((tax) => tax.active && tax.sales);
    useEffect(() => {
        const code = settings.data?.settings?.default_sales_tax_code;
        if (!isEdit && code) setLines((current) => current.map((line) => line.tax_code ? line : { ...line, tax_code: code, tax_rate: taxOptions.find((tax) => tax.code === code)?.rate ?? 0 }));
    }, [isEdit, settings.data?.settings?.default_sales_tax_code]);
    useEffect(() => {
        if (isEdit && existing.data?.sales_order) {
            const s = existing.data.sales_order;
            if (s.status !== 'draft') { toast.push('Only draft sales orders can be edited.', 'error'); nav(`/sales-orders/${id}`); return; }
            setHeader({ order_number: s.order_number, customer_id: s.customer_id ?? null, customer_name: s.customer_name ?? '', warehouse_id: s.warehouse_id, order_date: s.order_date?.slice(0, 10), requested_ship_date: s.requested_ship_date?.slice(0, 10) ?? '', notes: s.notes ?? '' });
            setLines((s.lines ?? []).map((l) => ({ item_id: l.item_id, ordered_qty: l.ordered_qty, unit_price: l.unit_price, discount_rate: l.discount_rate ?? '', tax_rate: l.tax_rate ?? '', tax_code: l.tax_code ?? '' })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const money = (v) => Number(v || 0).toFixed(2);

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                requested_ship_date: header.requested_ship_date || null,
                lines: lines.filter((l) => l.item_id && Number(l.ordered_qty) > 0).map((l) => ({ item_id: l.item_id, ordered_qty: l.ordered_qty, unit_price: l.unit_price || 0, discount_rate: l.discount_rate || 0, tax_code: l.tax_code || undefined })),
            };
            if (payload.lines.length === 0) { toast.push('Add at least one line with ordered qty.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateSalesOrder(id, payload) : await api.createSalesOrder(payload);
            const docId = res?.data?.id ?? id;
            toast.push(isEdit ? 'Draft updated.' : 'Sales order created.', 'success');
            qc.invalidateQueries({ queryKey: ['sales-orders'] });
            nav(`/sales-orders/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'qty', label: 'Ordered qty', width: 120, render: (l, i) => <QuantityInput value={l.ordered_qty} onChange={(v) => setLine(i, { ordered_qty: v })} /> },
        { key: 'price', label: 'Unit price', width: 120, render: (l, i) => <MoneyInput value={l.unit_price} onChange={(v) => setLine(i, { unit_price: v })} /> },
        { key: 'disc', label: 'Discount %', width: 110, render: (l, i) => <MoneyInput value={l.discount_rate} onChange={(v) => setLine(i, { discount_rate: v })} /> },
        { key: 'tax', label: 'Tax', width: 150, render: (l, i) => <select className="input" aria-label={`Sales tax line ${i + 1}`} value={l.tax_code} onChange={(e) => { const tax = taxOptions.find((option) => option.code === e.target.value); setLine(i, { tax_code: e.target.value, tax_rate: tax?.treatment === 'standard' ? tax.rate : 0 }); }}><option value="">No tax</option>{taxOptions.map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.treatment === 'standard' ? `${tax.rate}%` : tax.treatment}</option>)}</select> },
        { key: 'total', label: 'Line total', width: 110, render: (l) => {
            const gross = Number(l.ordered_qty || 0) * Number(l.unit_price || 0);
            const discount = gross * Number(l.discount_rate || 0) / 100;
            const tax = (gross - discount) * Number(l.tax_rate || 0) / 100;
            return <span>{money(gross - discount + tax)}</span>;
        } },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Orders', to: '/sales-orders' }, { label: isEdit ? 'Edit draft' : 'New' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit sales order' : 'New sales order'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="Order number" required error={errors.order_number}><input className="input" value={header.order_number} onChange={(e) => setHeader({ ...header, order_number: e.target.value })} /></Field>
                <Field label="Customer" error={errors.customer_id}><CustomerPicker value={header.customer_id} onChange={(v) => setHeader({ ...header, customer_id: v })} /></Field>
                <Field label="Customer name" error={errors.customer_name}><input className="input" value={header.customer_name} onChange={(e) => setHeader({ ...header, customer_name: e.target.value })} placeholder="Optional override" /></Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label="Order date" error={errors.order_date}><input className="input" type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} /></Field>
                <Field label="Requested ship date" error={errors.requested_ship_date}><input className="input" type="date" value={header.requested_ship_date} onChange={(e) => setHeader({ ...header, requested_ship_date: e.target.value })} /></Field>
                <Field label="Notes"><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Lines</h2>
                <DocumentLinesTable columns={columns} lines={lines}
                    onAdd={() => { const code = settings.data?.settings?.default_sales_tax_code ?? ''; const tax = taxOptions.find((row) => row.code === code); setLines([...lines, { ...emptyLine(), tax_code: code, tax_rate: tax?.treatment === 'standard' ? tax.rate : 0 }]); }}
                    onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} readOnly={false} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/sales-orders')}>Cancel</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : 'Save draft'}</button>
            </div>
        </section>
    );
}
