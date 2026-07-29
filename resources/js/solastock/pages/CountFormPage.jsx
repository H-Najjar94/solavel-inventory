import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, EmptyState, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput } from '../components/pickers.jsx';
import { useI18n } from '../i18n/context.jsx';
import { translateCountPlural, translateCounts } from '../i18n/counts.js';

const emptyLine = () => ({ item_id: null, bin_id: null, system_qty: '0', snapshot_qty: null, counted_qty: '' });

export default function CountFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const { locale } = useI18n();
    const t = (key, fallback, params) => translateCounts(locale, key, fallback, params);
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({
        count_number: '', count_type: 'cycle', blind_count: false, warehouse_id: null, zone_id: null,
        scheduled_for: '', recurrence: 'once', abc_class: '', freeze_snapshot: true, notes: '',
    });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [prefilling, setPrefilling] = useState(false);

    const existing = useApiQuery(['count', id], () => api.count(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.count) {
            const c = existing.data.count;
            if (c.status !== 'draft') { toast.push(t('counts.form.draftOnly'), 'error'); nav(`/counts/${id}`); return; }
            setHeader({
                count_number: c.count_number,
                count_type: c.count_type,
                blind_count: !!c.blind_count,
                warehouse_id: c.warehouse_id,
                zone_id: c.zone_id,
                scheduled_for: c.scheduled_for ?? '',
                recurrence: c.recurrence ?? 'once',
                abc_class: c.abc_class ?? '',
                freeze_snapshot: !!c.snapshot_at,
                notes: c.notes ?? '',
            });
            setLines((c.lines ?? []).map((l) => ({ item_id: l.item_id, bin_id: l.bin_id, system_qty: l.system_qty, snapshot_qty: l.snapshot_qty ?? null, counted_qty: l.counted_qty ?? '' })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const variance = (l) => (l.counted_qty === '' ? null : Number(l.counted_qty) - Number(l.system_qty || 0));

    async function prefill() {
        if (!header.warehouse_id) { toast.push(t('counts.form.selectWarehouse'), 'error'); return; }
        setPrefilling(true);
        try {
            const res = await api.countPrefill(header.warehouse_id);
            const rows = res?.data?.lines ?? [];
            if (rows.length === 0) { toast.push(t('counts.form.noStock'), 'info'); }
            setLines(rows.length ? rows.map((r) => ({ item_id: r.item_id, bin_id: r.bin_id, lot_id: r.lot_id ?? null, lot_code: r.lot_code ?? null, expiry_date: r.expiry_date ?? null, system_qty: r.system_qty, snapshot_qty: r.system_qty, counted_qty: '', expected_serials: r.expected_serials ?? [] })) : [emptyLine()]);
        } catch { toast.push(t('counts.form.prefillFailed'), 'error'); }
        finally { setPrefilling(false); }
    }

    async function save(post = false) {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = { ...header, lines: lines.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, bin_id: l.bin_id, lot_id: l.lot_id || undefined, system_qty: l.system_qty || '0', counted_qty: l.counted_qty === '' ? null : l.counted_qty })) };
            if (payload.lines.length === 0) { toast.push(t('counts.form.lineRequired'), 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateCount(id, payload) : await api.createCount(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postCount(docId); toast.push(t('counts.form.posted'), 'success'); }
            else toast.push(t(isEdit ? 'counts.form.updated' : 'counts.form.saved'), 'success');
            qc.invalidateQueries({ queryKey: ['counts'] });
            nav(`/counts/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(t('counts.form.saveFailed'), 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;
    if (isEdit && existing.isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb'), to: '/counts' }, { label: t('counts.form.editBreadcrumb') }]} />
            <EmptyState title={t('counts.form.loadErrorTitle')} hint={t('counts.form.loadErrorHint')}
                action={<button className="btn" onClick={() => existing.refetch()}>{t('counts.retry')}</button>} />
        </section>
    );

    const columns = [
        { key: 'item', label: t('counts.form.item'), render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'lot', label: t('counts.form.lot'), width: 150, render: (l) => l.lot_code
            ? <span title={l.expiry_date ? t('counts.form.lotExpiry', undefined, { date: l.expiry_date }) : ''}><bdi>{l.lot_code}</bdi>{l.expiry_date ? <> · <bdi>{l.expiry_date}</bdi></> : ''}</span>
            : <span className="muted">—</span> },
        { key: 'bin', label: t('counts.form.bin'), render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'exp', label: t(header.blind_count ? 'counts.form.expected' : 'counts.form.expectedSnapshot'), width: 130, render: (l) => (
            <span>{header.blind_count ? t('counts.form.hidden') : <bdi>{l.snapshot_qty ?? l.system_qty}</bdi>}{!header.blind_count && (l.expected_serials ?? []).length > 0 && <span className="muted" title={(l.expected_serials).map((s) => s.serial).join(', ')}> · {translateCountPlural(locale, 'counts.form.serialCount', (l.expected_serials).length)}</span>}</span>
        ) },
        { key: 'cnt', label: t('counts.form.counted'), width: 110, render: (l, i) => <QuantityInput value={l.counted_qty} onChange={(v) => setLine(i, { counted_qty: v })} /> },
        { key: 'var', label: t('counts.form.variance'), width: 100, render: (l) => { const v = variance(l); return <span className={v < 0 ? 'var-neg' : v > 0 ? 'var-pos' : 'muted'}><bdi>{v === null ? '—' : v}</bdi></span>; } },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb'), to: '/counts' }, { label: t(isEdit ? 'counts.form.editBreadcrumb' : 'counts.form.newBreadcrumb') }]} />
            <header className="page-head"><h1>{t(isEdit ? 'counts.form.editTitle' : 'counts.form.newTitle')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('counts.form.number')} error={errors.count_number}><input className="input" placeholder={t('counts.form.numberPlaceholder')} value={header.count_number} onChange={(e) => setHeader({ ...header, count_number: e.target.value })} /></Field>
                <Field label={t('counts.form.type')} error={errors.count_type}>
                    <select className="input" value={header.count_type} onChange={(e) => setHeader({ ...header, count_type: e.target.value })}>
                        <option value="cycle">{t('counts.type.cycle')}</option><option value="full">{t('counts.type.full')}</option>
                    </select>
                </Field>
                <Field label={t('counts.form.warehouse')} required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label={t('counts.form.scheduledFor')} error={errors.scheduled_for}><input className="input" type="date" value={header.scheduled_for} onChange={(e) => setHeader({ ...header, scheduled_for: e.target.value })} /></Field>
                <Field label={t('counts.form.cadence')} error={errors.recurrence}>
                    <select className="input" value={header.recurrence} onChange={(e) => setHeader({ ...header, recurrence: e.target.value })}>
                        <option value="once">{t('counts.recurrence.once')}</option><option value="weekly">{t('counts.recurrence.weekly')}</option><option value="monthly">{t('counts.recurrence.monthly')}</option><option value="quarterly">{t('counts.recurrence.quarterly')}</option>
                    </select>
                </Field>
                <Field label={t('counts.form.abcClass')} error={errors.abc_class}>
                    <select className="input" value={header.abc_class} onChange={(e) => setHeader({ ...header, abc_class: e.target.value })}>
                        <option value="">{t('counts.form.notClassified')}</option><option value="A">{t('counts.form.abcA')}</option><option value="B">{t('counts.form.abcB')}</option><option value="C">{t('counts.form.abcC')}</option>
                    </select>
                </Field>
                <Field label={t('counts.form.notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <label className="check-row" title={t('counts.form.blindHelp')}><input type="checkbox" checked={header.blind_count} onChange={(e) => setHeader({ ...header, blind_count: e.target.checked })} /> {t('counts.form.blindCount')}</label>
                <label className="check-row" title={t('counts.form.freezeHelp')}><input type="checkbox" checked={header.freeze_snapshot} onChange={(e) => setHeader({ ...header, freeze_snapshot: e.target.checked })} /> {t('counts.form.freezeSnapshot')}</label>
            </div>

            <div className="panel">
                <div className="page-head" style={{ marginBottom: 12 }}>
                    <h2 style={{ margin: 0 }}>{t('counts.form.lines')}</h2>
                    <button type="button" className="btn btn--sm" style={{ marginLeft: 'auto' }} disabled={prefilling || !header.warehouse_id} onClick={prefill}>
                        {prefilling ? t('counts.form.prefilling') : t('counts.form.prefill')}
                    </button>
                </div>
                <DocumentLinesTable columns={columns} lines={lines} addLabel={t('counts.form.addLine')} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <p className="muted">{t('counts.form.varianceHelp')}</p>
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/counts')}>{t('counts.form.cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? t('counts.form.saving') : t('counts.form.saveDraft')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => save(true)}>{t('counts.form.savePost')}</button>
            </div>
        </section>
    );
}
