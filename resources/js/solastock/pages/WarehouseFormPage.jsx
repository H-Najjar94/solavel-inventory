import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, StatusBadge, fieldErrors } from '../components/ui.jsx';
import { t } from '../i18n/index.js';

const EMPTY = {
    code: '', name: '', type: 'warehouse', is_active: true,
    address: { line1: '', city: '', country: '' }, max_capacity_units: '',
};

function Section({ title, sub, children }) {
    return (
        <div className="card form-section">
            <div className="card-head"><h3>{title}</h3>{sub && <span className="sub">{sub}</span>}</div>
            <div className="card-body">{children}</div>
        </div>
    );
}

export default function WarehouseFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate();
    const toast = useToast();
    const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_warehouses');

    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['warehouse', id], () => api.warehouse(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.warehouse) {
            const w = existing.data.warehouse;
            setForm({ ...EMPTY, ...w, address: w.address ?? EMPTY.address });
        }
    }, [isEdit, existing.data]);

    function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }
    function setAddr(k, v) { setForm((f) => ({ ...f, address: { ...f.address, [k]: v } })); }

    const typeLabel = { warehouse: t('warehouse'), retail: t('warehouses.retail'), transit: t('warehouses.transit'), virtual: t('warehouses.virtual') }[form.type] ?? form.type;
    const checks = { basic: !!form.code && !!form.name, type: !!form.type, ready: !!form.code && !!form.name };

    async function submit(e) {
        e.preventDefault();
        if (!gate.allowed || saving) return;
        setSaving(true); setErrors({});
        try {
            const res = isEdit ? await api.updateWarehouse(id, form) : await api.createWarehouse(form);
            const newId = res?.data?.id ?? id;
            toast.push(t(isEdit ? 'warehouses.updated' : 'warehouses.created'), 'success');
            qc.invalidateQueries({ queryKey: ['warehouses'] });
            qc.invalidateQueries({ queryKey: ['warehouse', String(newId)] });
            nav(`/warehouses/${newId}`);
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.push(err.message || t('warehouses.saveFailed'), 'error');
        } finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;
    const errorList = Object.entries(errors);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('warehouses'), to: '/warehouses' }, { label: t(isEdit ? 'warehouses.edit' : 'warehouses.new') }]} />
            <header className="page-head">
                <h1>{t(isEdit ? 'warehouses.edit' : 'warehouses.new')}</h1>
                <StatusBadge active={form.is_active} />
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button type="button" className="btn" onClick={() => nav('/warehouses')} disabled={saving}>{t('warehouses.cancel')}</button>
                    <button type="submit" form="wh-form" className="btn btn--primary" disabled={!gate.allowed || saving}>
                        {saving ? t('warehouses.saving') : t(isEdit ? 'warehouses.saveChanges' : 'warehouses.create')}
                    </button>
                </div>
            </header>

            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            {errorList.length > 0 && (
                <div className="form-error-summary">
                    <strong>{t('warehouses.fixErrors')}</strong>
                    <ul>{errorList.map(([f, m]) => <li key={f}>{m}</li>)}</ul>
                </div>
            )}

            <form id="wh-form" className="item-form-layout" onSubmit={submit}>
                <div>
                    <Section title={t('warehouses.basicInfo')} sub={t('warehouses.basicInfoHint')}>
                        <div className="fg2">
                            <Field label={t('name')} required error={errors.name}>
                                <input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder={t('warehouses.namePlaceholder')} />
                            </Field>
                            <Field label={t('warehouses.code')} required error={errors.code}>
                                <input className="input" value={form.code} onChange={(e) => set('code', e.target.value)} placeholder={t('warehouses.codePlaceholder')} />
                            </Field>
                            <Field label={t('warehouses.type')} error={errors.type}>
                                <select className="input" value={form.type} onChange={(e) => set('type', e.target.value)}>
                                    <option value="warehouse">{t('warehouse')}</option><option value="retail">{t('warehouses.retail')}</option>
                                    <option value="transit">{t('warehouses.transit')}</option><option value="virtual">{t('warehouses.virtual')}</option>
                                </select>
                            </Field>
                            <Field label={t('warehouses.status')}>
                                <label className="check-inline"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> {t('warehouses.active')}</label>
                            </Field>
                            <div className="span2"><Field label={t('warehouses.addressLine')} error={errors.address}><input className="input" value={form.address?.line1 ?? ''} onChange={(e) => setAddr('line1', e.target.value)} placeholder={t('warehouses.streetBuilding')} /></Field></div>
                            <Field label={t('warehouses.city')}><input className="input" value={form.address?.city ?? ''} onChange={(e) => setAddr('city', e.target.value)} /></Field>
                            <Field label={t('warehouses.country')}><input className="input" value={form.address?.country ?? ''} onChange={(e) => setAddr('country', e.target.value)} /></Field>
                        </div>
                    </Section>

                    <Section title={t('warehouses.operations')} sub={t('warehouses.operationsHint')}>
                        <div className="fg2">
                            <Field label={t('warehouses.capacityUnits')} error={errors.max_capacity_units}>
                                <input className="input" type="number" step="0.0001" value={form.max_capacity_units ?? ''} onChange={(e) => set('max_capacity_units', e.target.value)} placeholder={t('warehouses.optional')} />
                            </Field>
                            <div />
                            <div className="span2"><span className="field-hint">{t('warehouses.zonesBinsHint')}</span></div>
                        </div>
                    </Section>
                </div>

                <aside className="form-side">
                    <div className="card"><div className="card-body">
                        <div className="img-dropzone">
                            <div className="img-dropzone-ico">🏬</div>
                            <div className="img-dropzone-title">{t('warehouses.banner')}</div>
                            <div className="img-dropzone-hint">
                                {isEdit ? t('warehouses.manageBanner') : t('warehouses.saveThenBanner')}
                            </div>
                            {isEdit && <button type="button" className="btn btn--sm" onClick={() => nav(`/warehouses/${id}`)}>{t('warehouses.openMedia')}</button>}
                        </div>
                    </div></div>

                    <div className="card"><div className="card-head"><h3>{t('warehouses.setupChecklist')}</h3></div><div className="card-body">
                        <ul className="checklist">
                            <li className={checks.basic ? 'done' : ''}><span className="check-dot">{checks.basic ? '✓' : ''}</span> {t('warehouses.nameCode')}</li>
                            <li className={checks.type ? 'done' : ''}><span className="check-dot">{checks.type ? '✓' : ''}</span> {t('warehouses.typeSelected')}</li>
                            <li className={checks.ready ? 'done' : ''}><span className="check-dot">{checks.ready ? '✓' : ''}</span> {t('warehouses.readyToSave')}</li>
                        </ul>
                    </div></div>

                    <div className="card preview-card"><div className="card-head"><h3>{t('warehouses.preview')}</h3></div><div className="card-body">
                        <div className="pc-name">{form.name || t('warehouses.untitled')}</div>
                        <div className="pc-sku">{form.code || t('warehouses.noCode')}</div>
                        <div className="pc-grid">
                            <div><div className="pc-label">{t('warehouses.type')}</div><div className="pc-val">{typeLabel}</div></div>
                            <div><div className="pc-label">{t('warehouses.status')}</div><div className="pc-val">{form.is_active ? t('warehouses.active') : t('warehouses.inactive')}</div></div>
                            <div><div className="pc-label">{t('warehouses.city')}</div><div className="pc-val">{form.address?.city || '—'}</div></div>
                            <div><div className="pc-label">{t('warehouses.capacity')}</div><div className="pc-val">{form.max_capacity_units || '—'}</div></div>
                        </div>
                    </div></div>
                </aside>
            </form>
        </section>
    );
}
