import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';

function HealthBadge({ health }) {
    const map = { healthy: 'badge--live', needs_mapping: 'badge--demo', error: 'badge--warn', disconnected: 'badge--muted' };
    return <span className={`badge ${map[health] ?? 'badge--muted'}`}>{health ?? 'unknown'}</span>;
}

export default function IntegrationSettingsPage() {
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.integration.manage');
    const [tab, setTab] = useState('status');

    const status = useApiQuery(['integration-status'], api.integrationStatus, { fallback: null });
    const s = status.data;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Settings', to: '/settings' }, { label: 'SolaBooks Integration' }]} />
            <header className="page-head">
                <h1>SolaBooks Integration</h1>
                {s && <HealthBadge health={s.health} />}
                <span className="badge badge--warn">foundation — no posting yet</span>
            </header>

            <Tabs tabs={[{ key: 'status', label: 'Status' }, { key: 'accounts', label: 'Account mappings' }, { key: 'items', label: 'Item mappings' }]} active={tab} onChange={setTab} />

            {tab === 'status' && (status.isLoading ? <Skeleton /> : !s ? <EmptyState title="Unavailable" hint="Select a tenant." /> : (
                <>
                    <div className="widget-grid">
                        <div className="widget-card"><div className="widget-card-label">Mode</div><div className="widget-card-value" style={{ fontSize: 18 }}>{s.mode}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Pending events</div><div className="widget-card-value">{s.events?.pending ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Failed events</div><div className="widget-card-value">{s.events?.failed ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Ignored</div><div className="widget-card-value">{s.events?.ignored ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Mapping completeness</div><div className="widget-card-value">{s.mapping_completeness_pct ?? 0}%</div></div>
                    </div>
                    <div className="panel">
                        <dl className="kv">
                            <dt>Linked SolaBooks org</dt><dd>{s.solabooks_organization_id ? `#${s.solabooks_organization_id}` : '— (not linked)'}</dd>
                            <dt>Last sync</dt><dd>{s.last_sync_at ?? '—'}</dd>
                            <dt>Last event generated</dt><dd>{s.last_event_generated_at ?? '—'}</dd>
                            <dt>Connection implemented</dt><dd>{s.connection_implemented ? 'Yes' : 'No (placeholder)'}</dd>
                        </dl>
                        <div className="doc-actions">
                            <button className="btn" disabled title="Real connection is not implemented yet">Reconnect</button>
                            <button className="btn" disabled title="Real connection is not implemented yet">Disable</button>
                            <Link className="btn btn--primary" to="/integrations/solabooks/events">View events</Link>
                        </div>
                        <p className="muted">Events are recorded locally in the outbox when inventory documents post. A future worker will deliver them to SolaBooks; no accounting is posted from SolaStock.</p>
                    </div>
                </>
            ))}

            {tab === 'accounts' && <AccountMappings gate={gate} toast={toast} qc={qc} />}
            {tab === 'items' && <ItemMappings gate={gate} toast={toast} qc={qc} />}
        </section>
    );
}

function AccountMappings({ gate, toast, qc }) {
    const { data, isLoading } = useApiQuery(['integration-accounts'], api.integrationAccountMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);

    function set(i, k, v) { setRows((r) => r.map((row, idx) => idx === i ? { ...row, [k]: v } : row)); }
    async function save() {
        setSaving(true);
        try { await api.saveIntegrationAccountMappings(rows); toast.push('Account mappings saved.', 'success'); qc.invalidateQueries({ queryKey: ['integration-accounts'] }); qc.invalidateQueries({ queryKey: ['integration-status'] }); }
        catch (e) { toast.push(e.message, 'error'); } finally { setSaving(false); }
    }

    if (isLoading) return <Skeleton />;
    return (
        <div className="panel">
            <p className="muted">Map each inventory concept to a SolaBooks account reference. These are references only — SolaStock never owns the chart of accounts.</p>
            <table className="data-table">
                <thead><tr><th>Mapping</th><th>SolaBooks account id</th><th>Code</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>{rows.map((m, i) => (
                    <tr key={m.mapping_type}>
                        <td>{m.mapping_type.replace(/_/g, ' ')}</td>
                        <td><input className="input" disabled={!gate.allowed} value={m.solabooks_account_id ?? ''} onChange={(e) => set(i, 'solabooks_account_id', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_code ?? ''} onChange={(e) => set(i, 'account_code', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_name ?? ''} onChange={(e) => set(i, 'account_name', e.target.value)} /></td>
                        <td>{m.status}</td>
                    </tr>
                ))}</tbody>
            </table>
            <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : 'Save mappings'}</button></div>
        </div>
    );
}

function ItemMappings({ gate, toast, qc }) {
    const { data, isLoading } = useApiQuery(['integration-items'], () => api.integrationItemMappings({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <Skeleton />;
    return (
        <div className="panel">
            <p className="muted">Per-item SolaBooks links. Editing opens on the item's Integration tab.</p>
            {rows.length === 0 ? <EmptyState title="No items" /> : (
                <table className="data-table">
                    <thead><tr><th>SKU</th><th>Item</th><th>SolaBooks item</th><th>Sync status</th><th></th></tr></thead>
                    <tbody>{rows.map((r) => (
                        <tr key={r.id}><td>{r.sku}</td><td>{r.name}</td><td>{r.solabooks_item_id ?? '—'}</td><td>{r.sync_status ?? 'not_synced'}</td>
                            <td><Link className="btn btn--sm" to={`/items/${r.id}`}>Open</Link></td></tr>
                    ))}</tbody>
                </table>
            )}
        </div>
    );
}
