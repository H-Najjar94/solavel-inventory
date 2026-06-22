import React, { useState } from 'react';
import { Link } from 'react-router-dom';

// ── Breadcrumbs ──
export function Breadcrumbs({ items }) {
    return (
        <nav className="breadcrumbs">
            {items.map((it, i) => (
                <span key={i}>
                    {it.to ? <Link to={it.to}>{it.label}</Link> : <span className="breadcrumbs-current">{it.label}</span>}
                    {i < items.length - 1 && <span className="breadcrumbs-sep">/</span>}
                </span>
            ))}
        </nav>
    );
}

// ── Loading skeleton ──
export function Skeleton({ rows = 5 }) {
    return (
        <div className="skeleton">
            {Array.from({ length: rows }).map((_, i) => <div key={i} className="skeleton-row" />)}
        </div>
    );
}

// ── Empty state ──
export function EmptyState({ title = 'Nothing here yet', hint, action }) {
    return (
        <div className="empty-state">
            <div className="empty-state-title">{title}</div>
            {hint && <div className="empty-state-hint">{hint}</div>}
            {action}
        </div>
    );
}

// ── Tabs ──
export function Tabs({ tabs, active, onChange }) {
    return (
        <div className="tabs">
            {tabs.map((t) => (
                <button key={t.key} className={`tab ${active === t.key ? 'tab--active' : ''}`} onClick={() => onChange(t.key)}>
                    {t.label}
                </button>
            ))}
        </div>
    );
}

// ── Status badge ──
export function StatusBadge({ active, labels = ['Active', 'Inactive'] }) {
    return <span className={`badge ${active ? 'badge--live' : 'badge--muted'}`}>{active ? labels[0] : labels[1]}</span>;
}

// ── Field wrapper with error ──
export function Field({ label, error, children, required }) {
    return (
        <label className="field">
            <span className="field-label">{label}{required && <span className="field-req"> *</span>}</span>
            {children}
            {error && <span className="field-error">{error}</span>}
        </label>
    );
}

// ── Confirm modal ──
export function ConfirmModal({ open, title, message, confirmLabel = 'Confirm', onConfirm, onCancel, danger }) {
    if (!open) return null;
    return (
        <div className="modal-overlay" onClick={onCancel}>
            <div className="modal" onClick={(e) => e.stopPropagation()}>
                <h3>{title}</h3>
                <p>{message}</p>
                <div className="modal-actions">
                    <button className="btn" onClick={onCancel}>Cancel</button>
                    <button className={`btn ${danger ? 'btn--danger' : 'btn--primary'}`} onClick={onConfirm}>{confirmLabel}</button>
                </div>
            </div>
        </div>
    );
}

// ── Quick-create select: a dropdown with an inline "+ create" option ──
export function QuickCreateSelect({ label, value, onChange, options, onCreate, placeholder = '— none —', createLabel = 'Create…', error }) {
    const [creating, setCreating] = useState(false);
    const [text, setText] = useState('');

    async function submit() {
        const name = text.trim();
        if (!name) return;
        const created = await onCreate(name);
        if (created?.id) onChange(created.id);
        setText(''); setCreating(false);
    }

    return (
        <Field label={label} error={error}>
            {creating ? (
                <div className="quick-create">
                    <input className="input" autoFocus value={text} placeholder={`New ${label.toLowerCase()}…`}
                        onChange={(e) => setText(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()} />
                    <button type="button" className="btn btn--sm btn--primary" onClick={submit}>Add</button>
                    <button type="button" className="btn btn--sm" onClick={() => setCreating(false)}>Cancel</button>
                </div>
            ) : (
                <div className="quick-create">
                    <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
                        <option value="">{placeholder}</option>
                        {(options ?? []).map((o) => <option key={o.id} value={o.id}>{o.name ?? o.code}</option>)}
                    </select>
                    {onCreate && <button type="button" className="btn btn--sm" onClick={() => setCreating(true)}>+ {createLabel}</button>}
                </div>
            )}
        </Field>
    );
}

// ── Drawer: right-side slide-over for read-only detail (valuation, movement) ──
export function Drawer({ open, title, subtitle, onClose, children, width = 460 }) {
    if (!open) return null;
    return (
        <div className="drawer-overlay" onClick={onClose}>
            <aside className="drawer" style={{ width }} onClick={(e) => e.stopPropagation()} role="dialog" aria-label={title}>
                <header className="drawer-head">
                    <div>
                        <h3>{title}</h3>
                        {subtitle && <div className="drawer-sub">{subtitle}</div>}
                    </div>
                    <button className="drawer-close" onClick={onClose} aria-label="Close">×</button>
                </header>
                <div className="drawer-body">{children}</div>
            </aside>
        </div>
    );
}

// ── Metric card: a labelled value with optional sublabel/tone. Reuses .card ──
export function MetricCard({ label, value, sub, tone }) {
    return (
        <div className={`card metric-card ${tone ? `metric-card--${tone}` : ''}`}>
            <div className="metric-card-label">{label}</div>
            <div className="metric-card-val">{value}</div>
            {sub && <div className="metric-card-sub">{sub}</div>}
        </div>
    );
}

// ── Generic tone badge (ok / warn / danger / info / muted) ──
export function Badge({ tone = 'muted', children }) {
    const cls = { ok: 'badge--live', warn: 'badge--warn', danger: 'badge--danger', info: 'badge--info', muted: 'badge--muted' }[tone] ?? 'badge--muted';
    return <span className={`badge ${cls}`}>{children}</span>;
}

// Parse a Laravel 422 error payload into { field: message }.
export function fieldErrors(err) {
    const out = {};
    const errs = err?.payload?.errors;
    if (errs) Object.entries(errs).forEach(([k, v]) => { out[k] = Array.isArray(v) ? v[0] : v; });
    return out;
}
