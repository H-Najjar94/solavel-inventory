import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useToast } from '../stores/toast.jsx';
import { ConfirmedActionButton } from './ConfirmedActionButton.jsx';
import { AccessNotice } from './PlanAccess.jsx';
import { EmptyState, Skeleton, Badge } from './ui.jsx';
import ItemSupplierModal from './ItemSupplierModal.jsx';
import VendorPricelistTransfer from './VendorPricelistTransfer.jsx';
import {
    FinanceStateChip, Num, PreferredBadge, PriceWithCurrency, QueryProblem, RuleNote, TermsStatusBadge,
    costText, currentTier, decimalText, qtyText, unitLabel, useSupplierTermsAccess,
} from './SupplierTermsShared.jsx';
import { t } from '../i18n/index.js';

/** Item → Suppliers tab: supplier relationships with tiers, vendor comparison and cost trend. */
export default function ItemSuppliersPanel({ item }) {
    const access = useSupplierTermsAccess();
    const qc = useQueryClient();
    const toast = useToast();
    const [editing, setEditing] = useState(null); // null | 'new' | relationship
    const query = useApiQuery(['item-suppliers', String(item.id)], () => api.itemSuppliers(item.id), { fallback: null, enabled: access.viewTerms });
    const data = query.data;
    const rows = data?.suppliers ?? [];

    function refresh() {
        qc.invalidateQueries({ queryKey: ['item-suppliers', String(item.id)] });
        qc.invalidateQueries({ queryKey: ['vendor-comparison', String(item.id)] });
        qc.invalidateQueries({ queryKey: ['item', String(item.id)] });
        qc.invalidateQueries({ queryKey: ['supplier-items'] });
    }

    async function remove(relationship) {
        try {
            await api.deleteItemSupplier(item.id, relationship.id);
            toast.push(t('supplierTerms.list.removed'), 'success');
            refresh();
        } catch (err) {
            if ([402, 403].includes(err?.status)) toast.push(t('supplierTerms.access.saveDenied'), 'error');
            else toast.failure(err, t('supplierTerms.list.removeFailed'));
            return false;
        }
        return true;
    }

    if (!access.viewTerms) return <AccessNotice reason={access.viewTermsReason ?? 'permission_denied'} />;

    return (
        <div className="st-stack">
            <div className="card">
                <div className="card-head st-card-head">
                    <h3>{t('supplierTerms.list.title')}</h3>
                    <FinanceStateChip state={data?.finance?.state} />
                    <span className="spacer" />
                    <div className="st-actions">
                        <VendorPricelistTransfer itemId={item.id} compact />
                        {access.manageTerms && <button type="button" className="btn btn--sm btn--primary" onClick={() => setEditing('new')}>+ {t('supplierTerms.list.add')}</button>}
                    </div>
                </div>
                <div className="card-body">
                    {query.isLoading ? <Skeleton rows={3} /> : query.isError ? <QueryProblem error={query.error} onRetry={query.refetch} /> : rows.length === 0 ? (
                        <EmptyState title={t('supplierTerms.list.emptyTitle')} hint={t('supplierTerms.list.emptyHint')}
                            action={access.manageTerms ? <button type="button" className="btn btn--primary" onClick={() => setEditing('new')}>+ {t('supplierTerms.list.add')}</button> : undefined} />
                    ) : (
                        <div className="tbl-wrap"><table className="data-table st-table">
                            <thead><tr>
                                <th>{t('supplierTerms.common.supplier')}</th>
                                <th>{t('supplierTerms.common.status')}</th>
                                <th>{t('supplierTerms.list.supplierProduct')}</th>
                                <th>{t('supplierTerms.common.currency')}</th>
                                <th className="num">{t('supplierTerms.list.currentPrice')}</th>
                                <th className="num">{t('supplierTerms.list.tiers')}</th>
                                <th>{t('supplierTerms.list.moqMultiple')}</th>
                                <th className="num">{t('supplierTerms.list.leadTime')}</th>
                                <th className="num">{t('supplierTerms.list.discount')}</th>
                                {access.manageTerms && <th className="col-action" />}
                            </tr></thead>
                            <tbody>{rows.map((r) => {
                                const tier = currentTier(r);
                                const unit = unitLabel(r.purchase_unit);
                                return (
                                    <tr key={r.id}>
                                        <td>
                                            <div className="st-cell-stack">
                                                <Link to={`/suppliers/${r.supplier_id}`}>{r.supplier?.name ?? `#${r.supplier_id}`}</Link>
                                                <span className="st-badges">
                                                    {r.supplier?.code && <span className="muted"><Num>{r.supplier.code}</Num></span>}
                                                    {r.is_preferred && <PreferredBadge />}
                                                    {r.supplier?.is_active === false && <Badge tone="muted">{t('supplierTerms.list.supplierInactive')}</Badge>}
                                                </span>
                                            </div>
                                        </td>
                                        <td><TermsStatusBadge status={r.status} /></td>
                                        <td>
                                            <div className="st-cell-stack">
                                                {r.supplier_sku ? <Num>{r.supplier_sku}</Num> : <span className="muted">—</span>}
                                                {r.supplier_item_name && <span className="muted">{r.supplier_item_name}</span>}
                                            </div>
                                        </td>
                                        <td><Num>{r.currency_code ?? '—'}</Num></td>
                                        <td className="num">
                                            {tier ? <div className="st-cell-stack st-cell-stack--end">
                                                <PriceWithCurrency value={tier.unit_cost} currency={r.currency_code} scale={r.price_scale} />
                                                <span className="muted">{t('supplierTerms.list.perUnitFrom', undefined, { unit: unit || '—', qty: qtyText(tier.minimum_qty) })}</span>
                                            </div> : <span className="muted">{t('supplierTerms.list.noCurrentPrice')}</span>}
                                        </td>
                                        <td className="num"><Num>{(r.tiers ?? []).length}</Num></td>
                                        <td>
                                            <div className="st-cell-stack">
                                                <span>{t('supplierTerms.list.moq')}: <Num>{r.minimum_order_qty ? `${qtyText(r.minimum_order_qty)} ${unit}` : '—'}</Num></span>
                                                <span className="muted">{t('supplierTerms.list.multiple')}: <Num>{r.order_multiple ? `${qtyText(r.order_multiple)} ${unit}` : '—'}</Num></span>
                                            </div>
                                        </td>
                                        <td className="num">{r.lead_time_days !== null && r.lead_time_days !== undefined ? t('supplierTerms.common.days', undefined, { days: r.lead_time_days }) : '—'}</td>
                                        <td className="num"><Num>{r.discount_percent && Number(r.discount_percent) > 0 ? `${decimalText(r.discount_percent, 0, 6)}%` : '—'}</Num></td>
                                        {access.manageTerms && <td className="col-action">
                                            <div className="st-row-actions">
                                                <button type="button" className="btn btn--sm" onClick={() => setEditing(r)}>{t('supplierTerms.common.edit')}</button>
                                                <ConfirmedActionButton className="btn btn--sm btn--danger-ghost" onConfirm={() => remove(r)}
                                                    title={t('supplierTerms.list.removeTitle')} action={t('supplierTerms.list.remove')}
                                                    message={t('supplierTerms.list.removeBody', undefined, { supplier: r.supplier?.name ?? '' })}>{t('supplierTerms.list.remove')}</ConfirmedActionButton>
                                            </div>
                                        </td>}
                                    </tr>
                                );
                            })}</tbody>
                        </table></div>
                    )}
                    <RuleNote text={data?.price_rule} />
                </div>
            </div>

            {access.history && <VendorComparison itemId={item.id} access={access} />}

            {editing && <ItemSupplierModal itemId={item.id} baseUnit={item.base_unit} relationship={editing === 'new' ? null : editing}
                linkedSupplierIds={rows.map((r) => r.supplier_id)} onClose={() => setEditing(null)}
                onSaved={() => { setEditing(null); refresh(); }} />}
        </div>
    );
}

function VendorComparison({ itemId, access }) {
    const [quantityInput, setQuantityInput] = useState('1');
    const [quantity, setQuantity] = useState('1');
    const [date, setDate] = useState('');
    // Re-query shortly after the user stops typing.
    useEffect(() => {
        const timer = setTimeout(() => setQuantity(Number(quantityInput) > 0 ? String(quantityInput).trim() : '1'), 400);
        return () => clearTimeout(timer);
    }, [quantityInput]);
    const query = useApiQuery(['vendor-comparison', String(itemId), quantity, date],
        () => api.itemVendorComparison(itemId, { quantity, date: date || undefined }),
        { fallback: null, enabled: access.historyAllowed, placeholderData: (previous) => previous });
    const data = query.data;
    const rows = data?.rows ?? [];
    const trend = data?.trend ?? [];
    const base = data?.base_currency_code;

    if (!access.historyAllowed) return <div className="card"><div className="card-head"><h3>{t('supplierTerms.comparison.title')}</h3></div><div className="card-body"><AccessNotice reason={access.historyReason} /></div></div>;

    return (
        <>
            <div className="card">
                <div className="card-head st-card-head">
                    <h3>{t('supplierTerms.comparison.title')}</h3>
                    <span className="spacer" />
                    <div className="st-filters">
                        <label className="field">
                            <span className="field-label">{t('supplierTerms.comparison.quantity')}</span>
                            <input className="input input--num" type="number" inputMode="decimal" min="0" step="any" dir="ltr" value={quantityInput} onChange={(e) => setQuantityInput(e.target.value)} />
                        </label>
                        <label className="field">
                            <span className="field-label">{t('supplierTerms.comparison.date')}</span>
                            <input className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                        </label>
                    </div>
                </div>
                <div className="card-body">
                    {query.isLoading ? <Skeleton rows={3} /> : query.isError ? <QueryProblem error={query.error} onRetry={query.refetch} compact /> : rows.length === 0 ? (
                        <EmptyState title={t('supplierTerms.comparison.emptyTitle')} hint={t('supplierTerms.comparison.emptyHint')} />
                    ) : (
                        <div className="tbl-wrap"><table className="data-table st-table">
                            <thead><tr>
                                <th>{t('supplierTerms.common.supplier')}</th>
                                <th>{t('supplierTerms.common.status')}</th>
                                <th className="num">{t('supplierTerms.comparison.vendorPrice')}</th>
                                <th className="num">{t('supplierTerms.comparison.perBaseUnit', undefined, { currency: base ?? '' })}</th>
                                <th className="num">{t('supplierTerms.comparison.lastPaid', undefined, { currency: base ?? '' })}</th>
                                <th className="num">{t('supplierTerms.comparison.weightedAverage', undefined, { currency: base ?? '' })}</th>
                                <th className="num">{t('supplierTerms.comparison.difference')}</th>
                                <th className="num">{t('supplierTerms.list.leadTime')}</th>
                            </tr></thead>
                            <tbody>{rows.map((r) => {
                                const variance = r.vendor_vs_last_paid;
                                const up = variance !== null && variance !== undefined && Number(variance) > 0;
                                return (
                                    <tr key={r.supplier_id} className={r.is_best ? 'st-row--best' : ''}>
                                        <td>
                                            <div className="st-cell-stack">
                                                <Link to={`/suppliers/${r.supplier_id}`}>{r.supplier?.name ?? `#${r.supplier_id}`}</Link>
                                                <span className="st-badges">
                                                    {r.is_best && <Badge tone="ok">{t('supplierTerms.comparison.best')}</Badge>}
                                                    {r.is_preferred && <PreferredBadge />}
                                                </span>
                                            </div>
                                        </td>
                                        <td><TermsStatusBadge status={r.status} /></td>
                                        <td className="num">{r.vendor_price !== null && r.vendor_price !== undefined
                                            ? <PriceWithCurrency value={r.vendor_price} currency={r.currency_code} />
                                            : <span className="muted">{t(r.status === 'approved' ? 'supplierTerms.comparison.noTier' : 'supplierTerms.comparison.notOffered')}</span>}</td>
                                        <td className="num">{r.normalized === false
                                            ? <Badge tone="warn">{t('supplierTerms.comparison.noRate')}</Badge>
                                            : <Num>{costText(r.vendor_price_base_unit_in_base_currency)}</Num>}</td>
                                        <td className="num">
                                            <div className="st-cell-stack st-cell-stack--end">
                                                <Num>{costText(r.last_paid_base_unit_cost)}</Num>
                                                {r.last_paid_date && <span className="muted"><Num>{r.last_paid_date}</Num></span>}
                                            </div>
                                        </td>
                                        <td className="num"><Num>{costText(r.weighted_average_base_unit_cost)}</Num></td>
                                        <td className="num">{variance === null || variance === undefined ? <span className="muted">—</span>
                                            : <span className={`st-chip ${up ? 'is-warn' : 'is-ok'}`}><Num>{up ? '+' : ''}{costText(variance)}</Num></span>}</td>
                                        <td className="num">{r.lead_time_days !== null && r.lead_time_days !== undefined ? t('supplierTerms.common.days', undefined, { days: r.lead_time_days }) : '—'}</td>
                                    </tr>
                                );
                            })}</tbody>
                        </table></div>
                    )}
                    <RuleNote text={data?.rule} />
                </div>
            </div>

            <div className="card">
                <div className="card-head"><h3>{t('supplierTerms.trend.title')}</h3><span className="sub">{t('supplierTerms.trend.sub', undefined, { currency: base ?? '' })}</span></div>
                <div className="card-body">
                    {query.isLoading ? <Skeleton rows={2} /> : trend.length === 0 ? <EmptyState title={t('supplierTerms.trend.emptyTitle')} hint={t('supplierTerms.trend.emptyHint')} /> : (
                        <div className="tbl-wrap"><table className="data-table">
                            <thead><tr>
                                <th>{t('supplierTerms.trend.month')}</th><th>{t('supplierTerms.common.supplier')}</th>
                                <th className="num">{t('supplierTerms.trend.quantity')}</th><th className="num">{t('supplierTerms.trend.averageCost')}</th>
                            </tr></thead>
                            <tbody>{[...trend].reverse().map((row, i) => <tr key={`${row.month}-${row.supplier_id ?? row.supplier_name}-${i}`}>
                                <td><Num>{row.month}</Num></td>
                                <td>{row.supplier_id ? <Link to={`/suppliers/${row.supplier_id}`}>{row.supplier_name ?? `#${row.supplier_id}`}</Link> : (row.supplier_name ?? '—')}</td>
                                <td className="num"><Num>{qtyText(row.quantity)}</Num></td>
                                <td className="num"><Num>{costText(row.average_base_unit_cost)}</Num></td>
                            </tr>)}</tbody>
                        </table></div>
                    )}
                </div>
            </div>
        </>
    );
}
