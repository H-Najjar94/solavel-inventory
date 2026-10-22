import React, { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCan, useMeta } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { routeAccess } from '../services/uiAccess.mjs';
import { AccessNotice } from './PlanAccess.jsx';
import { Badge, EmptyState } from './ui.jsx';
import { t, getLocale } from '../i18n/index.js';
import './SupplierTerms.css';

// ── Decimal display (values arrive as decimal strings; never round what is sent back) ──
export function decimalText(value, min = 0, max = 6) {
    if (value === null || value === undefined || value === '') return '—';
    const raw = String(value).trim();
    if (!/^-?\d+(?:\.\d+)?$/.test(raw)) return raw;
    try {
        // Strings keep full precision in browsers that support exact decimal formatting.
        return new Intl.NumberFormat(getLocale(), { minimumFractionDigits: min, maximumFractionDigits: Math.max(min, max) }).format(raw);
    } catch {
        return raw;
    }
}
/** Supplier price: the relationship's price scale when set, otherwise up to 6 decimals (at least 2). */
export const priceText = (value, scale) => (scale ? decimalText(value, Number(scale), Number(scale)) : decimalText(value, 2, 6));
export const qtyText = (value) => decimalText(value, 0, 6);
export const costText = (value) => decimalText(value, 2, 6);
export const moneyText = (value) => decimalText(value, 2, 2);
/** Drop trailing zeros only ("12.500000" → "12.5"); used when a server value is applied to an input. */
export function trimDecimal(value) {
    const raw = String(value ?? '').trim();
    if (!/^\d+\.\d+$/.test(raw)) return raw;
    return raw.replace(/0+$/, '').replace(/\.$/, '');
}

/** LTR-isolated number or code inside RTL text. */
export function Num({ children, className }) {
    return <bdi dir="ltr" className={className}>{children}</bdi>;
}

export function PriceWithCurrency({ value, currency, scale }) {
    if (value === null || value === undefined || value === '') return <span className="muted">—</span>;
    return <Num>{priceText(value, scale)}{currency ? ` ${currency}` : ''}</Num>;
}

/** The tier that applies today at the smallest quantity (the "from" price). */
export function currentTier(relationship) {
    const tiers = (relationship?.tiers ?? []).filter((tier) => tier.is_current);
    return tiers.sort((a, b) => Number(a.minimum_qty) - Number(b.minimum_qty))[0] ?? null;
}

export function unitLabel(unit) {
    return unit ? (unit.symbol || unit.code || unit.name) : '';
}

// ── Access: role permission + plan route rules from /meta (the server still enforces both) ──
export function useSupplierTermsAccess() {
    const can = useCan();
    const meta = useMeta();
    const tenant = useTenant();
    const route = (path, method) => routeAccess(path, meta, method);
    const viewRoute = route('/items/0/suppliers');
    const manageRoute = route('/items/0/suppliers', 'POST');
    const historyRoute = route('/items/0/purchase-history');
    const importRoute = route('/supplier-terms/import', 'POST');
    const exportRoute = route('/supplier-terms/export');
    const suggestRoute = route('/supplier-terms/suggest');
    return {
        ready: !meta.isPlaceholderData,
        viewTerms: can('inventory.view_items') && viewRoute.allowed,
        viewTermsReason: viewRoute.allowed ? null : viewRoute.reason,
        manageTerms: tenant.hasTenant && can('inventory.manage_items') && manageRoute.allowed,
        history: can('inventory.view_reports'),
        historyAllowed: can('inventory.view_reports') && historyRoute.allowed,
        historyReason: historyRoute.allowed ? null : historyRoute.reason,
        importTerms: tenant.hasTenant && can('inventory.manage_items') && importRoute.allowed,
        exportTerms: tenant.hasTenant && can('inventory.view_items') && can('inventory.export_reports') && exportRoute.allowed,
        purchasing: tenant.hasTenant && can('inventory.manage_purchase_orders') && suggestRoute.allowed,
    };
}

export const errorReason = (error) => error?.payload?.reason_code ?? error?.payload?.code ?? error?.code ?? null;

/** Load failure: plan/role denials become the standard access notice; anything else offers a retry. */
export function QueryProblem({ error, onRetry, compact = false }) {
    if ([402, 403].includes(error?.status)) {
        const reason = errorReason(error);
        if (['feature_not_in_plan', 'permission_denied', 'entitlement_service_unavailable', 'entitlement_verification_stale'].includes(reason) || error?.status === 402) {
            return <AccessNotice reason={reason ?? 'subscription_not_access_eligible'} />;
        }
        return <EmptyState title={t('supplierTerms.access.deniedTitle')} hint={t('supplierTerms.access.deniedHint')} />;
    }
    if (error?.status === 404) return <EmptyState title={t('supplierTerms.access.unavailableTitle')} hint={t('supplierTerms.access.unavailableHint')} />;
    const retry = onRetry ? <button type="button" className="btn btn--sm" onClick={() => onRetry()}>{t('supplierTerms.common.retry')}</button> : null;
    if (compact) return <div className="drawer-error">{t('supplierTerms.common.loadFailed')}{retry && <div>{retry}</div>}</div>;
    return <EmptyState title={t('supplierTerms.common.loadFailed')} hint={error?.message} action={retry} />;
}

const STATUS_TONES = { approved: 'ok', blocked: 'danger', historical: 'muted' };
export const TERM_STATUSES = ['approved', 'blocked', 'historical'];

export function TermsStatusBadge({ status }) {
    return <Badge tone={STATUS_TONES[status] ?? 'muted'}>{t(`supplierTerms.status.${status}`, status)}</Badge>;
}

export function PreferredBadge() {
    return <Badge tone="info">{t('supplierTerms.common.preferred')}</Badge>;
}

const FINANCE_TONES = { connected: 'is-ok', paused: 'is-warn', not_entitled: 'is-muted', needs_review: 'is-warn' };
/** Calm status chip for the SolaCount link; nothing for a standalone organization. */
export function FinanceStateChip({ state }) {
    if (!state || !FINANCE_TONES[state]) return null;
    return <span className={`st-chip ${FINANCE_TONES[state]}`} title={t(`supplierTerms.finance.${state}Hint`)}>
        <span className="st-chip__dot" aria-hidden="true" />{t(`supplierTerms.finance.${state}`)}
    </span>;
}

/** Help note describing how a vendor price is chosen (server-localized rule text). */
export function RuleNote({ text }) {
    if (!text) return null;
    return <p className="st-rule"><span className="st-rule__label">{t('supplierTerms.common.howPriced')}</span> {text}</p>;
}

/** Minimal modal frame matching the existing .modal-overlay/.modal styles. */
export function TermsModal({ title, subtitle, onClose, children, actions, wide = false, busy = false }) {
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && !busy) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, busy]);
    return (
        <div className="modal-overlay" onClick={() => { if (!busy) onClose(); }}>
            <div className={`modal st-modal ${wide ? 'st-modal--wide' : ''}`} role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
                <div className="st-modal__head">
                    <div><h3>{title}</h3>{subtitle && <p className="muted">{subtitle}</p>}</div>
                    <button type="button" className="btn btn--sm btn--icon btn--ghost" onClick={onClose} disabled={busy} aria-label={t('supplierTerms.common.close')}>×</button>
                </div>
                <div className="st-modal__body">{children}</div>
                {actions && <div className="modal-actions">{actions}</div>}
            </div>
        </div>
    );
}

/** Previous / next pager for paginated endpoints ({ meta: { current_page, last_page, total } }). */
export function Pager({ meta, onPage }) {
    if (!meta || (meta.last_page ?? 1) <= 1) return null;
    const page = meta.current_page ?? 1;
    return (
        <div className="st-pager">
            <button type="button" className="btn btn--sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t('supplierTerms.common.previous')}</button>
            <span className="muted">{t('supplierTerms.common.pageOf', undefined, { page, pages: meta.last_page, total: meta.total })}</span>
            <button type="button" className="btn btn--sm" disabled={page >= meta.last_page} onClick={() => onPage(page + 1)}>{t('supplierTerms.common.next')}</button>
        </div>
    );
}

/** Preferred supplier shown by name (from the item's supplier terms, else the supplier record). */
export function PreferredSupplierName({ itemId, supplierId }) {
    const access = useSupplierTermsAccess();
    const terms = useApiQuery(['item-suppliers', String(itemId)], () => api.itemSuppliers(itemId), { fallback: null, enabled: Boolean(supplierId) && access.viewTerms });
    const fromTerms = (terms.data?.suppliers ?? []).find((r) => Number(r.supplier_id) === Number(supplierId))?.supplier;
    const needsRecord = Boolean(supplierId) && !fromTerms && (terms.isError || terms.isSuccess || !access.viewTerms);
    const record = useApiQuery(['supplier', String(supplierId)], () => api.supplier(supplierId), { fallback: null, enabled: needsRecord });
    if (!supplierId) return <span>{t('supplierTerms.detail.noPreferred')}</span>;
    const name = fromTerms?.name ?? record.data?.name;
    if (!name) return <span className="muted">{terms.isLoading || record.isLoading ? '…' : t('supplierTerms.detail.preferredUnavailable')}</span>;
    return <Link to={`/suppliers/${supplierId}`}>{name}</Link>;
}
