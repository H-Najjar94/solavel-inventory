import React, { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Field, Skeleton, Badge } from '../components/ui.jsx';
import { SupplierPicker, WarehousePicker } from '../components/pickers.jsx';
import { Num, PreferredBadge, QueryProblem, moneyText, qtyText, costText, trimDecimal } from '../components/SupplierTermsShared.jsx';
import { useI18n } from '../i18n/context.jsx';

const NO_SUPPLIER = 'none';

/**
 * Advisory reorder list: items at or below their reorder point, with the preferred or best
 * supplier and a quantity rounded to that supplier's terms. Nothing is created from here;
 * "Create purchase order" opens the normal PO form prefilled for review.
 */
export default function ReorderSuggestionsPage() {
    const { t } = useI18n();
    const nav = useNavigate();
    const gate = useCanCreate('inventory.manage_purchase_orders');
    const [warehouseId, setWarehouseId] = useState(null);
    const [supplierId, setSupplierId] = useState(null);
    const query = useApiQuery(['reorder-suggestions', warehouseId, supplierId],
        () => api.supplierTermsReorder({ warehouse_id: warehouseId || undefined, supplier_id: supplierId || undefined }),
        { fallback: null, placeholderData: (previous) => previous });
    const rows = query.data?.rows ?? [];

    // One group per supplier + warehouse (a purchase order has one of each).
    const groups = useMemo(() => {
        const map = new Map();
        rows.forEach((row) => {
            const key = `${row.supplier?.id ?? NO_SUPPLIER}:${row.warehouse_id}`;
            if (!map.has(key)) map.set(key, { key, supplier: row.supplier, warehouseId: row.warehouse_id, warehouse: row.warehouse, rows: [] });
            map.get(key).rows.push(row);
        });
        return [...map.values()].sort((a, b) => (a.supplier ? 0 : 1) - (b.supplier ? 0 : 1)
            || String(a.supplier?.name ?? '').localeCompare(String(b.supplier?.name ?? '')) || String(a.warehouse ?? '').localeCompare(String(b.warehouse ?? '')));
    }, [rows]);

    function createPurchaseOrder(group) {
        const currency = group.rows.find((r) => r.supplier?.currency_code)?.supplier.currency_code;
        const expected = group.rows.map((r) => r.supplier?.expected_date).filter(Boolean).sort().pop();
        nav('/purchase-orders/new', { state: { prefill: {
            source: 'reorder',
            supplier_id: group.supplier?.id ?? null,
            warehouse_id: group.warehouseId,
            currency_code: currency ?? null,
            expected_date: expected ?? '',
            lines: group.rows.map((r) => ({
                item_id: r.item_id,
                ordered_qty: trimDecimal(r.suggested_qty),
                // Suggested quantities and prices are per base unit.
                unit_price: r.supplier?.unit_price_base_unit != null ? trimDecimal(r.supplier.unit_price_base_unit) : '',
            })),
        } } });
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders'), to: '/purchase-orders' }, { label: t('supplierTerms.reorder.title') }]} />
            <header className="page-head">
                <div><h1>{t('supplierTerms.reorder.title')}</h1><p className="muted">{t('supplierTerms.reorder.intro')}</p></div>
            </header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="card">
                <div className="card-body">
                    <div className="st-filters">
                        <Field label={t('supplierTerms.reorder.warehouse')}><WarehousePicker value={warehouseId} onChange={setWarehouseId} placeholder={t('supplierTerms.reorder.allWarehouses')} /></Field>
                        <Field label={t('supplierTerms.common.supplier')}><SupplierPicker value={supplierId} onChange={setSupplierId} /></Field>
                        {(warehouseId || supplierId) && <button type="button" className="btn btn--sm" onClick={() => { setWarehouseId(null); setSupplierId(null); }}>{t('supplierTerms.common.clear')}</button>}
                    </div>
                    <p className="field-hint">{t(supplierId ? 'supplierTerms.reorder.supplierFilterHint' : 'supplierTerms.reorder.choiceHint')}</p>
                </div>
            </div>

            {query.isLoading ? <Skeleton rows={5} /> : query.isError ? <QueryProblem error={query.error} onRetry={query.refetch} /> : groups.length === 0 ? (
                <EmptyState title={t('supplierTerms.reorder.emptyTitle')} hint={t('supplierTerms.reorder.emptyHint')} />
            ) : (
                <div className="st-stack" style={{ marginTop: 18 }}>
                    {groups.map((group) => {
                        const total = group.rows.reduce((sum, r) => sum + Number(r.supplier?.estimated_total ?? 0), 0);
                        const currency = group.supplier?.currency_code;
                        return (
                            <div className="card" key={group.key}>
                                <div className="card-head st-card-head">
                                    <div className="st-group__head">
                                        <h3>{group.supplier ? <Link to={`/suppliers/${group.supplier.id}`}>{group.supplier.name ?? `#${group.supplier.id}`}</Link> : t('supplierTerms.reorder.noSupplier')}</h3>
                                        {group.supplier?.is_preferred && <PreferredBadge />}
                                        <span className="st-chip is-muted">{t('supplierTerms.reorder.warehouseChip', undefined, { warehouse: group.warehouse ?? `#${group.warehouseId}` })}</span>
                                        {group.supplier && <span className="st-chip">{t('supplierTerms.reorder.estimatedTotal')} <Num>{moneyText(total)} {currency ?? ''}</Num></span>}
                                    </div>
                                    <span className="spacer" />
                                    {gate.allowed && <button type="button" className="btn btn--sm btn--primary" onClick={() => createPurchaseOrder(group)}>{t('supplierTerms.reorder.createPo')}</button>}
                                </div>
                                <div className="card-body">
                                    {!group.supplier && <p className="field-hint">{t('supplierTerms.reorder.noSupplierHint')}</p>}
                                    <div className="tbl-wrap"><table className="data-table st-table">
                                        <thead><tr>
                                            <th>{t('supplierTerms.common.item')}</th>
                                            <th className="num">{t('supplierTerms.reorder.available')}</th>
                                            <th className="num">{t('supplierTerms.reorder.incoming')}</th>
                                            <th className="num">{t('supplierTerms.reorder.reorderPoint')}</th>
                                            <th className="num">{t('supplierTerms.reorder.needed')}</th>
                                            <th className="num">{t('supplierTerms.reorder.suggested')}</th>
                                            <th className="num">{t('supplierTerms.reorder.unitPrice')}</th>
                                            <th className="num">{t('supplierTerms.reorder.lineTotal')}</th>
                                            <th>{t('supplierTerms.reorder.expected')}</th>
                                        </tr></thead>
                                        <tbody>{group.rows.map((r) => (
                                            <tr key={`${r.item_id}-${r.warehouse_id}`}>
                                                <td>
                                                    <div className="st-cell-stack">
                                                        <Link to={`/items/${r.item_id}?tab=suppliers`}>{r.item}</Link>
                                                        <span className="muted"><Num>{r.sku}</Num></span>
                                                    </div>
                                                </td>
                                                <td className="num"><Num>{qtyText(r.available_qty)}</Num></td>
                                                <td className="num"><Num>{qtyText(r.incoming_qty)}</Num></td>
                                                <td className="num"><Num>{qtyText(r.reorder_point)}</Num></td>
                                                <td className="num"><Num>{qtyText(r.needed_qty)}</Num></td>
                                                <td className="num">
                                                    <div className="st-cell-stack st-cell-stack--end">
                                                        <strong><Num>{qtyText(r.suggested_qty)}</Num></strong>
                                                        {r.supplier?.quantity_adjusted && <Badge tone="info">{t('supplierTerms.reorder.roundedToTerms')}</Badge>}
                                                    </div>
                                                </td>
                                                <td className="num">{r.supplier ? <Num>{costText(r.supplier.unit_price_base_unit)} {r.supplier.currency_code}</Num> : <span className="muted">—</span>}</td>
                                                <td className="num">{r.supplier ? <Num>{moneyText(r.supplier.estimated_total)}</Num> : <span className="muted">—</span>}</td>
                                                <td>{r.supplier?.expected_date
                                                    ? <div className="st-cell-stack"><Num>{r.supplier.expected_date}</Num><span className="muted">{t('supplierTerms.common.days', undefined, { days: r.supplier.lead_time_days })}</span></div>
                                                    : <span className="muted">—</span>}</td>
                                            </tr>
                                        ))}</tbody>
                                    </table></div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
