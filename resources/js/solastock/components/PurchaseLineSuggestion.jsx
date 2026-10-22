import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { Num, priceText, qtyText, trimDecimal, unitLabel } from './SupplierTermsShared.jsx';
import { t } from '../i18n/index.js';

function useDebounced(value, delay = 400) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

const sameNumber = (a, b) => a !== '' && b !== '' && a !== null && b !== null && Number(a) === Number(b);

/**
 * Vendor terms for one purchase-order line. Shows the applicable supplier price and
 * quantity rounding as chips with an explicit Apply; it never changes the line by itself.
 */
export function PurchaseLineSuggestion({ line, supplierId, currencyCode, date, onApplyPrice, onApplyQuantity }) {
    const qty = Number(line.ordered_qty) > 0 ? String(line.ordered_qty).trim() : '';
    // Debounce a string key so identical inputs never re-trigger a request.
    const key = useDebounced(JSON.stringify({
        item_id: line.item_id || null, supplier_id: supplierId || null, quantity: qty || undefined,
        unit_id: line.entered_unit_id || undefined, currency_code: currencyCode || undefined, date: date || undefined,
    }));
    const params = useMemo(() => JSON.parse(key), [key]);
    const enabled = Boolean(params.item_id && params.supplier_id);
    const query = useApiQuery(['supplier-suggest', params], () => api.supplierTermsSuggest(params),
        { fallback: null, enabled, staleTime: 60_000, placeholderData: (previous) => previous });
    if (!enabled || !line.item_id || !supplierId || query.isError) return null;
    const data = query.data;
    if (!data || Number(data.item_id) !== Number(line.item_id)) return null;

    const suggestion = data.suggestion;
    const blocked = data.blocked && Number(data.blocked.supplier_id) === Number(supplierId) ? data.blocked : null;
    const sameSupplier = (data.candidates ?? []).find((c) => Number(c.supplier_id) === Number(supplierId));
    const best = data.best && Number(data.best.supplier_id) !== Number(supplierId) ? data.best : null;

    if (blocked) {
        return <div className="st-line-hints"><span className="st-chip is-danger" role="status">{blocked.message || t('supplierTerms.po.blocked')}</span></div>;
    }
    if (suggestion) {
        const price = suggestion.net_unit_price_entered_unit;
        const applied = sameNumber(line.unit_price, price);
        const unit = unitLabel(suggestion.purchase_unit);
        return (
            <div className="st-line-hints">
                <span className="st-chip is-info" title={data.rule ?? ''}>
                    <span>{t('supplierTerms.po.vendorPrice')} <Num>{priceText(price, suggestion.price_scale)} {suggestion.currency_code}</Num>
                        {' '}{t('supplierTerms.po.tierFrom', undefined, { qty: qtyText(suggestion.tier_minimum_qty), unit })}</span>
                    {applied ? <span className="st-chip__note">{t('supplierTerms.po.applied')}</span>
                        : <button type="button" className="btn btn--sm btn--link st-chip__action" onClick={() => onApplyPrice(trimDecimal(price))}>{t('supplierTerms.po.apply')}</button>}
                </span>
                {suggestion.quantity_adjusted && !sameNumber(line.ordered_qty, suggestion.suggested_entered_quantity) && (
                    <span className="st-chip is-warn">
                        <span>{t('supplierTerms.po.adjustQuantity', undefined, { qty: qtyText(suggestion.suggested_entered_quantity) })}</span>
                        <button type="button" className="btn btn--sm btn--link st-chip__action" onClick={() => onApplyQuantity(trimDecimal(suggestion.suggested_entered_quantity))}>{t('supplierTerms.po.apply')}</button>
                    </span>
                )}
                {suggestion.expected_date && <span className="st-chip is-muted">{t('supplierTerms.po.leadTime', undefined, { days: suggestion.lead_time_days, date: suggestion.expected_date })}</span>}
            </div>
        );
    }
    if (sameSupplier && currencyCode && sameSupplier.currency_code !== currencyCode) {
        return <div className="st-line-hints"><span className="st-chip is-muted">{t('supplierTerms.po.otherCurrency', undefined, { currency: sameSupplier.currency_code })}</span></div>;
    }
    if (best) {
        return <div className="st-line-hints"><span className="st-hint">{t('supplierTerms.po.bestElsewhere', undefined, { supplier: best.supplier?.name ?? '' })}
            {' '}<Num>{priceText(best.net_unit_price, best.price_scale)} {best.currency_code}</Num></span></div>;
    }
    return null;
}

/** Find an item by the PO supplier's own SKU or product name, then add it to the lines. */
export function SupplierSkuLookup({ supplierId, onPick }) {
    const [search, setSearch] = useState('');
    const term = useDebounced(search.trim(), 300);
    const enabled = Boolean(supplierId && term.length >= 2);
    const query = useApiQuery(['supplier-sku-lookup', supplierId, term], () => api.supplierTermsLookup({ supplier_id: supplierId, search: term }), { fallback: [], enabled });
    const rows = Array.isArray(query.data) ? query.data : [];
    const denied = query.isError && [402, 403].includes(query.error?.status);

    return (
        <div className="st-lookup">
            <label className="field st-lookup__field">
                <span className="field-label">{t('supplierTerms.po.lookupLabel')}</span>
                <input className="input" type="search" dir="auto" value={search} disabled={!supplierId} maxLength={100}
                    placeholder={supplierId ? t('supplierTerms.po.lookupPlaceholder') : t('supplierTerms.po.lookupNeedsSupplier')}
                    onChange={(e) => setSearch(e.target.value)} />
            </label>
            {enabled && <div className="st-lookup__results" aria-live="polite">
                {query.isLoading ? <span className="muted">{t('supplierTerms.common.searching')}</span>
                    : denied ? <span className="muted">{t('supplierTerms.access.lookupDenied')}</span>
                        : query.isError ? <span className="muted">{t('supplierTerms.common.loadFailed')}</span>
                            : rows.length === 0 ? <span className="muted">{t('supplierTerms.po.lookupNone')}</span>
                                : <ul className="st-lookup__list">{rows.map((r) => <li key={`${r.item_id}-${r.supplier_sku ?? ''}`}>
                                    <button type="button" className="st-lookup__option" onClick={() => { onPick(r); setSearch(''); }}>
                                        <span><Num>{r.supplier_sku || '—'}</Num>{r.supplier_item_name ? ` · ${r.supplier_item_name}` : ''}</span>
                                        <span className="muted"><Num>{r.item_sku}</Num> · {r.item_name}</span>
                                    </button>
                                </li>)}</ul>}
            </div>}
        </div>
    );
}
