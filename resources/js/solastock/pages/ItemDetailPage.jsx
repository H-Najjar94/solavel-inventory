import React, { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, Tabs, StatusBadge, EmptyState, Drawer, MetricCard, Badge } from '../components/ui.jsx';
import ItemImages from '../components/ItemImages.jsx';

// ── helpers ──
const num = (v) => Number(v ?? 0);
const money = (v) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 4 });

// Read-only valuation drawer (FIFO layer stack + FIFO-vs-average + reconciliation).
function ValuationDrawer({ itemId, open, onClose }) {
    const { data: v, isLoading, isError, error, refetch } = useApiQuery(
        ['item-valuation', itemId], () => api.itemValuation(itemId), { enabled: open });
    const isFifo = v?.costing_method === 'fifo';
    const hasStock = (v?.warehouses ?? []).length > 0;

    return (
        <Drawer open={open} onClose={onClose} title="Stock valuation"
            subtitle={v ? `${v.sku} · ${isFifo ? 'FIFO' : 'Average'} costing` : ''} width={500}>
            {isLoading && <Skeleton rows={5} />}
            {isError && (
                <div className="drawer-error">Couldn’t load valuation{error?.message ? `: ${error.message}` : '.'}
                    <div><button className="btn btn--sm" onClick={() => refetch()}>Try again</button></div></div>
            )}
            {v && !isError && (
                <>
                    <div className="drawer-note">
                        {isFifo
                            ? <>This item is valued <strong>FIFO</strong> — each batch keeps its own cost and the <strong>oldest stock is counted as sold first</strong>.</>
                            : <>This item is valued at <strong>weighted average</strong> cost.</>}
                    </div>
                    {hasStock && (
                        <div className="wh-card-grid" style={{ marginBottom: 18 }}>
                            <MetricCard label="On-hand value" value={money(v.on_hand_value)} sub={isFifo ? 'FIFO basis' : 'average basis'} tone="ok" />
                            <MetricCard label={isFifo ? 'Same stock, average' : 'Same stock, FIFO'} value={money(isFifo ? v.average_value_total : v.fifo_value_total)} sub="for comparison" />
                        </div>
                    )}
                    {!hasStock && <EmptyState title="No stock to value yet" hint="Once you receive or open stock, its value and FIFO layers appear here." />}
                    {(v.warehouses ?? []).map((w) => (
                        <div className="card wh-card" key={w.warehouse_id} style={{ marginBottom: 14 }}>
                            <div className="wh-card-head">
                                <span className="wh-card-name">{w.warehouse_name ?? `Warehouse #${w.warehouse_id}`}</span>
                                {w.warehouse_code && <span className="wh-card-code">{w.warehouse_code}</span>}
                                {w.qty_reconciled ? <Badge tone="ok">Reconciled</Badge> : <Badge tone="danger">Needs review</Badge>}
                            </div>
                            <div className="wh-card-grid" style={{ marginBottom: 10 }}>
                                <div><div className="wh-stat-label">On hand</div><div className="wh-stat-val">{qty(w.on_hand_qty)}</div></div>
                                <div><div className="wh-stat-label">Value {isFifo ? '(FIFO)' : ''}</div><div className="wh-stat-val">{money(isFifo ? w.fifo_value : w.average_value)}</div></div>
                                <div><div className="wh-stat-label">Avg cost / unit</div><div className="wh-stat-val">{money(w.average_cost)}</div></div>
                                <div><div className="wh-stat-label">{isFifo ? 'Average basis' : 'FIFO basis'}</div><div className="wh-stat-val">{money(isFifo ? w.average_value : w.fifo_value)}</div></div>
                            </div>
                            {isFifo && (w.layers?.length > 0 ? (
                                <>
                                    <div className="section-label">Cost layers — oldest first</div>
                                    <div className="layer-list">
                                        {w.layers.map((l) => (
                                            <div className="layer-row" key={l.id}>
                                                <div className="layer-row-main">{qty(l.remaining_qty)} left × {money(l.unit_cost)}</div>
                                                <div className="layer-row-val">{money(l.layer_value)}</div>
                                                <div className="layer-row-meta">received {l.received_at ?? '—'}{l.lot_id ? ` · lot #${l.lot_id}` : ''}</div>
                                                <div className="layer-row-ref">{l.source_ledger_id ? `from movement #${l.source_ledger_id}` : ''}</div>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            ) : <div className="layer-row-meta">No remaining cost layers in this warehouse.</div>)}
                            <div className="recon-line">
                                {w.qty_reconciled ? <>✓ Cost layers add up to the on-hand quantity.</> : <>⚠ Cost layers don’t add up to on-hand here — worth a look.</>}
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
        <Drawer open={open} onClose={onClose} title="Movement detail"
            subtitle={`${movement.moved_at ?? ''} · ${movement.warehouse_name ?? `WH #${movement.warehouse_id}`}`} width={480}>
            <dl className="mv-kv">
                <dt>Type</dt><dd><Badge tone={isOut ? 'warn' : 'ok'}>{isOut ? 'Stock out' : 'Stock in'}</Badge></dd>
                <dt>Warehouse</dt><dd>{movement.warehouse_name ?? '—'} {movement.warehouse_code && <span className="wh-card-code">{movement.warehouse_code}</span>}</dd>
                <dt>Quantity</dt><dd>{qty(movement.quantity)}</dd>
                <dt>Unit cost</dt><dd>{movement.unit_cost ? money(movement.unit_cost) : '—'}</dd>
                <dt>Total cost</dt><dd>{movement.total_cost ? money(movement.total_cost) : '—'}</dd>
                <dt>On hand after</dt><dd>{movement.balance_qty_after ? qty(movement.balance_qty_after) : '—'}</dd>
                <dt>Value after</dt><dd>{movement.balance_value_after ? money(movement.balance_value_after) : '—'}</dd>
                <dt>Source</dt><dd>{movement.source_label ?? '—'}{movement.source_id ? ` #${movement.source_id}` : ''}</dd>
            </dl>
            {isOut && (
                <div style={{ marginTop: 18 }}>
                    <div className="section-label">Which stock this used</div>
                    <div className="drawer-note">An outbound FIFO movement draws from the <strong>oldest cost layers first</strong>.</div>
                    {isLoading && <Skeleton rows={2} />}
                    {isError && <div className="drawer-error">Couldn’t load consumed layers.<div><button className="btn btn--sm" onClick={() => refetch()}>Try again</button></div></div>}
                    {consumed && !isError && consumed.consumed_layer_count === 0 && <div className="layer-row-meta">Average-costed — no specific layers consumed.</div>}
                    {consumed && !isError && consumed.consumed_layer_count > 0 && (
                        <>
                            <div className="layer-list">
                                {consumed.layers.map((l, i) => (
                                    <div className="layer-row" key={i}>
                                        <div className="layer-row-main">{qty(l.qty)} × {money(l.unit_cost)}</div>
                                        <div className="layer-row-val">{money(l.total_value)}</div>
                                        <div className="layer-row-meta">layer #{l.cost_layer_id}{l.source_layer?.received_at ? ` · received ${l.source_layer.received_at}` : ''}</div>
                                        <div className="layer-row-ref">{l.consumed_at ?? ''}</div>
                                    </div>
                                ))}
                            </div>
                            <div className="wh-card-foot"><span className="wh-stat-label">Total cost of stock used</span>
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
    if (!rows.length) return <EmptyState title="No movements yet" hint="Stock receipts, issues, transfers and adjustments will appear here." />;
    return (
        <table className="data-table">
            <thead><tr><th>Date</th><th>Dir</th><th>Qty</th><th>Unit cost</th><th>Running qty</th><th>Warehouse</th><th>Source</th></tr></thead>
            <tbody>{rows.map((m) => (
                <tr key={m.id} className="clickable-row" onClick={() => onRow(m)} title="View movement detail">
                    <td>{m.moved_at}</td><td><Badge tone={m.direction === 'out' ? 'warn' : 'ok'}>{m.direction}</Badge></td>
                    <td>{qty(m.quantity)}</td><td>{m.unit_cost ? money(m.unit_cost) : '—'}</td>
                    <td>{m.balance_qty_after ? qty(m.balance_qty_after) : '—'}</td><td>{m.warehouse_name ?? `#${m.warehouse_id}`}</td>
                    <td>{m.source_label ?? '—'}{m.source_id ? ` #${m.source_id}` : ''}</td>
                </tr>
            ))}</tbody>
        </table>
    );
}

// Warehouse stock cards (shared by Overview + Inventory).
function WarehouseCards({ cards, onValuation }) {
    if (!cards.length) return <EmptyState title="No stock yet" hint="This item isn’t held in any warehouse." />;
    return (
        <div className="wh-cards">
            {cards.map((w) => (
                <div className="card wh-card" key={w.warehouse_id}>
                    <div className="wh-card-head">
                        <span className="wh-card-name">{w.warehouse_name ?? `Warehouse #${w.warehouse_id}`}</span>
                        {w.warehouse_code && <span className="wh-card-code">{w.warehouse_code}</span>}
                        {w.qty_reconciled === false && <Badge tone="danger">qty mismatch</Badge>}
                    </div>
                    <div className="wh-card-grid">
                        <div><div className="wh-stat-label">On hand</div><div className="wh-stat-val">{qty(w.on_hand_qty)}</div></div>
                        <div><div className="wh-stat-label">Available</div><div className="wh-stat-val">{qty(w.available_qty)}</div></div>
                        <div><div className="wh-stat-label">Reserved</div><div className="wh-stat-val">{qty(w.reserved_qty)}</div></div>
                        <div><div className="wh-stat-label">Value</div><div className="wh-stat-val">{money(w.fifo_value ?? w.average_value)}</div></div>
                    </div>
                    {onValuation && <div className="wh-card-foot"><button className="btn btn--sm" onClick={onValuation}>View valuation</button></div>}
                </div>
            ))}
        </div>
    );
}

export default function ItemDetailPage() {
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const [tab, setTab] = useState('overview');
    const [valuationOpen, setValuationOpen] = useState(false);
    const [activeMovement, setActiveMovement] = useState(null);

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
                <Breadcrumbs items={[{ label: 'Items', to: '/items' }, { label: 'Not found' }]} />
                <EmptyState title="Item unavailable" hint="Select a tenant to load real data." />
            </section>
        );
    }

    const isFifo = (item.costing_method ?? 'average') === 'fifo';
    const isService = item.item_type === 'service';
    const reorder = num(item.reorder_point);
    const lowStock = !isService && reorder > 0 && totals.available <= reorder;

    const tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'inventory', label: 'Inventory' },
        { key: 'movements', label: 'Movements' },
        { key: 'media', label: 'Media' },
        { key: 'details', label: 'Details' },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Items', to: '/items' }, { label: item.name }]} />

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
                        {lowStock && <Badge tone="warn">Low stock</Badge>}
                    </div>
                    <div className="item-hero-meta">
                        <span><b>SKU</b> {item.sku}</span>
                        <span><b>Barcode</b> {data.primary_barcode ?? '—'}</span>
                        <span><b>Type</b> {item.item_type}</span>
                        <span><b>Costing</b> {isService ? '—' : (isFifo ? 'FIFO' : 'Average')}</span>
                        <span><b>Category</b> {item.category?.name ?? '—'}</span>
                        <span><b>Brand</b> {item.brand?.name ?? '—'}</span>
                    </div>
                    <div className="item-hero-actions">
                        <Link to={`/items/${item.id}/edit`} className={`btn btn--sm btn--primary ${gate.allowed ? '' : 'is-disabled'}`}>Edit</Link>
                        <button className="btn btn--sm" onClick={() => setTab('media')}>Manage images</button>
                        <Link to="/adjustments/new" className="btn btn--sm">Adjust stock</Link>
                        <Link to="/transfers/new" className="btn btn--sm">Transfer</Link>
                    </div>
                </div>
            </div>

            <Tabs tabs={tabs} active={tab} onChange={setTab} />

            {/* ── Overview ── */}
            {tab === 'overview' && (
                <>
                    {lowStock && <div className="banner banner--warn">Available stock ({qty(totals.available)}) is at or below the reorder point ({qty(reorder)}).</div>}
                    <div className="metric-cards">
                        <MetricCard label="On hand" value={qty(totals.onHand)} />
                        <MetricCard label="Available" value={qty(totals.available)} tone={lowStock ? 'warn' : undefined} />
                        <MetricCard label="Reserved" value={qty(totals.reserved)} />
                        <MetricCard label="Total stock value" value={money(val?.on_hand_value)} sub={isFifo ? 'FIFO basis' : 'average basis'} tone="ok" />
                        <MetricCard label="Purchase price" value={money(item.purchase_price)} />
                        <MetricCard label="Sales price" value={money(item.sales_price)} />
                        <MetricCard label="Reorder point" value={reorder > 0 ? qty(reorder) : '—'} />
                        <MetricCard label={isFifo ? 'FIFO value' : 'Avg value'} value={money(val?.fifo_value_total ?? val?.on_hand_value)} />
                    </div>

                    <div className="overview-grid">
                        <div className="card"><div className="card-head"><h3>Stock by warehouse</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setValuationOpen(true)}>View valuation</button></div>
                            <div className="card-body">{valuation.isLoading ? <Skeleton rows={2} /> : <WarehouseCards cards={cards} />}</div></div>
                        <div className="card"><div className="card-head"><h3>Recent movements</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setTab('movements')}>See all</button></div>
                            <div className="card-body">{movements.isLoading ? <Skeleton rows={2} /> : <MovementsTable rows={movementRows.slice(-5).reverse()} onRow={setActiveMovement} />}</div></div>
                    </div>
                </>
            )}

            {/* ── Inventory ── */}
            {tab === 'inventory' && (
                <div className="card"><div className="card-head"><h3>Stock &amp; valuation</h3><span className="spacer" /><button className="btn btn--sm" onClick={() => setValuationOpen(true)}>FIFO layers &amp; valuation</button></div>
                    <div className="card-body">{valuation.isLoading ? <Skeleton rows={3} /> : valuation.isError
                        ? <div className="drawer-error">Couldn’t load stock &amp; valuation.<div><button className="btn btn--sm" onClick={() => valuation.refetch()}>Try again</button></div></div>
                        : <WarehouseCards cards={cards} onValuation={() => setValuationOpen(true)} />}</div></div>
            )}

            {/* ── Movements ── */}
            {tab === 'movements' && (
                <div className="panel">{movements.isLoading ? <Skeleton rows={4} /> : <MovementsTable rows={movementRows} onRow={setActiveMovement} />}</div>
            )}

            {/* ── Media ── */}
            {tab === 'media' && <div className="panel"><ItemImages itemId={item.id} canManage={gate.allowed} /></div>}

            {/* ── Details ── */}
            {tab === 'details' && (
                <div className="overview-grid">
                    <div className="card"><div className="card-head"><h3>Specifications</h3></div><div className="card-body"><dl className="kv">
                        <dt>Tracking</dt><dd>{item.tracking_type}</dd>
                        <dt>Description</dt><dd>{item.description || '—'}</dd>
                        <dt>Notes</dt><dd>{item.notes || '—'}</dd>
                        <dt>Unit</dt><dd>{item.base_unit?.code ?? '—'}</dd>
                    </dl></div></div>
                    <div className="card"><div className="card-head"><h3>Commercial</h3></div><div className="card-body"><dl className="kv">
                        <dt>Preferred supplier</dt><dd>{item.preferred_supplier_id ? `#${item.preferred_supplier_id}` : '—'}</dd>
                        <dt>Primary barcode</dt><dd>{data.primary_barcode ?? '—'}</dd>
                        <dt>Reorder qty</dt><dd>{item.reorder_qty ? qty(item.reorder_qty) : '—'}</dd>
                        <dt>Tax code</dt><dd>{item.tax_code ?? '—'}</dd>
                    </dl></div></div>
                    <div className="card"><div className="card-head"><h3>Variants</h3></div><div className="card-body">
                        {(item.variants?.length ?? 0) === 0 ? <EmptyState title="No variants" hint="This item has no variants." />
                            : <ul className="plain-list">{item.variants.map((v) => <li key={v.id}>{v.name ?? v.sku ?? `Variant #${v.id}`}</li>)}</ul>}
                    </div></div>
                    <div className="card"><div className="card-head"><h3>Audit</h3></div><div className="card-body"><dl className="kv">
                        <dt>Created</dt><dd>{item.created_at ?? '—'}</dd>
                        <dt>Updated</dt><dd>{item.updated_at ?? '—'}</dd>
                    </dl><span className="field-hint">Full create/update history is recorded in the inventory audit log.</span></div></div>
                </div>
            )}

            <ValuationDrawer itemId={id} open={valuationOpen} onClose={() => setValuationOpen(false)} />
            <MovementDrawer movement={activeMovement} open={!!activeMovement} onClose={() => setActiveMovement(null)} />
        </section>
    );
}
