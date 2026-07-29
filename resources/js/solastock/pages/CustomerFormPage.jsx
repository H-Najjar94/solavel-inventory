import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, EmptyState, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

const EMPTY = { code: '', name: '', email: '', phone: '', address: '', tax_number: '', currency: '', payment_terms: '', is_active: true, notes: '' };

export default function CustomerFormPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_sales_orders');
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const existing = useApiQuery(['customer', id], () => api.customer(id), { fallback: null, enabled: isEdit });
    const gateReason = gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied');

    useEffect(() => {
        if (isEdit && existing.data) {
            const c = existing.data.contact ?? {};
            setForm({ ...EMPTY, code: existing.data.code, name: existing.data.name, is_active: existing.data.is_active, ...c });
        }
    }, [isEdit, existing.data]);

    function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }

    async function submit(e) {
        e.preventDefault();
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const res = isEdit ? await api.updateCustomer(id, form) : await api.createCustomer(form);
            toast.push(t(isEdit ? 'partners.customers.form.updated' : 'partners.customers.form.created'), 'success');
            qc.invalidateQueries({ queryKey: ['customers'] });
            nav(`/customers/${res?.data?.id ?? id}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(t('partners.customers.form.saveFailed'), 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;
    if (isEdit && existing.isError) return <section className="page">
        <Breadcrumbs items={[{ label: t('partners.common.customers'), to: '/customers' }, { label: t('partners.common.edit') }]} />
        <EmptyState title={t('partners.customers.form.loadErrorTitle')} hint={t('partners.customers.form.loadErrorHint')}
            action={<button type="button" className="btn" onClick={() => existing.refetch()}>{t('partners.common.retry')}</button>} />
    </section>;
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.customers'), to: '/customers' }, { label: t(isEdit ? 'partners.customers.form.editBreadcrumb' : 'partners.customers.form.newBreadcrumb') }]} />
            <header className="page-head"><h1>{t(isEdit ? 'partners.customers.form.editTitle' : 'partners.customers.form.newTitle')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gateReason}</div>}
            <form className="form-grid" onSubmit={submit}>
                <Field label={t('partners.common.code')} required error={errors.code}><input className="input" dir="auto" value={form.code} onChange={(e) => set('code', e.target.value)} /></Field>
                <Field label={t('partners.common.name')} required error={errors.name}><input className="input" dir="auto" value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
                <Field label={t('partners.common.email')} error={errors.email}><input className="input" dir="ltr" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
                <Field label={t('partners.common.phone')}><input className="input" dir="auto" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} /></Field>
                <Field label={t('partners.common.address')}><input className="input" dir="auto" value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} /></Field>
                <Field label={t('partners.common.taxNumber')}><input className="input" dir="auto" value={form.tax_number ?? ''} onChange={(e) => set('tax_number', e.target.value)} /></Field>
                <Field label={t('partners.common.currency')}><input className="input" dir="ltr" value={form.currency ?? ''} onChange={(e) => set('currency', e.target.value)} /></Field>
                <Field label={t('partners.common.paymentTerms')}><input className="input" dir="auto" value={form.payment_terms ?? ''} onChange={(e) => set('payment_terms', e.target.value)} /></Field>
                <Field label={t('partners.common.status')}><label><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> {t('partners.common.active')}</label></Field>
                <Field label={t('partners.common.notes')}><textarea className="input" dir="auto" rows="2" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>
                <div className="form-actions">
                    <button type="button" className="btn" onClick={() => nav('/customers')}>{t('partners.common.cancel')}</button>
                    <button type="submit" className="btn btn--primary" disabled={!gate.allowed || saving}>{saving ? t('partners.common.saving') : t(isEdit ? 'partners.common.saveChanges' : 'partners.customers.form.create')}</button>
                </div>
            </form>
        </section>
    );
}
