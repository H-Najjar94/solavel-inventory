import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { AccessNotice } from './PlanAccess.jsx';
import { EmptyState, Field, Skeleton, Badge } from './ui.jsx';
import { FinanceStateChip, Num, PriceWithCurrency, QueryProblem, costText, moneyText, qtyText, decimalText, useSupplierTermsAccess } from './SupplierTermsShared.jsx';
import { t } from '../i18n/index.js';

/**
 * Read-only purchase history for one item (mode "item") or one supplier (mode "supplier"):
 * per-supplier statistics, posted goods receipts and, when connected, SolaCount posted bills.
 */
export default function PurchaseHistoryPanel({ mode = 'item', id }) {
    const access = useSupplierTermsAccess();
    const [range, setRange] = useState({ from: '', to: '' });
    const params = { from: range.from || undefined, to: range.to || undefined };
    const query = useApiQuery(['purchase-history', mode, String(id), range.from, range.to],
        () => (mode === 'item' ? api.itemPurchaseHistory(id, params) : api.supplierPurchaseHistory(id, params)),
        { fallback: null, enabled: Boolean(id) && access.historyAllowed });
    const data = query.data;
    const vendorPrices = useMemo(() => new Map((data?.vendor_prices ?? []).map((r) => [Number(r.supplier_id), r])), [data?.vendor_prices]);

    if (!access.history) return <EmptyState title={t('supplierTerms.access.deniedTitle')} hint={t('supplierTerms.history.permissionHint')} />;
    if (!access.historyAllowed) return <AccessNotice reason={access.historyReason} />;

    const base = data?.base_currency_code;
    const receipts = data?.receipts ?? [];
    const bills = data?.bills ?? [];
    const summary = data?.summary ?? [];
    const financeState = data?.finance?.state;
    const billsReadable = ['connected', 'paused'].includes(financeState);
    const basis = summary[0]?.basis;
    const showItem = mode === 'supplier';

    return (
        <div className="st-stack">
            <div className="card">
                <div className="card-head st-card-head">
                    <h3>{t('supplierTerms.history.title')}</h3>
                    <FinanceStateChip state={financeState} />
                    <span className="spacer" />
                    <div className="st-filters">
                        <Field label={t('supplierTerms.history.from')}><input className="input" type="date" value={range.from} max={range.to || undefined} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
                        <Field label={t('supplierTerms.history.to')}><input className="input" type="date" value={range.to} min={range.from || undefined} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
                        {(range.from || range.to) && <button type="button" className="btn btn--sm" onClick={() => setRange({ from: '', to: '' })}>{t('supplierTerms.common.clear')}</button>}
                    </div>
                </div>
                <div className="card-body">
                    <p className="field-hint">{t('supplierTerms.history.readOnlyHint')}</p>
                    {query.isLoading ? <Skeleton rows={3} /> : query.isError ? <QueryProblem error={query.error} onRetry={query.refetch} /> : summary.length === 0
                        ? <EmptyState title={t('supplierTerms.history.emptyTitle')} hint={t('supplierTerms.history.emptyHint')} />
                        : <>
                            <p className="muted">{t(basis === 'bills' ? 'supplierTerms.history.basisBills' : 'supplierTerms.history.basisReceipts', undefined, { currency: base ?? '' })}</p>
                            <div className="tbl-wrap"><table className="data-table">
                                <thead><tr>
                                    <th>{t('supplierTerms.common.supplier')}</th>
                                    <th className="num">{t('supplierTerms.history.documents')}</th>
                                    <th>{t('supplierTerms.history.lastDate')}</th>
                                    <th className="num">{t('supplierTerms.history.lastCost')}</th>
                                    <th className="num">{t('supplierTerms.history.lowest')}</th>
                                    <th className="num">{t('supplierTerms.history.highest')}</th>
                                    <th className="num">{t('supplierTerms.history.weightedAverage')}</th>
                                    <th className="num">{t('supplierTerms.history.totalQty')}</th>
                                    <th className="num">{t('supplierTerms.history.totalValue')}</th>
                                    {mode === 'item' && <th className="num">{t('supplierTerms.history.vendorPrice')}</th>}
                                </tr></thead>
                                <tbody>{summary.map((row, i) => {
                                    const vendor = row.supplier_id ? vendorPrices.get(Number(row.supplier_id)) : null;
                                    const tier = vendor ? [...(vendor.tiers ?? [])].filter((x) => x.is_current).sort((a, b) => Number(a.minimum_qty) - Number(b.minimum_qty))[0] : null;
                                    return <tr key={`${row.supplier_id ?? row.supplier_name}-${i}`}>
                                        <td>{row.supplier_id ? <Link to={`/suppliers/${row.supplier_id}`}>{row.supplier_name ?? `#${row.supplier_id}`}</Link> : (row.supplier_name ?? '—')}</td>
                                        <td className="num"><Num>{row.documents}</Num></td>
                                        <td><Num>{row.last_date ?? '—'}</Num></td>
                                        <td className="num"><PriceWithCurrency value={row.last_unit_cost} currency={row.last_currency_code} /></td>
                                        <td className="num"><Num>{costText(row.lowest_base_unit_cost)}</Num></td>
                                        <td className="num"><Num>{costText(row.highest_base_unit_cost)}</Num></td>
                                        <td className="num"><Num>{costText(row.weighted_average_base_unit_cost)}</Num></td>
                                        <td className="num"><Num>{qtyText(row.total_quantity)}</Num></td>
                                        <td className="num"><Num>{moneyText(row.total_base_value)}</Num></td>
                                        {mode === 'item' && <td className="num">{tier ? <PriceWithCurrency value={tier.unit_cost} currency={vendor.currency_code} scale={vendor.price_scale} /> : <span className="muted">—</span>}</td>}
                                    </tr>;
                                })}</tbody>
                            </table></div>
                            <p className="field-hint">{t('supplierTerms.history.baseNote', undefined, { currency: base ?? '' })}</p>
                        </>}
                </div>
            </div>

            {data && <div className="card">
                <div className="card-head"><h3>{t('supplierTerms.history.receiptsTitle')}</h3><span className="sub">{t('supplierTerms.history.receiptsSub')}</span></div>
                <div className="card-body">{receipts.length === 0 ? <EmptyState title={t('supplierTerms.history.noReceipts')} /> : (
                    <div className="tbl-wrap"><table className="data-table">
                        <thead><tr>
                            <th>{t('supplierTerms.history.date')}</th><th>{t('supplierTerms.history.receipt')}</th><th>{t('supplierTerms.history.purchaseOrder')}</th>
                            {showItem ? <th>{t('supplierTerms.common.item')}</th> : <th>{t('supplierTerms.common.supplier')}</th>}
                            <th className="num">{t('supplierTerms.history.quantity')}</th><th className="num">{t('supplierTerms.history.unitCost')}</th>
                            <th className="num">{t('supplierTerms.history.baseUnitCost')}</th>
                        </tr></thead>
                        <tbody>{receipts.map((r) => <tr key={`r-${r.line_id}`}>
                            <td><Num>{r.date}</Num></td>
                            <td>{r.path ? <Link to={r.path}><Num>{r.document_number ?? `#${r.document_id}`}</Num></Link> : <Num>{r.document_number}</Num>}</td>
                            <td><Num>{r.reference ?? '—'}</Num></td>
                            {showItem
                                ? <td>{r.item_id ? <Link to={`/items/${r.item_id}`}><Num>{r.item_sku}</Num> · {r.item_name}</Link> : (r.item_name ?? '—')}</td>
                                : <td>{r.supplier_id ? <Link to={`/suppliers/${r.supplier_id}`}>{r.supplier_name}</Link> : (r.supplier_name ?? '—')}</td>}
                            <td className="num"><Num>{qtyText(r.quantity)}</Num></td>
                            <td className="num"><PriceWithCurrency value={r.unit_cost} currency={r.currency_code} /></td>
                            <td className="num"><Num>{costText(r.base_unit_cost)}</Num></td>
                        </tr>)}</tbody>
                    </table></div>
                )}</div>
            </div>}

            {data && billsReadable && <div className="card">
                <div className="card-head st-card-head"><h3>{t('supplierTerms.history.billsTitle')}</h3><span className="sub">{t('supplierTerms.history.billsSub')}</span></div>
                <div className="card-body">{bills.length === 0 ? <EmptyState title={t('supplierTerms.history.noBills')} hint={t('supplierTerms.history.noBillsHint')} /> : (
                    <div className="tbl-wrap"><table className="data-table">
                        <thead><tr>
                            <th>{t('supplierTerms.history.date')}</th><th>{t('supplierTerms.history.bill')}</th><th>{t('supplierTerms.history.supplierInvoice')}</th>
                            {showItem ? <th>{t('supplierTerms.common.item')}</th> : <th>{t('supplierTerms.common.supplier')}</th>}
                            <th className="num">{t('supplierTerms.history.quantity')}</th><th className="num">{t('supplierTerms.history.unitPrice')}</th>
                            <th className="num">{t('supplierTerms.history.discount')}</th><th className="num">{t('supplierTerms.history.tax')}</th>
                            <th>{t('supplierTerms.common.currency')}</th><th className="num">{t('supplierTerms.history.rate')}</th>
                            <th className="num">{t('supplierTerms.history.netUnitCost')}</th><th className="num">{t('supplierTerms.history.baseUnitCost')}</th><th />
                        </tr></thead>
                        <tbody>{bills.map((b) => <tr key={`b-${b.line_id}`}>
                            <td><Num>{b.date}</Num></td>
                            <td><Num>{b.document_number ?? `#${b.document_id}`}</Num></td>
                            <td><Num>{b.reference || '—'}</Num></td>
                            {showItem
                                ? <td>{b.item_id ? <Link to={`/items/${b.item_id}`}>{b.item_name ?? `#${b.item_id}`}</Link> : (b.item_name ?? '—')}</td>
                                : <td>{b.supplier_id ? <Link to={`/suppliers/${b.supplier_id}`}>{b.supplier_name}</Link> : <>{b.supplier_name ?? '—'}{b.supplier_mapped === false && <> <Badge tone="warn">{t('supplierTerms.history.notMapped')}</Badge></>}</>}</td>}
                            <td className="num"><Num>{qtyText(b.quantity)}</Num></td>
                            <td className="num"><Num>{costText(b.unit_price)}</Num></td>
                            <td className="num"><Num>{moneyText(b.discount)}</Num></td>
                            <td className="num"><Num>{moneyText(b.tax)}</Num></td>
                            <td><Num>{b.currency_code ?? '—'}</Num></td>
                            <td className="num"><Num>{decimalText(b.exchange_rate, 0, 8)}</Num></td>
                            <td className="num"><Num>{costText(b.unit_cost)}</Num></td>
                            <td className="num"><Num>{costText(b.base_unit_cost)}</Num></td>
                            <td>{b.url && <a className="btn btn--sm" href={b.url} target="_blank" rel="noopener noreferrer">{t('supplierTerms.history.openBill')}<span className="ss-visually-hidden"> {t('supplierTerms.common.newTab')}</span> <i className="fa-solid fa-arrow-up-right-from-square" aria-hidden="true" /></a>}</td>
                        </tr>)}</tbody>
                    </table></div>
                )}</div>
            </div>}
        </div>
    );
}
