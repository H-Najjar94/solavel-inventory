import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';

const EMPTY = { code: '', name: '', email: '', phone: '', address: '', tax_number: '', currency: '', payment_terms: '', is_active: true, notes: '' };

export default function SupplierFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_items');
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['supplier', id], () => api.supplier(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data) {
            const s = existing.data; const c = s.contact ?? {};
            setForm({ ...EMPTY, code: s.code, name: s.name, is_active: s.is_active, ...c });
        }
    }, [isEdit, existing.data]);

    function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }

    async function submit(e) {
        e.preventDefault();
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const res = isEdit ? await api.updateSupplier(id, form) : await api.createSupplierFull(form);
            toast.push(isEdit ? 'Supplier updated.' : 'Supplier created.', 'success');
            qc.invalidateQueries({ queryKey: ['suppliers'] });
            nav(`/suppliers/${res?.data?.id ?? id}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Suppliers', to: '/suppliers' }, { label: isEdit ? 'Edit' : 'New supplier' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit supplier' : 'New supplier'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}
            <form className="form-grid" onSubmit={submit}>
                <Field label="Code" required error={errors.code}><input className="input" value={form.code} onChange={(e) => set('code', e.target.value)} /></Field>
                <Field label="Name" required error={errors.name}><input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
                <Field label="Email" error={errors.email}><input className="input" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
                <Field label="Phone"><input className="input" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} /></Field>
                <Field label="Address"><input className="input" value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} /></Field>
                <Field label="Tax number"><input className="input" value={form.tax_number ?? ''} onChange={(e) => set('tax_number', e.target.value)} /></Field>
                <Field label="Currency"><input className="input" value={form.currency ?? ''} onChange={(e) => set('currency', e.target.value)} /></Field>
                <Field label="Payment terms"><input className="input" value={form.payment_terms ?? ''} onChange={(e) => set('payment_terms', e.target.value)} /></Field>
                <Field label="Status"><label><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label></Field>
                <Field label="Notes"><textarea className="input" rows="2" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>
                <div className="form-actions">
                    <button type="button" className="btn" onClick={() => nav('/suppliers')}>Cancel</button>
                    <button type="submit" className="btn btn--primary" disabled={!gate.allowed || saving}>{saving ? 'Saving…' : (isEdit ? 'Save changes' : 'Create supplier')}</button>
                </div>
            </form>
        </section>
    );
}
