import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, fieldErrors } from '../components/ui.jsx';
import { ItemPicker } from '../components/pickers.jsx';
import { LotSelector } from '../components/traceability.jsx';

export default function RecallFormPage() {
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_recalls');

    const [header, setHeader] = useState({ recall_number: '', item_id: null, scope: 'lot', reason: '', notes: '' });
    const [lines, setLines] = useState([]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    // Serial options for the chosen item (when scope = serial).
    const serialOpts = useApiQuery(['serials-for-recall', header.item_id],
        () => api.serials({ item_id: header.item_id, per_page: 200 }), { fallback: [], enabled: !!header.item_id && header.scope === 'serial' });
    const serials = Array.isArray(serialOpts.data) ? serialOpts.data : (serialOpts.data?.data ?? []);

    function addLine() { setLines([...lines, { lot_id: null, serial_id: null, disposition: 'quarantine' }]); }
    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines
                    .filter((l) => (header.scope === 'lot' ? l.lot_id : l.serial_id))
                    .map((l) => ({ item_id: header.item_id, lot_id: l.lot_id, serial_id: l.serial_id, disposition: l.disposition })),
            };
            if (payload.lines.length === 0) { toast.push('Add at least one affected lot/serial.', 'error'); setSaving(false); return; }
            const res = await api.createRecall(payload);
            toast.push('Recall case created.', 'success');
            qc.invalidateQueries({ queryKey: ['recalls'] });
            nav(`/recalls/${res?.data?.id}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Recalls', to: '/recalls' }, { label: 'New' }]} />
            <header className="page-head"><h1>New recall case</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="Recall number" required error={errors.recall_number}><input className="input" value={header.recall_number} onChange={(e) => setHeader({ ...header, recall_number: e.target.value })} /></Field>
                <Field label="Item" required error={errors.item_id}><ItemPicker value={header.item_id} onChange={(v) => { setHeader({ ...header, item_id: v }); setLines([]); }} /></Field>
                <Field label="Scope"><select className="input" value={header.scope} onChange={(e) => { setHeader({ ...header, scope: e.target.value }); setLines([]); }}>
                    <option value="lot">Lot</option><option value="serial">Serial</option>
                </select></Field>
                <Field label="Reason"><input className="input" value={header.reason} onChange={(e) => setHeader({ ...header, reason: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Affected {header.scope === 'lot' ? 'lots' : 'serials'}</h2>
                {!header.item_id && <p className="muted">Choose an item first.</p>}
                {header.item_id && (
                    <table className="data-table">
                        <thead><tr><th>{header.scope === 'lot' ? 'Lot' : 'Serial'}</th><th>Disposition</th><th /></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={i}>
                                    <td>{header.scope === 'lot'
                                        ? <LotSelector itemId={header.item_id} value={l.lot_id} onChange={(v) => setLine(i, { lot_id: v })} />
                                        : <select className="input" value={l.serial_id ?? ''} onChange={(e) => setLine(i, { serial_id: e.target.value ? Number(e.target.value) : null })}>
                                            <option value="">Serial…</option>
                                            {serials.map((s) => <option key={s.id} value={s.id}>{s.serial} · {s.status}</option>)}
                                        </select>}</td>
                                    <td><select className="input" value={l.disposition} onChange={(e) => setLine(i, { disposition: e.target.value })}>
                                        {['quarantine', 'return', 'destroy', 'none'].map((d) => <option key={d} value={d}>{d}</option>)}
                                    </select></td>
                                    <td><button type="button" className="btn btn--sm btn--danger" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}>×</button></td>
                                </tr>
                            ))}
                            {lines.length === 0 && <tr><td colSpan={3} className="muted">No lines yet.</td></tr>}
                        </tbody>
                    </table>
                )}
                {header.item_id && <button type="button" className="btn btn--sm" onClick={addLine}>+ Add line</button>}
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/recalls')}>Cancel</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : 'Save draft'}</button>
            </div>
        </section>
    );
}
