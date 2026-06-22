import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput } from '../components/pickers.jsx';

const emptyLine = () => ({ item_id: null, bin_id: null, system_qty: '0', counted_qty: '' });

export default function CountFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ count_number: '', count_type: 'cycle', warehouse_id: null, zone_id: null, notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [prefilling, setPrefilling] = useState(false);

    const existing = useApiQuery(['count', id], () => api.count(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.count) {
            const c = existing.data.count;
            if (c.status !== 'draft') { toast.push('Only draft counts can be edited.', 'error'); nav(`/counts/${id}`); return; }
            setHeader({ count_number: c.count_number, count_type: c.count_type, warehouse_id: c.warehouse_id, zone_id: c.zone_id, notes: c.notes ?? '' });
            setLines((c.lines ?? []).map((l) => ({ item_id: l.item_id, bin_id: l.bin_id, system_qty: l.system_qty, counted_qty: l.counted_qty ?? '' })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const variance = (l) => (l.counted_qty === '' ? null : Number(l.counted_qty) - Number(l.system_qty || 0));

    async function prefill() {
        if (!header.warehouse_id) { toast.push('Select a warehouse to prefill expected quantities.', 'error'); return; }
        setPrefilling(true);
        try {
            const res = await api.countPrefill(header.warehouse_id);
            const rows = res?.data?.lines ?? [];
            if (rows.length === 0) { toast.push('No stock found for this scope.', 'info'); }
            setLines(rows.length ? rows.map((r) => ({ item_id: r.item_id, bin_id: r.bin_id, lot_id: r.lot_id ?? null, lot_code: r.lot_code ?? null, expiry_date: r.expiry_date ?? null, system_qty: r.system_qty, counted_qty: '', expected_serials: r.expected_serials ?? [] })) : [emptyLine()]);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setPrefilling(false); }
    }

    async function save(post = false) {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = { ...header, lines: lines.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, bin_id: l.bin_id, lot_id: l.lot_id || undefined, system_qty: l.system_qty || '0', counted_qty: l.counted_qty === '' ? null : l.counted_qty })) };
            if (payload.lines.length === 0) { toast.push('Add at least one line.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateCount(id, payload) : await api.createCount(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postCount(docId); toast.push('Count posted — variance adjustment created.', 'success'); }
            else toast.push(isEdit ? 'Draft updated.' : 'Draft saved.', 'success');
            qc.invalidateQueries({ queryKey: ['counts'] });
            nav(`/counts/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'lot', label: 'Lot', width: 150, render: (l) => l.lot_code
            ? <span title={l.expiry_date ? `exp ${l.expiry_date}` : ''}>{l.lot_code}{l.expiry_date ? ` · ${l.expiry_date}` : ''}</span>
            : <span className="muted">—</span> },
        { key: 'bin', label: 'Bin', render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'exp', label: 'Expected', width: 100, render: (l) => (
            <span>{l.system_qty}{(l.expected_serials ?? []).length > 0 && <span className="muted" title={(l.expected_serials).map((s) => s.serial).join(', ')}> · {(l.expected_serials).length} serial(s)</span>}</span>
        ) },
        { key: 'cnt', label: 'Counted', width: 110, render: (l, i) => <QuantityInput value={l.counted_qty} onChange={(v) => setLine(i, { counted_qty: v })} /> },
        { key: 'var', label: 'Variance', width: 100, render: (l) => { const v = variance(l); return <span className={v < 0 ? 'var-neg' : v > 0 ? 'var-pos' : 'muted'}>{v === null ? '—' : v}</span>; } },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Stock Counts', to: '/counts' }, { label: isEdit ? 'Edit draft' : 'New' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit count' : 'New count'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="Count number" required error={errors.count_number}><input className="input" value={header.count_number} onChange={(e) => setHeader({ ...header, count_number: e.target.value })} /></Field>
                <Field label="Type" error={errors.count_type}>
                    <select className="input" value={header.count_type} onChange={(e) => setHeader({ ...header, count_type: e.target.value })}>
                        <option value="cycle">Cycle count</option><option value="full">Full stocktake</option>
                    </select>
                </Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label="Notes"><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <div className="page-head" style={{ marginBottom: 12 }}>
                    <h2 style={{ margin: 0 }}>Lines</h2>
                    <button type="button" className="btn btn--sm" style={{ marginLeft: 'auto' }} disabled={prefilling || !header.warehouse_id} onClick={prefill}>
                        {prefilling ? 'Loading…' : 'Prefill expected from stock'}
                    </button>
                </div>
                <DocumentLinesTable columns={columns} lines={lines} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <p className="muted">Zero-variance lines create no stock movement. Posting generates a single adjustment for the non-zero variances.</p>
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/counts')}>Cancel</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? 'Saving…' : 'Save draft'}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => save(true)}>Save & post variance</button>
            </div>
        </section>
    );
}
