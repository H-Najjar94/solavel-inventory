import React from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

// Real, zeroed dashboard for a live tenant with no data yet. NEVER mock/sample —
// the dashboard only ever shows this org's actual numbers.
const EMPTY_DASHBOARD = {
    inventory_value: 0, total_skus: 0, active_items: 0, low_stock: 0, out_of_stock: 0,
    dead_stock: 0, movements_today: 0, pending_pos: 0, pending_grns: 0, pending_transfers: 0,
    pending_counts: 0, warehouses: 0, pending_sales_orders: 0, reserved_stock_qty: 0,
    awaiting_pick: 0, awaiting_pack: 0, awaiting_ship: 0, shipments_today: 0,
    top_moving: [], recent_movements: [], generated_at: null,
};

const n = (v) => Number(v || 0);
const num = (v) => n(v).toLocaleString();
const money = (v) => `$${n(v).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;

const TONE = { good: '#2f7a4f', warn: '#c97f12', danger: '#d64545', default: 'var(--ink,#222)' };

// ── Primary KPI (big, prominent) ─────────────────────────────────────
function Kpi({ label, value, to, tone = 'default', sub, icon }) {
    return (
        <Link to={to} className="dash-kpi" style={{
            display: 'flex', flexDirection: 'column', gap: 6, padding: '18px 20px',
            background: 'var(--surface-1,#fff)', border: '1px solid var(--line-soft,#e6e1d8)',
            borderRadius: 16, textDecoration: 'none', color: 'inherit', minWidth: 0,
        }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                {icon && <i className={`fa-solid ${icon}`} style={{ color: '#e09921', fontSize: 13 }} aria-hidden="true" />}
                <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ink-soft,#8a8478)' }}>{label}</span>
            </div>
            <span style={{ fontSize: 30, fontWeight: 800, lineHeight: 1.1, color: TONE[tone], letterSpacing: '-.02em' }}>{value}</span>
            {sub != null && <span style={{ fontSize: 12, color: 'var(--ink-soft,#8a8478)' }}>{sub}</span>}
        </Link>
    );
}

// ── Compact operational stat (small) ─────────────────────────────────
function OpStat({ label, value, to, alert }) {
    const v = n(value);
    return (
        <Link to={to} className="dash-op" style={{
            display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10,
            padding: '10px 12px', borderRadius: 10, textDecoration: 'none', color: 'inherit',
            background: v > 0 && alert ? 'rgba(214,69,69,0.06)' : 'transparent',
        }}>
            <span style={{ fontSize: 13, color: 'var(--ink-soft,#6b6357)' }}>{label}</span>
            <span style={{
                fontSize: 15, fontWeight: 700, minWidth: 28, textAlign: 'right',
                color: v > 0 && alert ? TONE.danger : 'var(--ink,#222)',
            }}>{typeof value === 'number' ? num(value) : value}</span>
        </Link>
    );
}

function OpsGroup({ title, icon, children }) {
    return (
        <div className="panel" style={{ padding: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '13px 16px', borderBottom: '1px solid var(--line-soft,#eee)' }}>
                <i className={`fa-solid ${icon}`} style={{ color: '#e09921', fontSize: 13 }} aria-hidden="true" />
                <h2 style={{ margin: 0, fontSize: 14 }}>{title}</h2>
            </div>
            <div style={{ padding: 6 }}>{children}</div>
        </div>
    );
}

export default function DashboardPage() {
    const qc = useQueryClient();
    // Real data only. No mock/sample fallback — an empty workspace shows real zeros.
    const { data, isFetching } = useApiQuery(['dashboard'], api.dashboard, { fallback: EMPTY_DASHBOARD });
    const d = data ?? EMPTY_DASHBOARD;
    const integ = useApiQuery(['integration-status'], api.integrationStatus, { fallback: null });
    const si = integ.data;

    // Only surface alerts that actually need attention.
    const alerts = [
        n(d.out_of_stock) > 0 && { label: `${num(d.out_of_stock)} out of stock`, to: '/reports', tone: 'danger', icon: 'fa-circle-xmark' },
        n(d.low_stock) > 0 && { label: `${num(d.low_stock)} low on stock`, to: '/reports', tone: 'warn', icon: 'fa-triangle-exclamation' },
        n(d.dead_stock) > 0 && { label: `${num(d.dead_stock)} dead stock`, to: '/reports', tone: 'warn', icon: 'fa-box' },
        n(d.active_recalls) > 0 && { label: `${num(d.active_recalls)} active recall(s)`, to: '/recalls', tone: 'danger', icon: 'fa-bullhorn' },
        n(d.recalled_lots) > 0 && { label: `${num(d.recalled_lots)} recalled lot(s)`, to: '/traceability/lots?status=recalled', tone: 'danger', icon: 'fa-barcode' },
        n(d.expired_lots) > 0 && { label: `${num(d.expired_lots)} expired lot(s)`, to: '/traceability/lots?status=expired', tone: 'warn', icon: 'fa-clock' },
        n(d.expiring_lots_30d) > 0 && { label: `${num(d.expiring_lots_30d)} expiring in 30d`, to: '/traceability/lots?expiring=1', tone: 'warn', icon: 'fa-hourglass-half' },
        n(d.quarantined_lots) > 0 && { label: `${num(d.quarantined_lots)} quarantined lot(s)`, to: '/traceability/lots?status=quarantined', tone: 'warn', icon: 'fa-ban' },
    ].filter(Boolean);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Dashboard' }]} />
            <header className="page-head">
                <h1>Dashboard</h1>
                <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 10 }}>
                    {d.generated_at && <span className="muted">Updated {d.generated_at}</span>}
                    <button className="btn btn--sm" disabled={isFetching} onClick={() => qc.invalidateQueries({ queryKey: ['dashboard'] })}>
                        {isFetching ? 'Refreshing…' : 'Refresh'}
                    </button>
                </div>
            </header>

            {/* Primary KPIs */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14, marginTop: 4 }}>
                <Kpi label="Inventory Value" value={money(d.inventory_value)} to="/reports" tone="good" icon="fa-coins" sub="on-hand at cost" />
                <Kpi label="Active SKUs" value={num(d.active_items)} to="/items" icon="fa-tags" sub={`${num(d.total_skus)} total`} />
                <Kpi label="Low Stock" value={num(d.low_stock)} to="/reports" tone={n(d.low_stock) > 0 ? 'warn' : 'default'} icon="fa-triangle-exclamation" sub="below reorder point" />
                <Kpi label="Out of Stock" value={num(d.out_of_stock)} to="/reports" tone={n(d.out_of_stock) > 0 ? 'danger' : 'default'} icon="fa-circle-xmark" sub="needs restocking" />
            </div>

            {/* Needs attention */}
            {alerts.length > 0 && (
                <div className="panel" style={{ marginTop: 16 }}>
                    <h2 style={{ fontSize: 14, marginBottom: 10 }}><i className="fa-solid fa-bell" style={{ color: '#e09921', marginRight: 6 }} /> Needs attention</h2>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                        {alerts.map((a) => (
                            <Link key={a.label} to={a.to} style={{
                                display: 'inline-flex', alignItems: 'center', gap: 7, padding: '7px 12px',
                                borderRadius: 999, textDecoration: 'none', fontSize: 13, fontWeight: 600, color: TONE[a.tone],
                                background: a.tone === 'danger' ? 'rgba(214,69,69,0.08)' : 'rgba(201,127,18,0.10)',
                                border: `1px solid ${a.tone === 'danger' ? 'rgba(214,69,69,0.25)' : 'rgba(201,127,18,0.28)'}`,
                            }}>
                                <i className={`fa-solid ${a.icon}`} aria-hidden="true" /> {a.label}
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            {/* Operations, grouped */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 14, marginTop: 16 }}>
                <OpsGroup title="Purchasing" icon="fa-truck-fast">
                    <OpStat label="Pending POs" value={d.pending_pos} to="/purchase-orders" alert />
                    <OpStat label="Pending GRNs" value={d.pending_grns} to="/goods-receipts" alert />
                </OpsGroup>
                <OpsGroup title="Fulfillment" icon="fa-box-open">
                    <OpStat label="Open Sales Orders" value={d.pending_sales_orders} to="/sales-orders" />
                    <OpStat label="Awaiting Pick" value={d.awaiting_pick} to="/pick-lists" alert />
                    <OpStat label="Awaiting Pack" value={d.awaiting_pack} to="/packs" alert />
                    <OpStat label="Awaiting Ship" value={d.awaiting_ship} to="/shipments" alert />
                    <OpStat label="Shipped today" value={d.shipments_today} to="/shipments" />
                </OpsGroup>
                <OpsGroup title="Stock operations" icon="fa-arrows-rotate">
                    <OpStat label="Movements today" value={d.movements_today} to="/ledger" />
                    <OpStat label="Pending Transfers" value={d.pending_transfers} to="/transfers" alert />
                    <OpStat label="Pending Counts" value={d.pending_counts} to="/counts" alert />
                    <OpStat label="Reserved stock" value={num(d.reserved_stock_qty)} to="/reports" />
                    <OpStat label="Warehouses" value={d.warehouses} to="/warehouses" />
                </OpsGroup>
            </div>

            {/* SolaBooks sync */}
            {si && (
                <Link to="/settings/solabooks" className="panel panel--link" style={{ display: 'block', textDecoration: 'none', color: 'inherit', marginTop: 16 }}>
                    <h2 style={{ fontSize: 14 }}>
                        <i className="fa-solid fa-rotate" style={{ color: '#e09921', marginRight: 6 }} />
                        SolaBooks sync <span className={`badge ${si.health === 'healthy' ? 'badge--live' : si.health === 'disconnected' ? 'badge--muted' : 'badge--warn'}`}>{si.health}</span>
                    </h2>
                    <p className="muted" style={{ margin: 0 }}>
                        Pending: {si.events?.pending ?? 0} · Failed: {si.events?.failed ?? 0} ·
                        Mapping {si.mapping_completeness_pct ?? 0}% · Awaiting sync: {si.documents_awaiting_sync ?? 0}
                    </p>
                </Link>
            )}

            {/* Activity */}
            <div className="dash-cols" style={{ marginTop: 16 }}>
                <div className="panel">
                    <h2 style={{ fontSize: 14 }}><i className="fa-solid fa-arrow-trend-up" style={{ color: '#e09921', marginRight: 6 }} /> Top moving items <span className="muted">(30d)</span></h2>
                    {(d.top_moving ?? []).length === 0 ? <EmptyState title="No movement yet" /> : (
                        <table className="data-table"><thead><tr><th>SKU</th><th>Item</th><th>Qty out</th></tr></thead>
                            <tbody>{d.top_moving.map((t, i) => <tr key={i}><td>{t.sku}</td><td>{t.name}</td><td>{t.qty}</td></tr>)}</tbody></table>
                    )}
                </div>
                <div className="panel">
                    <h2 style={{ fontSize: 14 }}><i className="fa-solid fa-clock-rotate-left" style={{ color: '#e09921', marginRight: 6 }} /> Recent movements</h2>
                    {(d.recent_movements ?? []).length === 0 ? <EmptyState title="No recent movements" /> : (
                        <ul className="activity-list">
                            {d.recent_movements.map((m) => (
                                <li key={m.id}>{m.direction === 'in' ? '▲' : '▼'} item #{m.item_id} · {m.quantity} @ {m.unit_cost} <span className="muted">{m.moved_at}</span></li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </section>
    );
}
