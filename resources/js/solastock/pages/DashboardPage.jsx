import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { useCan } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';
import { t } from '../i18n/index.js';

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
const DEFAULT_LAYOUT = [
    { key: 'kpis', visible: true },
    { key: 'alerts', visible: true },
    { key: 'operations', visible: true },
    { key: 'integration', visible: true },
    { key: 'activity', visible: true },
];

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
    const toast = useToast();
    const can = useCan();
    const canViewIntegration = can('inventory.integration.view');
    // Real data only. No mock/sample fallback — an empty workspace shows real zeros.
    const { data, isFetching } = useApiQuery(['dashboard'], api.dashboard, { fallback: EMPTY_DASHBOARD });
    const layoutQuery = useApiQuery(['dashboard-layout'], api.getDashboardLayout, { fallback: { layout: null } });
    const d = data ?? EMPTY_DASHBOARD;
    const integ = useApiQuery(['integration-status'], api.integrationStatus, { fallback: null, enabled: canViewIntegration });
    const si = integ.data;
    const [customizing, setCustomizing] = useState(false);
    const [layout, setLayout] = useState(DEFAULT_LAYOUT);

    useEffect(() => {
        const saved = layoutQuery.data?.layout;
        if (Array.isArray(saved) && saved.length > 0) {
            const byKey = new Map(DEFAULT_LAYOUT.map((w) => [w.key, w]));
            const merged = saved
                .filter((w) => byKey.has(w.key))
                .map((w) => ({ ...byKey.get(w.key), ...w }));
            const missing = DEFAULT_LAYOUT.filter((w) => !merged.some((m) => m.key === w.key));
            setLayout([...merged, ...missing]);
        }
    }, [layoutQuery.data]);

    // Only surface alerts that actually need attention.
    const alerts = [
        n(d.out_of_stock) > 0 && { label: t('dashboard.alert.out', undefined, { count: num(d.out_of_stock) }), to: '/reports', tone: 'danger', icon: 'fa-circle-xmark' },
        n(d.low_stock) > 0 && { label: t('dashboard.alert.low', undefined, { count: num(d.low_stock) }), to: '/reports', tone: 'warn', icon: 'fa-triangle-exclamation' },
        n(d.dead_stock) > 0 && { label: t('dashboard.alert.dead', undefined, { count: num(d.dead_stock) }), to: '/reports', tone: 'warn', icon: 'fa-box' },
        n(d.active_recalls) > 0 && { label: t('dashboard.alert.recalls', undefined, { count: num(d.active_recalls) }), to: '/recalls', tone: 'danger', icon: 'fa-bullhorn' },
        n(d.recalled_lots) > 0 && { label: t('dashboard.alert.recalledLots', undefined, { count: num(d.recalled_lots) }), to: '/traceability/lots?status=recalled', tone: 'danger', icon: 'fa-barcode' },
        n(d.expired_lots) > 0 && { label: t('dashboard.alert.expiredLots', undefined, { count: num(d.expired_lots) }), to: '/traceability/lots?status=expired', tone: 'warn', icon: 'fa-clock' },
        n(d.expiring_lots_30d) > 0 && { label: t('dashboard.alert.expiringLots', undefined, { count: num(d.expiring_lots_30d) }), to: '/traceability/lots?expiring=1', tone: 'warn', icon: 'fa-hourglass-half' },
        n(d.quarantined_lots) > 0 && { label: t('dashboard.alert.quarantinedLots', undefined, { count: num(d.quarantined_lots) }), to: '/traceability/lots?status=quarantined', tone: 'warn', icon: 'fa-ban' },
    ].filter(Boolean);
    const serverAlerts = d.alerts ?? [];

    function updateLayout(key, patch) {
        setLayout((rows) => rows.map((row) => row.key === key ? { ...row, ...patch } : row));
    }

    function moveLayout(key, delta) {
        setLayout((rows) => {
            const next = [...rows];
            const i = next.findIndex((row) => row.key === key);
            const j = i + delta;
            if (i < 0 || j < 0 || j >= next.length) return rows;
            [next[i], next[j]] = [next[j], next[i]];
            return next;
        });
    }

    async function saveLayout() {
        try {
            await api.saveDashboardLayout(layout);
            await qc.invalidateQueries({ queryKey: ['dashboard-layout'] });
            setCustomizing(false);
            toast.push(t('dashboard.layoutSaved'), 'success');
        } catch (e) {
            toast.push(e.message || t('dashboard.layoutSaveFailed'), 'error');
        }
    }

    async function ackAlert(id) {
        try {
            await api.acknowledgeDashboardAlert(id);
            await qc.invalidateQueries({ queryKey: ['dashboard'] });
            toast.push(t('dashboard.alertAcknowledged'), 'success');
        } catch (e) {
            toast.push(e.message || t('dashboard.alertAckFailed'), 'error');
        }
    }

    const sections = {
        kpis: (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14, marginTop: 4 }}>
                <Kpi label={t('dashboard.inventoryValue')} value={money(d.inventory_value)} to="/reports" tone="good" icon="fa-coins" sub={t('dashboard.onHandCost')} />
                <Kpi label={t('dashboard.activeSkus')} value={num(d.active_items)} to="/items" icon="fa-tags" sub={t('dashboard.totalSkus', undefined, { count: num(d.total_skus) })} />
                <Kpi label={t('dashboard.lowStock')} value={num(d.low_stock)} to="/reports" tone={n(d.low_stock) > 0 ? 'warn' : 'default'} icon="fa-triangle-exclamation" sub={t('dashboard.belowReorder')} />
                <Kpi label={t('dashboard.outOfStock')} value={num(d.out_of_stock)} to="/reports" tone={n(d.out_of_stock) > 0 ? 'danger' : 'default'} icon="fa-circle-xmark" sub={t('dashboard.needsRestocking')} />
            </div>
        ),
        alerts: (alerts.length > 0 || serverAlerts.length > 0) && (
            <div className="panel" style={{ marginTop: 16 }}>
                <h2 style={{ fontSize: 14, marginBottom: 10 }}><i className="fa-solid fa-bell" style={{ color: '#e09921', marginInlineEnd: 6 }} /> {t('dashboard.needsAttention')}</h2>
                {serverAlerts.length > 0 && <div style={{ display: 'grid', gap: 8, marginBottom: 10 }}>
                    {serverAlerts.map((a) => (
                        <div key={a.id} className="banner banner--warn" style={{ display: 'flex', gap: 10, alignItems: 'center', justifyContent: 'space-between' }}>
                            <span><strong>{a.title}</strong> · {a.message}</span>
                            {a.status === 'open' && <button className="btn btn--sm" onClick={() => ackAlert(a.id)}>{t('dashboard.acknowledge')}</button>}
                        </div>
                    ))}
                </div>}
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
        ),
        operations: (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 14, marginTop: 16 }}>
                <OpsGroup title={t('dashboard.purchasing')} icon="fa-truck-fast">
                    <OpStat label={t('dashboard.pendingPos')} value={d.pending_pos} to="/purchase-orders" alert />
                    <OpStat label={t('dashboard.pendingGrns')} value={d.pending_grns} to="/goods-receipts" alert />
                </OpsGroup>
                <OpsGroup title={t('dashboard.fulfillment')} icon="fa-box-open">
                    <OpStat label={t('dashboard.openSalesOrders')} value={d.pending_sales_orders} to="/sales-orders" />
                    <OpStat label={t('dashboard.awaitingPick')} value={d.awaiting_pick} to="/pick-lists" alert />
                    <OpStat label={t('dashboard.awaitingPack')} value={d.awaiting_pack} to="/packs" alert />
                    <OpStat label={t('dashboard.awaitingShip')} value={d.awaiting_ship} to="/shipments" alert />
                    <OpStat label={t('dashboard.shippedToday')} value={d.shipments_today} to="/shipments" />
                </OpsGroup>
                <OpsGroup title={t('dashboard.stockOperations')} icon="fa-arrows-rotate">
                    <OpStat label={t('dashboard.movementsToday')} value={d.movements_today} to="/ledger" />
                    <OpStat label={t('dashboard.pendingTransfers')} value={d.pending_transfers} to="/transfers" alert />
                    <OpStat label={t('dashboard.pendingCounts')} value={d.pending_counts} to="/counts" alert />
                    <OpStat label={t('dashboard.reservedStock')} value={num(d.reserved_stock_qty)} to="/reports" />
                    <OpStat label={t('dashboard.warehouses')} value={d.warehouses} to="/warehouses" />
                </OpsGroup>
            </div>
        ),
        integration: canViewIntegration && si && (
            <Link to="/integrations/solabooks" className="panel panel--link" style={{ display: 'block', textDecoration: 'none', color: 'inherit', marginTop: 16 }}>
                <h2 style={{ fontSize: 14 }}>
                    <i className="fa-solid fa-rotate" style={{ color: '#e09921', marginInlineEnd: 6 }} />
                    {t('dashboard.solabooksSync')} <span className={`badge ${si.health === 'healthy' ? 'badge--live' : si.health === 'disconnected' ? 'badge--muted' : 'badge--warn'}`}>{['healthy', 'disconnected', 'needs_mapping', 'error'].includes(si.health) ? t(`status.${si.health}`) : t('status.unknown', undefined, { value: si.health })}</span>
                </h2>
                <p className="muted" style={{ margin: 0 }}>
                    {t('dashboard.pending')}: {si.events?.pending ?? 0} · {t('dashboard.failed')}: {si.events?.failed ?? 0} ·
                    {t('dashboard.mapping')} {si.mapping_completeness_pct ?? 0}% · {t('dashboard.awaitingSync')}: {si.documents_awaiting_sync ?? 0}
                </p>
            </Link>
        ),
        activity: (
            <div className="dash-cols" style={{ marginTop: 16 }}>
                <div className="panel">
                    <h2 style={{ fontSize: 14 }}><i className="fa-solid fa-arrow-trend-up" style={{ color: '#e09921', marginInlineEnd: 6 }} /> {t('dashboard.topMoving')} <span className="muted">{t('dashboard.last30Days')}</span></h2>
                    {(d.top_moving ?? []).length === 0 ? <EmptyState title={t('dashboard.noMovement')} /> : (
                        <table className="data-table"><thead><tr><th>{t('sku')}</th><th>{t('dashboard.item')}</th><th>{t('dashboard.quantityOut')}</th></tr></thead>
                            <tbody>{d.top_moving.map((t, i) => <tr key={i}><td>{t.sku}</td><td>{t.name}</td><td>{t.qty}</td></tr>)}</tbody></table>
                    )}
                </div>
                <div className="panel">
                    <h2 style={{ fontSize: 14 }}><i className="fa-solid fa-clock-rotate-left" style={{ color: '#e09921', marginInlineEnd: 6 }} /> {t('dashboard.recentMovements')}</h2>
                    {(d.recent_movements ?? []).length === 0 ? <EmptyState title={t('dashboard.noRecentMovements')} /> : (
                        <ul className="activity-list">
                            {d.recent_movements.map((m) => (
                                <li key={m.id}>{m.direction === 'in' ? '▲' : '▼'} {m.item_name ?? `#${m.item_id}`} · {m.quantity} @ {m.unit_cost} <span className="muted">{m.source_display ? `· ${m.source_display} ` : ''}{m.moved_at}</span></li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        ),
    };

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('dashboard.title') }]} />
            <header className="page-head">
                <h1>{t('dashboard.title')}</h1>
                <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 10 }}>
                    {d.generated_at && <span className="muted">{t('dashboard.updated', undefined, { date: d.generated_at })}</span>}
                    <button className="btn btn--sm" disabled={isFetching} onClick={() => qc.invalidateQueries({ queryKey: ['dashboard'] })}>
                        {t(isFetching ? 'dashboard.refreshing' : 'dashboard.refresh')}
                    </button>
                    <button className="btn btn--sm" onClick={() => setCustomizing((v) => !v)}>{t('dashboard.customize')}</button>
                </div>
            </header>

            {customizing && <div className="panel" style={{ marginBottom: 16 }}>
                <h2>{t('dashboard.layout')}</h2>
                <table className="data-table"><thead><tr><th>{t('dashboard.visible')}</th><th>{t('dashboard.section')}</th><th>{t('dashboard.order')}</th></tr></thead><tbody>
                    {layout.map((row) => <tr key={row.key}>
                        <td><input type="checkbox" checked={row.visible !== false} onChange={(e) => updateLayout(row.key, { visible: e.target.checked })} /></td>
                        <td>{t(`dashboard.section.${row.key}`, row.key)}</td>
                        <td><button className="btn btn--sm" onClick={() => moveLayout(row.key, -1)}>{t('dashboard.up')}</button> <button className="btn btn--sm" onClick={() => moveLayout(row.key, 1)}>{t('dashboard.down')}</button></td>
                    </tr>)}
                </tbody></table>
                <button className="btn btn--primary" onClick={saveLayout}>{t('dashboard.saveLayout')}</button>
            </div>}

            {layout.filter((row) => row.visible !== false).map((row) => <React.Fragment key={row.key}>{sections[row.key]}</React.Fragment>)}
        </section>
    );
}
