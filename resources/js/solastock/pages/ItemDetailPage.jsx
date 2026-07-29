import React, { useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, StatusBadge, EmptyState, Drawer, MetricCard, Badge } from '../components/ui.jsx';
import ItemImages from '../components/ItemImages.jsx';
import ItemAttachments from '../components/ItemAttachments.jsx';
import { MoneyInput, SupplierPicker } from '../components/pickers.jsx';
import { t } from '../i18n/index.js';

// ── helpers ──
const num = (v) => Number(v ?? 0);
const money = (v) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 4 });
const sourceText = (obj) => obj?.source_display || obj?.source_label || t('itemDetail.sourceUnavailable');
const sourceLink = (obj) => obj?.source_route || null;
const layerValue = (remainingQty, unitCost) => money(num(remainingQty) * num(unitCost));

function SourceRef({ obj }) {
    const text = sourceText(obj);
    const to = sourceLink(obj);
    return to ? <Link to={to}>{text}</Link> : <span>{text}</span>;
}

function LayerMetric({ label, value, tone }) {
    return (
        <div className={`fifo-metric ${tone ? `fifo-metric--${tone}` : ''}`}>
            <div className="fifo-metric__label">{label}</div>
            <div className="fifo-metric__value">{value}</div>
        </div>
    );
}

function RemainingLayerCard({ layer, index }) {
    const consumedQty = layer.consumed_qty ?? Math.max(0, num(layer.original_qty) - num(layer.remaining_qty));
    return (
        <div className="fifo-layer-card">
            <div className="fifo-layer-card__head">
                <Badge tone="info">{t('itemDetail.fifoLayer', undefined, { number: index + 1 })}</Badge>
                <Badge tone="ok">{t('itemDetail.remainingLayer')}</Badge>
                <span className="fifo-layer-card__date">{t('itemDetail.receivedDate', undefined, { date: layer.received_at ?? t('itemDetail.dateUnavailable') })}</span>
            </div>
            <div className="fifo-layer-card__source">
                <span>{t('itemDetail.sourceDocument')}</span>
                <strong><SourceRef obj={layer} /></strong>
            </div>
            <div className="fifo-layer-grid">
                <LayerMetric label={t('itemDetail.originalQuantity')} value={qty(layer.original_qty)} />
                <LayerMetric label={t('itemDetail.consumedQuantity')} value={qty(consumedQty)} tone={num(consumedQty) > 0 ? 'warn' : undefined} />
                <LayerMetric label={t('itemDetail.remainingQuantity')} value={qty(layer.remaining_qty)} tone="ok" />
                <LayerMetric label={t('itemDetail.unitCost')} value={money(layer.unit_cost)} />
                <LayerMetric label={t('itemDetail.remainingValue')} value={layer.layer_value ?? layerValue(layer.remaining_qty, layer.unit_cost)} tone="ok" />
                <LayerMetric label={t('itemDetail.warehouse')} value={layer.warehouse_name || layer.warehouse_code || t('itemDetail.warehouseUnavailable')} />
            </div>
        </div>
    );
}

function ConsumedLayerCard({ layer, index }) {
    const source = layer.source_layer ?? {};
    return (
        <div className="fifo-layer-card fifo-layer-card--consumed">
            <div className="fifo-layer-card__head">
                <Badge tone="warn">{t('itemDetail.consumedLayer', undefined, { number: index + 1 })}</Badge>
                <span className="fifo-layer-card__date">{t('itemDetail.receivedDate', undefined, { date: source.received_at ?? t('itemDetail.dateUnavailable') })}</span>
            </div>
            <div className="fifo-layer-card__source">
                <span>{t('itemDetail.sourceDocument')}</span>
                <strong><SourceRef obj={source} /></strong>
            </div>
            <div className="fifo-layer-grid">
                <LayerMetric label={t('itemDetail.consumedQuantity')} value={qty(layer.qty)} tone="warn" />
                <LayerMetric label={t('itemDetail.unitCost')} value={money(layer.unit_cost)} />
                <LayerMetric label={t('itemDetail.consumedValue')} value={money(layer.total_value)} tone="warn" />
                <LayerMetric label={t('itemDetail.layerRemainingAfter')} value={source.remaining_qty != null ? qty(source.remaining_qty) : '—'} />
                <LayerMetric label={t('itemDetail.layerOriginalQuantity')} value={source.original_qty != null ? qty(source.original_qty) : '—'} />
                <LayerMetric label={t('itemDetail.consumedAt')} value={layer.consumed_at ?? '—'} />
            </div>
        </div>
    );
}

// Read-only valuation drawer (FIFO layer stack + FIFO-vs-average + reconciliation).
function ValuationDrawer({ itemId, open, onClose }) {
    const { data: v, isLoading, isError, error, refetch } = useApiQuery(
        ['item-valuation', itemId], () => api.itemValuation(itemId), { enabled: open });
    const isFifo = v?.costing_method === 'fifo';
    const hasStock = (v?.warehouses ?? []).length > 0;

    return (
        <Drawer open={open} onClose={onClose} title={t('itemDetail.stockValuation')}
            subtitle={v ? `${v.sku} · ${isFifo ? 'FIFO' : t('items.average')} ${t('itemDetail.costingSuffix')}` : ''} width={500}>
            {isLoading && <Skeleton rows={5} />}
            {isError && (
                <div className="drawer-error">{t('itemDetail.loadValuationFailed')}{error?.message ? `: ${error.message}` : '.'}
                    <div><button className="btn btn--sm" onClick={() => refetch()}>{t('itemDetail.tryAgain')}</button></div></div>
            )}
            {v && !isError && (
                <>
                    <div className="drawer-note">
                        {isFifo
                            ? t('itemDetail.fifoExplanation')
                            : t('itemDetail.averageExplanation')}
                    </div>
                    {hasStock && (
                        <div className="wh-card-grid" style={{ marginBottom: 18 }}>
                        <MetricCard label={t('itemDetail.onHandValue')} value={money(v.on_hand_value)} sub={isFifo ? t('itemDetail.fifoBasis') : t('itemDetail.averageBasis')} tone="ok" />
                            <MetricCard label={isFifo ? t('itemDetail.sameStockAverage') : t('itemDetail.sameStockFifo')} value={money(isFifo ? v.average_value_total : v.fifo_value_total)} sub={t('itemDetail.forComparison')} />
                        </div>
                    )}
                    {!hasStock && <EmptyState title={t('itemDetail.noStockValue')} hint={t('itemDetail.noStockValueHint')} />}
                    {(v.warehouses ?? []).map((w) => (
                        <div className="card wh-card" key={w.warehouse_id} style={{ marginBottom: 14 }}>
                            <div className="wh-card-head">
                                <span className="wh-card-name">{w.warehouse_name ?? `Warehouse #${w.warehouse_id}`}</span>
                                {w.warehouse_code && <span className="wh-card-code">{w.warehouse_code}</span>}
                                {w.qty_reconciled ? <Badge tone="ok">{t('itemDetail.reconciled')}</Badge> : <Badge tone="danger">{t('itemDetail.needsReview')}</Badge>}
                            </div>
                            <div className="wh-card-grid" style={{ marginBottom: 10 }}>
                                <div><div className="wh-stat-label">{t('itemDetail.onHand')}</div><div className="wh-stat-val">{qty(w.on_hand_qty)}</div></div>
                                <div><div className="wh-stat-label">Value {isFifo ? '(FIFO)' : ''}</div><div className="wh-stat-val">{money(isFifo ? w.fifo_value : w.average_value)}</div></div>
                                <div><div className="wh-stat-label">{t('itemDetail.averageCostUnit')}</div><div className="wh-stat-val">{money(w.average_cost)}</div></div>
                                <div><div className="wh-stat-label">{isFifo ? 'Average basis' : 'FIFO basis'}</div><div className="wh-stat-val">{money(isFifo ? w.average_value : w.fifo_value)}</div></div>
                            </div>
                            {isFifo && (w.layers?.length > 0 ? (
                                <>
                                    <div className="section-label">{t('itemDetail.remainingLayers')}</div>
                                    <div className="fifo-layer-stack">
                                        {w.layers.map((l, i) => <RemainingLayerCard layer={l} index={i} key={l.id} />)}
                                    </div>
                                </>
                            ) : <div className="layer-row-meta">{t('itemDetail.noRemainingLayers')}</div>)}
                            <div className="recon-line">
                                {w.qty_reconciled ? <>✓ {t('itemDetail.layersReconciled')}</> : <>⚠ {t('itemDetail.layersMismatch')}</>}
                            </div>
                        </div>
                    ))}
                </>
            )}
        </Drawer>
    );
}

// Read-only movement drawer (detail + consumed FIFO layers for OUT).
function MovementDrawer({ movement, open, onClose }) {
    const isOut = movement?.direction === 'out';
    const { data: consumed, isLoading, isError, error, refetch } = useApiQuery(
        ['consumed-layers', movement?.id], () => api.movementConsumedLayers(movement.id),
        { enabled: open && isOut && !!movement?.id });

    if (!movement) return null;
    return (
        <Drawer open={open} onClose={onClose} title={t('itemDetail.movementDetail')}
            subtitle={`${movement.moved_at ?? ''} · ${movement.warehouse_name ?? `WH #${movement.warehouse_id}`}`} width={480}>
            <dl className="mv-kv">
                <dt>{t('itemDetail.type')}</dt><dd><Badge tone={isOut ? 'warn' : 'ok'}>{t(isOut ? 'itemDetail.stockOut' : 'itemDetail.stockIn')}</Badge></dd>
                <dt>{t('itemDetail.warehouse')}</dt><dd>{movement.warehouse_name ?? '—'} {movement.warehouse_code && <span className="wh-card-code">{movement.warehouse_code}</span>}</dd>
                <dt>{t('itemDetail.quantity')}</dt><dd>{qty(movement.quantity)}</dd>
                <dt>{t('itemDetail.unitCost')}</dt><dd>{movement.unit_cost ? money(movement.unit_cost) : '—'}</dd>
                <dt>{t('itemDetail.totalCost')}</dt><dd>{movement.total_cost ? money(movement.total_cost) : '—'}</dd>
                <dt>{t('itemDetail.onHandAfter')}</dt><dd>{movement.balance_qty_after ? qty(movement.balance_qty_after) : '—'}</dd>
                <dt>{t('itemDetail.valueAfter')}</dt><dd>{movement.balance_value_after ? money(movement.balance_value_after) : '—'}</dd>
                <dt>{t('itemDetail.source')}</dt><dd>{movement.source_display ?? movement.source_label ?? '—'}</dd>
            </dl>
            {isOut && (
                <div style={{ marginTop: 18 }}>
                    <div className="section-label">{t('itemDetail.fifoConsumption')}</div>
                    <div className="drawer-note">{t('itemDetail.fifoConsumptionHint')}</div>
                    {isLoading && <Skeleton rows={2} />}
                    {isError && <div className="drawer-error">{t('itemDetail.loadConsumedFailed')}<div><button className="btn btn--sm" onClick={() => refetch()}>{t('itemDetail.tryAgain')}</button></div></div>}
                    {consumed && !isError && consumed.consumed_layer_count === 0 && <div className="layer-row-meta">{t('itemDetail.averageNoLayers')}</div>}
                    {consumed && !isError && consumed.consumed_layer_count > 0 && (
                        <>
                            <div className="fifo-layer-stack">
                                {consumed.layers.map((l, i) => <ConsumedLayerCard layer={l} index={i} key={`${l.cost_layer_id}-${i}`} />)}
                            </div>
                            <div className="wh-card-foot fifo-total-line"><span className="wh-stat-label">{t('itemDetail.totalStockUsed')}</span>
                                <strong style={{ marginLeft: 'auto', fontVariantNumeric: 'tabular-nums' }}>{money(consumed.consumed_total_value)}</strong></div>
                        </>
                    )}
                </div>
            )}
        </Drawer>
    );
}

// Compact movements table → opens the movement drawer.
function MovementsTable({ rows, onRow }) {
    if (!rows.length) return <EmptyState title={t('itemDetail.noMovements')} hint={t('itemDetail.noMovementsHint')} />;
    return (
        <table className="data-table">
            <thead><tr><th>{t('itemDetail.date')}</th><th>{t('itemDetail.direction')}</th><th>{t('itemDetail.quantity')}</th><th>{t('itemDetail.unitCost')}</th><th>{t('itemDetail.runningQuantity')}</th><th>{t('itemDetail.warehouse')}</th><th>{t('itemDetail.source')}</th><th></th></tr></thead>
            <tbody>{rows.map((m) => (
                <tr key={m.id} className="clickable-row" onClick={() => onRow(m)} title={t('itemDetail.viewMovement')}>
                    <td>{m.moved_at}</td><td><Badge tone={m.direction === 'out' ? 'warn' : 'ok'}>{m.direction}</Badge></td>
                    <td>{qty(m.quantity)}</td><td>{m.unit_cost ? money(m.unit_cost) : '—'}</td>
                    <td>{m.balance_qty_after ? qty(m.balance_qty_after) : '—'}</td><td>{m.warehouse_name ?? `#${m.warehouse_id}`}</td>
                    <td>{m.source_display ?? m.source_label ?? '—'}</td>
                    <td><button className="btn btn--sm" onClick={(e) => { e.stopPropagation(); onRow(m); }}>{t('itemDetail.view')}</button></td>
                </tr>
            ))}</tbody>
        </table>
    );
}

// Warehouse stock cards (shared by Overview + Inventory).
function WarehouseCards({ cards, onValuation }) {
    if (!cards.length) return <EmptyState title={t('itemDetail.noStock')} hint={t('itemDetail.noStockHint')} />;
    return (
        <div className="wh-cards">
            {cards.map((w) => (
                <div className="card wh-card" key={w.warehouse_id}>
                    <div className="wh-card-head">
                        <span className="wh-card-name">{w.warehouse_name ?? `Warehouse #${w.warehouse_id}`}</span>
                        {w.warehouse_code && <span className="wh-card-code">{w.warehouse_code}</span>}
                        {w.qty_reconciled === false && <Badge tone="danger">{t('itemDetail.quantityMismatch')}</Badge>}
                    </div>
                    <div className="wh-card-grid">
                        <div><div className="wh-stat-label">{t('itemDetail.onHand')}</div><div className="wh-stat-val">{qty(w.on_hand_qty)}</div></div>
                        <div><div className="wh-stat-label">{t('itemDetail.available')}</div><div className="wh-stat-val">{qty(w.available_qty)}</div></div>
                        <div><div className="wh-stat-label">{t('itemDetail.reserved')}</div><div className="wh-stat-val">{qty(w.reserved_qty)}</div></div>
                        <div><div className="wh-stat-label">{t('itemDetail.value')}</div><div className="wh-stat-val">{money(w.fifo_value ?? w.average_value)}</div></div>
                    </div>
                    {onValuation && <div className="wh-card-foot"><button className="btn btn--sm" onClick={onValuation}>{t('itemDetail.viewValuation')}</button></div>}
                </div>
            ))}
        </div>
    );
}

export default function ItemDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const qc = useQueryClient();
    const toast = useToast();
    const [tab, setTab] = useState('overview');
    const [valuationOpen, setValuationOpen] = useState(false);
    const [activeMovement, setActiveMovement] = useState(null);
    const [newBarcode, setNewBarcode] = useState({ barcode: '', type: 'internal' });
    const [scanCode, setScanCode] = useState('');
    const [scanResult, setScanResult] = useState(null);
    const [variantForm, setVariantForm] = useState({ sku: '', option: '', value: '', barcode_primary: '', purchase_price: '', sales_price: '' });
    const [priceForm, setPriceForm] = useState({ supplier_id: null, supplier_sku: '', unit_cost: '', minimum_qty: '1', currency_code: 'SAR' });
    const [labels, setLabels] = useState(null);

    const { data, isLoading } = useApiQuery(['item', id], () => api.item(id), { fallback: null });
    const item = data?.item;

    const valuation = useApiQuery(['item-valuation', id], () => api.itemValuation(id), { fallback: null });
    const val = valuation.data ?? null;
    const cards = val?.warehouses ?? [];

    const movements = useApiQuery(['item-movements', id], () => api.itemMovements(id, { per_page: 50 }), { fallback: [] });
    const movementRows = Array.isArray(movements.data) ? movements.data : (movements.data?.data ?? []);

    // Aggregate headline figures across warehouses.
    const totals = useMemo(() => cards.reduce((a, w) => ({
        onHand: a.onHand + num(w.on_hand_qty), available: a.available + num(w.available_qty), reserved: a.reserved + num(w.reserved_qty),
    }), { onHand: 0, available: 0, reserved: 0 }), [cards]);

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!item) {
        return (
            <section className="page">
                <Breadcrumbs items={[{ label: t('items'), to: '/items' }, { label: t('itemDetail.notFound') }]} />
                <EmptyState title={t('itemDetail.unavailable')} hint={t('itemDetail.unavailableHint')} />
            </section>
        );
    }

    const isFifo = (item.costing_method ?? 'average') === 'fifo';
    const isService = item.item_type === 'service';
    const reorder = num(item.reorder_point);
    const lowStock = !isService && reorder > 0 && totals.available <= reorder;

    const tabs = [
        { key: 'overview', label: t('itemDetail.overview') },
        { key: 'inventory', label: t('itemDetail.inventory') },
        { key: 'movements', label: t('itemDetail.movements') },
        { key: 'barcodes', label: t('itemDetail.barcodes') },
        { key: 'media', label: t('itemDetail.media') },
        { key: 'details', label: t('itemDetail.details') },
    ];

    async function addBarcode(e) {
        e.preventDefault();
        if (!newBarcode.barcode.trim()) return;
        try {
            await api.createItemBarcode(item.id, newBarcode);
            setNewBarcode({ barcode: '', type: 'internal' });
            await qc.invalidateQueries({ queryKey: ['item', id] });
            toast.push(t('itemDetail.barcodeAdded'), 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function lookupBarcode(e) {
        e.preventDefault();
        setScanResult(null);
        if (!scanCode.trim()) return;
        try {
            const res = await api.barcodeLookup(scanCode.trim());
            setScanResult(res.data);
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function makePrimary(barcodeId) {
        try {
            await api.makeItemBarcodePrimary(item.id, barcodeId);
            await qc.invalidateQueries({ queryKey: ['item', id] });
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function removeBarcode(barcodeId) {
        try {
            await api.deleteItemBarcode(item.id, barcodeId);
            await qc.invalidateQueries({ queryKey: ['item', id] });
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addVariant(e) {
        e.preventDefault();
        if (!variantForm.sku.trim()) return;
        const attrs = variantForm.option.trim() ? { [variantForm.option.trim()]: variantForm.value.trim() } : {};
        try {
            await api.createItemVariant(item.id, {
                sku: variantForm.sku.trim(),
                variant_attributes: attrs,
                barcode_primary: variantForm.barcode_primary || undefined,
                purchase_price: variantForm.purchase_price || undefined,
                sales_price: variantForm.sales_price || undefined,
                is_active: true,
            });
            setVariantForm({ sku: '', option: '', value: '', barcode_primary: '', purchase_price: '', sales_price: '' });
            await qc.invalidateQueries({ queryKey: ['item', id] });
            toast.push(t('itemDetail.variantAdded'), 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function deleteVariant(variantId) {
        try {
            await api.deleteItemVariant(item.id, variantId);
            await qc.invalidateQueries({ queryKey: ['item', id] });
            toast.push(t('itemDetail.variantRemoved'), 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addSupplierPrice(e) {
        e.preventDefault();
        if (!priceForm.supplier_id || !priceForm.unit_cost) return;
        try {
            await api.createSupplierPrice(item.id, priceForm);
            setPriceForm({ supplier_id: null, supplier_sku: '', unit_cost: '', minimum_qty: '1', currency_code: 'SAR' });
            await qc.invalidateQueries({ queryKey: ['item', id] });
            toast.push(t('itemDetail.supplierPriceSaved'), 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function deleteSupplierPrice(priceId) {
        try {
            await api.deleteSupplierPrice(item.id, priceId);
            await qc.invalidateQueries({ queryKey: ['item', id] });
            toast.push(t('itemDetail.supplierPriceRemoved'), 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function loadLabels() {
        try {
            const res = await api.itemLabelSheet(item.id);
            setLabels(res.data ?? res);
            setTimeout(() => window.print(), 150);
        } catch (err) { toast.push(err.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('items'), to: '/items' }, { label: item.name }]} />

            {/* ── Hero header ── */}
            <div className="item-hero card">
                <div className="item-hero-media">
                    {data.primary_image_url
                        ? <img src={data.primary_image_url} alt={item.name} />
                        : <div className="item-hero-ph"><span>📦</span></div>}
                </div>
                <div className="item-hero-body">
                    <div className="item-hero-top">
                        <h1>{item.name}</h1>
                        <StatusBadge active={item.is_active} />
                        {lowStock && <Badge tone="warn">{t('items.low')}</Badge>}
                    </div>
                    <div className="item-hero-meta">
                        <span><b>{t('sku')}</b> <bdi>{item.sku}</bdi></span>
                        <span><b>{t('barcode')}</b> {data.primary_barcode ?? '—'}</span>
                        <span><b>{t('itemDetail.type')}</b> {item.item_type}</span>
                        <span><b>{t('items.costing')}</b> {isService ? '—' : (isFifo ? 'FIFO' : t('items.average'))}</span>
                        <span><b>{t('category')}</b> {item.category?.name ?? '—'}</span>
                        <span><b>{t('brand')}</b> {item.brand?.name ?? '—'}</span>
                    </div>
                    <div className="item-hero-actions">
                        <Link to={`/items/${item.id}/edit`} className={`btn btn--sm btn--primary ${gate.allowed ? '' : 'is-disabled'}`}>{t('items.edit')}</Link>
                        <button className="btn btn--sm" onClick={() => setTab('media')}>{t('items.manageMedia')}</button>
                        <Link to="/adjustments/new" className="btn btn--sm">{t('itemDetail.adjustStock')}</Link>
                        <Link to="/transfers/new" className="btn btn--sm">{t('itemDetail.transfer')}</Link>
                    </div>
                </div>
            </div>

            <Tabs tabs={tabs} active={tab} onChange={setTab} />

            {/* ── Overview ── */}
            {tab === 'overview' && (
                <>
                    {lowStock && <div className="banner banner--warn">{t('itemDetail.lowStockMessage', undefined, { available: qty(totals.available), reorder: qty(reorder) })}</div>}
                    <div className="metric-cards">
                        <MetricCard label={t('itemDetail.onHand')} value={qty(totals.onHand)} />
                        <MetricCard label={t('itemDetail.available')} value={qty(totals.available)} tone={lowStock ? 'warn' : undefined} />
                        <MetricCard label={t('itemDetail.reserved')} value={qty(totals.reserved)} />
                        <MetricCard label={t('itemDetail.totalStockValue')} value={money(val?.on_hand_value)} sub={isFifo ? t('itemDetail.fifoBasis') : t('itemDetail.averageBasis')} tone="ok" />
                        <MetricCard label={t('items.purchasePrice')} value={money(item.purchase_price)} />
                        <MetricCard label={t('items.salesPrice')} value={money(item.sales_price)} />
                        <MetricCard label={t('items.reorderPoint')} value={reorder > 0 ? qty(reorder) : '—'} />
                        <MetricCard label={isFifo ? t('itemDetail.fifoValue') : t('itemDetail.avgValue')} value={money(val?.fifo_value_total ?? val?.on_hand_value)} />
                    </div>

                    <div className="overview-grid">
                        <div className="card"><div className="card-head"><h3>{t('itemDetail.stockByWarehouse')}</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setValuationOpen(true)}>{t('itemDetail.viewValuation')}</button></div>
                            <div className="card-body">{valuation.isLoading ? <Skeleton rows={2} /> : <WarehouseCards cards={cards} />}</div></div>
                        <div className="card"><div className="card-head"><h3>{t('itemDetail.recentMovements')}</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setTab('movements')}>{t('itemDetail.seeAll')}</button></div>
                            <div className="card-body">{movements.isLoading ? <Skeleton rows={2} /> : <MovementsTable rows={movementRows.slice(-5).reverse()} onRow={setActiveMovement} />}</div></div>
                    </div>
                </>
            )}

            {/* ── Inventory ── */}
            {tab === 'inventory' && (
                <div className="card"><div className="card-head"><h3>{t('itemDetail.stockAndValuation')}</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setValuationOpen(true)}>{t('itemDetail.fifoLayersValuation')}</button></div>
                    <div className="card-body">{valuation.isLoading ? <Skeleton rows={3} /> : valuation.isError
                        ? <div className="drawer-error">{t('itemDetail.loadStockFailed')}<div><button className="btn btn--sm" onClick={() => valuation.refetch()}>{t('itemDetail.tryAgain')}</button></div></div>
                        : <WarehouseCards cards={cards} onValuation={() => setValuationOpen(true)} />}</div></div>
            )}

            {/* ── Movements ── */}
            {tab === 'movements' && (
                <div className="panel">{movements.isLoading ? <Skeleton rows={4} /> : <MovementsTable rows={movementRows} onRow={setActiveMovement} />}</div>
            )}

            {tab === 'barcodes' && (
                <div className="overview-grid">
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.itemBarcodes')}</h3></div><div className="card-body">
                        {(data.barcodes ?? []).length === 0 ? <EmptyState title={t('itemDetail.noBarcodes')} hint={t('itemDetail.noBarcodesHint')} />
                            : <table className="data-table"><thead><tr><th>{t('itemDetail.code')}</th><th>{t('itemDetail.type')}</th><th></th></tr></thead><tbody>
                                {(data.barcodes ?? []).map((b) => <tr key={b.id}><td>{b.barcode}</td><td>{b.type}</td><td style={{ textAlign: 'right' }}>
                                    {gate.allowed && b.type !== 'primary' && <button className="btn btn--sm" onClick={() => makePrimary(b.id)}>{t('itemDetail.makePrimary')}</button>}
                                    {gate.allowed && <button className="btn btn--sm" onClick={() => removeBarcode(b.id)}>{t('itemDetail.delete')}</button>}
                                </td></tr>)}
                            </tbody></table>}
                        {gate.allowed && <form className="fg2" onSubmit={addBarcode} style={{ marginTop: 12 }}>
                            <input className="input" placeholder={t('itemDetail.scanOrTypeBarcode')} value={newBarcode.barcode} onChange={(e) => setNewBarcode({ ...newBarcode, barcode: e.target.value })} />
                            <select className="input" value={newBarcode.type} onChange={(e) => setNewBarcode({ ...newBarcode, type: e.target.value })}>
                                <option value="internal">{t('itemDetail.internal')}</option><option value="EAN">{t('itemDetail.formatEan', 'EAN')}</option><option value="UPC">{t('itemDetail.formatUpc', 'UPC')}</option><option value="QR">{t('itemDetail.formatQr', 'QR')}</option>
                            </select>
                            <button className="btn btn--primary">{t('itemDetail.addBarcode')}</button>
                        </form>}
                    </div></div>
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.scanLookup')}</h3></div><div className="card-body">
                        <form className="quick-create" onSubmit={lookupBarcode}>
                            <input className="input" autoFocus placeholder={t('itemDetail.scanBarcodeEnter')} value={scanCode} onChange={(e) => setScanCode(e.target.value)} dir="ltr" />
                            <button className="btn btn--primary">{t('itemDetail.lookup')}</button>
                        </form>
                        {scanResult && <div className="banner" style={{ marginTop: 12 }}>
                            {t('itemDetail.scanFound')} <Link to={`/items/${scanResult.item_id}`}>{scanResult.item?.name ?? t('itemDetail.itemNumber', 'Item #:id', { id: scanResult.item_id })}</Link> · <bdi>{scanResult.item?.sku ?? t('itemDetail.noSku')}</bdi>
                        </div>}
                    </div></div>
                </div>
            )}

            {/* ── Media ── */}
            {tab === 'media' && <div className="overview-grid">
                <div className="card"><div className="card-head"><h3>{t('itemDetail.images')}</h3></div><div className="card-body"><ItemImages itemId={item.id} canManage={gate.allowed} /></div></div>
                <div className="card"><div className="card-body"><ItemAttachments itemId={item.id} canManage={gate.allowed} /></div></div>
            </div>}

            {/* ── Details ── */}
            {tab === 'details' && (
                <div className="overview-grid">
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.specifications')}</h3></div><div className="card-body"><dl className="kv">
                        <dt>{t('itemDetail.tracking')}</dt><dd>{t(`tracking.${item.tracking_type}`, item.tracking_type)}</dd>
                        <dt>{t('itemDetail.description')}</dt><dd>{item.description || '—'}</dd>
                        <dt>{t('itemDetail.notes')}</dt><dd>{item.notes || '—'}</dd>
                        <dt>{t('itemDetail.unit')}</dt><dd><bdi>{item.base_unit?.code ?? '—'}</bdi></dd>
                        <dt>{t('itemDetail.weight')}</dt><dd>{item.weight ? qty(item.weight) : '—'}</dd>
                        <dt>{t('itemDetail.dimensions')}</dt><dd>{[item.length, item.width, item.height].filter(Boolean).length ? `${qty(item.length)} × ${qty(item.width)} × ${qty(item.height)}` : '—'}</dd>
                        <dt>{t('itemDetail.package')}</dt><dd>{data.package_recommendation ? `${data.package_recommendation.label}${data.package_recommendation.max_dimensions ? ` · ${data.package_recommendation.max_dimensions}` : ''}` : '—'}</dd>
                    </dl></div></div>
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.commercial')}</h3></div><div className="card-body"><dl className="kv">
                        <dt>{t('itemDetail.preferredSupplier')}</dt><dd><bdi>{item.preferred_supplier_id ? `#${item.preferred_supplier_id}` : '—'}</bdi></dd>
                        <dt>{t('itemDetail.primaryBarcode')}</dt><dd><bdi>{data.primary_barcode ?? '—'}</bdi></dd>
                        <dt>{t('itemDetail.reorderQuantity')}</dt><dd>{item.reorder_qty ? qty(item.reorder_qty) : '—'}</dd>
                        <dt>{t('itemDetail.minMax')}</dt><dd>{item.min_stock || item.max_stock ? `${item.min_stock ? qty(item.min_stock) : '—'} / ${item.max_stock ? qty(item.max_stock) : '—'}` : '—'}</dd>
                        <dt>{t('itemDetail.safetyStock')}</dt><dd>{item.safety_stock ? qty(item.safety_stock) : '—'}</dd>
                        <dt>{t('itemDetail.taxCode')}</dt><dd><bdi>{item.tax_code ?? '—'}</bdi></dd>
                    </dl>
                        {(data.supplier_price_lists ?? []).length === 0 ? <EmptyState title={t('itemDetail.noSupplierPrices')} hint={t('itemDetail.noSupplierPricesHint')} />
                            : <table className="data-table"><thead><tr><th>{t('itemDetail.supplier')}</th><th>{t('sku')}</th><th>{t('itemDetail.cost')}</th><th>{t('itemDetail.minimumQuantity')}</th><th></th></tr></thead><tbody>
                                {data.supplier_price_lists.map((p) => <tr key={p.id}><td>{p.supplier?.name ?? `#${p.supplier_id}`}</td><td><bdi>{p.supplier_sku ?? '—'}</bdi></td><td>{p.unit_cost} <bdi>{p.currency_code ?? ''}</bdi></td><td>{p.minimum_qty}</td><td>{gate.allowed && <button className="btn btn--sm btn--danger" onClick={() => deleteSupplierPrice(p.id)}>{t('itemDetail.delete')}</button>}</td></tr>)}
                            </tbody></table>}
                        {gate.allowed && <form className="fg2" onSubmit={addSupplierPrice} style={{ marginTop: 12 }}>
                            <SupplierPicker value={priceForm.supplier_id} onChange={(v) => setPriceForm({ ...priceForm, supplier_id: v })} />
                            <input className="input" placeholder={t('itemDetail.supplierSku')} value={priceForm.supplier_sku} onChange={(e) => setPriceForm({ ...priceForm, supplier_sku: e.target.value })} dir="ltr" />
                            <MoneyInput value={priceForm.unit_cost} onChange={(v) => setPriceForm({ ...priceForm, unit_cost: v })} />
                            <input className="input" type="number" step="0.0001" min="0" value={priceForm.minimum_qty} onChange={(e) => setPriceForm({ ...priceForm, minimum_qty: e.target.value })} />
                            <button className="btn btn--primary">{t('itemDetail.addSupplierPrice')}</button>
                        </form>}
                    </div></div>
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.variants')}</h3></div><div className="card-body">
                        {(item.variants?.length ?? 0) === 0 ? <EmptyState title={t('itemDetail.noVariants')} hint={t('itemDetail.noVariantsHint')} />
                            : <table className="data-table"><thead><tr><th>{t('sku')}</th><th>{t('itemDetail.attributes')}</th><th>{t('itemDetail.barcode')}</th><th>{t('itemDetail.status')}</th><th></th></tr></thead><tbody>
                                {item.variants.map((v) => <tr key={v.id}><td><bdi>{v.sku}</bdi></td><td>{Object.entries(v.variant_attributes ?? {}).map(([k, val]) => `${k}: ${val}`).join(', ') || '—'}</td><td><bdi>{v.barcode_primary ?? '—'}</bdi></td><td>{t(v.is_active ? 'itemDetail.active' : 'itemDetail.inactive')}</td><td>{gate.allowed && <button className="btn btn--sm btn--danger" onClick={() => deleteVariant(v.id)}>{t('itemDetail.delete')}</button>}</td></tr>)}
                            </tbody></table>}
                        {gate.allowed && <form className="fg2" onSubmit={addVariant} style={{ marginTop: 12 }}>
                            <input className="input" placeholder={t('itemDetail.variantSku')} value={variantForm.sku} onChange={(e) => setVariantForm({ ...variantForm, sku: e.target.value })} required dir="ltr" />
                            <input className="input" placeholder={t('itemDetail.variantOptionPlaceholder')} value={variantForm.option} onChange={(e) => setVariantForm({ ...variantForm, option: e.target.value })} />
                            <input className="input" placeholder={t('itemDetail.variantValuePlaceholder')} value={variantForm.value} onChange={(e) => setVariantForm({ ...variantForm, value: e.target.value })} />
                            <input className="input" placeholder={t('itemDetail.variantBarcode')} value={variantForm.barcode_primary} onChange={(e) => setVariantForm({ ...variantForm, barcode_primary: e.target.value })} dir="ltr" />
                            <button className="btn btn--primary">{t('itemDetail.addVariant')}</button>
                        </form>}
                    </div></div>
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.labels')}</h3><span className="spacer" /><button className="btn btn--sm" onClick={loadLabels}>{t('itemDetail.printLabels')}</button></div><div className="card-body">
                        {!labels ? <EmptyState title={t('itemDetail.noLabelPreview')} hint={t('itemDetail.noLabelPreviewHint')} /> : <div className="label-sheet">
                            {(labels.labels ?? []).map((l) => <div className="label-card" key={l.barcode}><strong>{l.name}</strong><span>{l.sku}</span>{l.barcode_svg && <div className="label-barcode" dangerouslySetInnerHTML={{ __html: l.barcode_svg }} />}<code>{l.barcode}</code><span className="muted">{l.type}</span></div>)}
                        </div>}
                    </div></div>
                    <div className="card"><div className="card-head"><h3>{t('itemDetail.audit')}</h3></div><div className="card-body"><dl className="kv">
                        <dt>{t('itemDetail.created')}</dt><dd>{item.created_at ?? '—'}</dd>
                        <dt>{t('itemDetail.updated')}</dt><dd>{item.updated_at ?? '—'}</dd>
                    </dl><span className="field-hint">{t('itemDetail.auditHint')}</span></div></div>
                </div>
            )}

            <ValuationDrawer itemId={id} open={valuationOpen} onClose={() => setValuationOpen(false)} />
            <MovementDrawer movement={activeMovement} open={!!activeMovement} onClose={() => setActiveMovement(null)} />
        </section>
    );
}
