import React, { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, fieldErrors } from '../components/ui.jsx';
import { ItemPicker } from '../components/pickers.jsx';
import { LotSelector } from '../components/traceability.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function RecallFormPage() {
    const { t } = useI18n();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_recalls');

    const [header, setHeader] = useState({ recall_number: '', item_id: null, scope: 'lot', reason: '', notes: '' });
    const [lines, setLines] = useState([]);
    const [errors, setErrors] = useState({});
    const summary=useRef(null);const pending=useRef(false);
    const [failure,setFailure]=useState('');
    const [unknown,setUnknown]=useState(false);
    useEffect(()=>{if(failure||Object.keys(errors).length){summary.current?.focus();summary.current?.scrollIntoView({block:'center',behavior:'smooth'});}},[errors,failure]);
    const [saving, setSaving] = useState(false);

    // Serial options for the chosen item (when scope = serial).
    const serialOpts = useApiQuery(['serials-for-recall', header.item_id],
        () => api.serials({ item_id: header.item_id, per_page: 200 }), { fallback: [], enabled: !!header.item_id && header.scope === 'serial' });
    const serials = Array.isArray(serialOpts.data) ? serialOpts.data : (serialOpts.data?.data ?? []);

    function addLine() { setLines([...lines, { lot_id: null, serial_id: null, disposition: 'quarantine' }]); }
    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    async function save() {
        if (!gate.allowed || pending.current || unknown) return;
        const validation={};
        if(!header.recall_number.trim())validation.recall_number=t('recallSave.numberRequired');
        if(!header.item_id)validation.item_id=t('recallSave.itemRequired');
        if(!lines.length)validation.lines=t('recalls.validation.lineRequired');
        lines.forEach((line,i)=>{if(!(header.scope==='lot'?line.lot_id:line.serial_id))validation[`lines.${i}.${header.scope==='lot'?'lot_id':'serial_id'}`]=t('recallSave.selectionRequired',undefined,{line:i+1});});
        if(Object.keys(validation).length){setFailure('');setErrors(validation);return;}
        pending.current=true;
        setSaving(true); setErrors({});setFailure('');
        try {
            const payload = {
                ...header,
                lines: lines.map((l) => ({ item_id: header.item_id, lot_id: l.lot_id, serial_id: l.serial_id, disposition: l.disposition })),
            };
            const res = await api.createRecall(payload);
            if(!res?.data?.id)throw Object.assign(new Error('Unconfirmed save'),{outcomeUnknown:true});
            toast.push(t('recalls.messages.created', 'Recall case created.'), 'success');
            qc.invalidateQueries({ queryKey: ['recalls'] });
            nav(`/recalls/${res?.data?.id}`);
        } catch (err) { setErrors(fieldErrors(err));setFailure(err.status===422?err.message:t(err.outcomeUnknown?'recallSave.unknown':'recalls.messages.saveFailed'));setUnknown(!!err.outcomeUnknown);if(![422,402,403].includes(err.status)&&!err.outcomeUnknown)toast.failure(err,err.message); }
        finally { pending.current=false;setSaving(false); }
    }

    return (
        <section className="page" aria-busy={saving}>
            <Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls'), to: '/recalls' }, { label: t('recalls.common.new', 'New') }]} />
            <header className="page-head"><h1>{t('recalls.form.title', 'New recall case')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('recalls.form.number', 'Recall number')} required error={errors.recall_number}><input className="input" value={header.recall_number} onChange={(e) => setHeader({ ...header, recall_number: e.target.value })} /></Field>
                <Field label={t('recalls.common.item', 'Item')} required error={errors.item_id}><ItemPicker value={header.item_id} onChange={(v) => { setHeader({ ...header, item_id: v }); setLines([]); }} /></Field>
                <Field label={t('recalls.common.scope', 'Scope')}><select className="input" value={header.scope} onChange={(e) => { setHeader({ ...header, scope: e.target.value }); setLines([]); }}>
                    <option value="lot">{t('recalls.scope.lot', 'Lot')}</option><option value="serial">{t('recalls.scope.serial', 'Serial number')}</option>
                </select></Field>
                <Field label={t('recalls.common.reason', 'Reason')}><input className="input" value={header.reason} onChange={(e) => setHeader({ ...header, reason: e.target.value })} /></Field>
                <Field label={t('recalls.common.notes', 'Notes')}><textarea className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t(header.scope === 'lot' ? 'recalls.form.affectedLots' : 'recalls.form.affectedSerials', header.scope === 'lot' ? 'Affected lots' : 'Affected serial numbers')}</h2>
                {!header.item_id && <p className="muted">{t('recalls.form.chooseItem', 'Choose an item first.')}</p>}
                {header.item_id && (
                    <table className="data-table">
                        <thead><tr><th>{t(header.scope === 'lot' ? 'recalls.common.lot' : 'recalls.common.serial', header.scope === 'lot' ? 'Lot' : 'Serial number')}</th><th>{t('recalls.form.disposition', 'Disposition')}</th><th /></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={i}>
                                    <td>{header.scope === 'lot'
                                        ? <LotSelector itemId={header.item_id} value={l.lot_id} onChange={(v) => setLine(i, { lot_id: v })} />
                                        : <select className="input" value={l.serial_id ?? ''} onChange={(e) => setLine(i, { serial_id: e.target.value ? Number(e.target.value) : null })}>
                                            <option value="">{t('recalls.form.serialPlaceholder', 'Select a serial number…')}</option>
                                            {serials.map((s) => <option key={s.id} value={s.id}>{s.serial} · {t(`recalls.status.${s.status}`, s.status)}</option>)}
                                        </select>}</td>
                                    <td><select className="input" value={l.disposition} onChange={(e) => setLine(i, { disposition: e.target.value })}>
                                        {['quarantine', 'return', 'destroy', 'none'].map((d) => <option key={d} value={d}>{t(`recalls.disposition.${d}`, d)}</option>)}
                                    </select></td>
                                    <td><button type="button" className="btn btn--sm btn--danger" aria-label={t('recalls.common.removeLine', 'Remove affected line')} title={t('recalls.common.removeLine', 'Remove affected line')} onClick={() => setLines(lines.filter((_, idx) => idx !== i))}>×</button></td>
                                </tr>
                            ))}
                            {lines.length === 0 && <tr><td colSpan={3} className="muted">{t('recalls.form.noLines', 'No affected lines yet.')}</td></tr>}
                        </tbody>
                    </table>
                )}
                {header.item_id && <button type="button" className="btn btn--sm" onClick={addLine}>+ {t('recalls.form.addLine', 'Add line')}</button>}
            </div>

            {(failure||Object.keys(errors).length>0)&&<div ref={summary} tabIndex={-1} className="banner banner--warn recall-save-feedback" role="alert" data-feedback-validation><strong>{t(unknown?'recallSave.unconfirmed':'recallSave.notSaved')}</strong>{failure&&<p>{failure}</p>}{Object.keys(errors).length>0&&<ul>{Object.values(errors).map((message,i)=><li key={i}>{message}</li>)}</ul>}{unknown&&<button type="button" className="btn" onClick={()=>nav('/recalls')}>{t('recallSave.review')}</button>}</div>}
            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/recalls')}>{t('recalls.common.cancel', 'Cancel')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving || unknown} onClick={save}>{saving ? t('recalls.common.saving', 'Saving…') : t('recalls.common.saveDraft', 'Save draft')}</button>
            </div>
        </section>
    );
}
