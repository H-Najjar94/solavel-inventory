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
    const [connection, setConnection] = useState({ mode: 'connected_pending_mapping', client_id: '990010', solabooks_organization_id: '', api_key: '', require_mapping_before_post: true });
    const [savingConnection, setSavingConnection] = useState(false);
    const [rotatingKey, setRotatingKey] = useState(false);

    const status = useApiQuery(['integration-status'], api.integrationStatus, { fallback: null });
    const s = status.data;
    useEffect(() => {
        if (s) setConnection((current) => ({ ...current, mode: s.mode === 'disconnected' ? 'connected_pending_mapping' : s.mode, solabooks_organization_id: s.solabooks_organization_id ?? '' }));
    }, [s]);

    async function saveConnection() {
        setSavingConnection(true);
        try {
            await api.configureIntegration({ ...connection, client_id: Number(connection.client_id), solabooks_organization_id: Number(connection.solabooks_organization_id), api_key: connection.api_key || undefined });
            setConnection((current) => ({ ...current, api_key: '' }));
            await qc.invalidateQueries({ queryKey: ['integration-status'] });
            toast.push('SolaBooks connection saved.', 'success');
        } catch (error) { toast.push(error.message, 'error'); }
        finally { setSavingConnection(false); }
    }

    async function rotateSigningKey() {
        setRotatingKey(true);
        try {
            await api.rotateIntegrationSigningKey();
            await qc.invalidateQueries({ queryKey: ['integration-status'] });
            toast.push('Signing key generated and transferred securely.', 'success');
        } catch (error) { toast.push(error.message, 'error'); }
        finally { setRotatingKey(false); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Settings', to: '/settings' }, { label: 'SolaBooks Integration' }]} />
            <header className="page-head">
                <h1>SolaBooks Integration</h1>
                {s && <HealthBadge health={s.health} />}
                <span className="badge badge--live">outbox delivery</span>
            </header>

            <Tabs tabs={[{ key: 'status', label: 'Status' }, { key: 'accounts', label: 'Account mappings' }, { key: 'taxes', label: 'Tax mappings' }, { key: 'items', label: 'Item mappings' }]} active={tab} onChange={setTab} />

            {tab === 'status' && (status.isLoading ? <Skeleton /> : !s ? <EmptyState title="Unavailable" hint="Select a tenant." /> : (
                <>
                    <div className="widget-grid">
                        <div className="widget-card"><div className="widget-card-label">Mode</div><div className="widget-card-value" style={{ fontSize: 18 }}>{s.mode}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Pending events</div><div className="widget-card-value">{s.events?.pending ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Failed events</div><div className="widget-card-value">{s.events?.failed ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Ignored</div><div className="widget-card-value">{s.events?.ignored ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">Mapping completeness</div><div className="widget-card-value">{s.mapping_completeness_pct ?? 0}%</div></div>
                        <div className="widget-card"><div className="widget-card-label">Tax mappings</div><div className="widget-card-value">{s.tax_mapping_completeness_pct ?? 0}%</div></div>
                    </div>
                    <div className="panel">
                        <dl className="kv">
                            <dt>Linked SolaBooks org</dt><dd>{s.solabooks_organization_id ? `#${s.solabooks_organization_id}` : '— (not linked)'}</dd>
                            <dt>Last sync</dt><dd>{s.last_sync_at ?? '—'}</dd>
                            <dt>Last event generated</dt><dd>{s.last_event_generated_at ?? '—'}</dd>
                            <dt>Connection implemented</dt><dd>{s.connection_implemented ? 'Yes' : 'No (placeholder)'}</dd>
                            <dt>Signing protocol</dt><dd>{s.signing?.protocol_version ?? 'Not configured'}</dd>
                            <dt>Signing key ID</dt><dd>{s.signing?.key_id ?? '—'}</dd>
                            <dt>Last signed delivery</dt><dd>{s.signing?.last_successful_delivery_at ?? '—'}</dd>
                        </dl>
                        <div className="doc-actions">
                            <Link className="btn btn--primary" to="/integrations/solabooks/events">View events</Link>
                        </div>
                        <p className="muted">Events are recorded locally when inventory documents post, then delivered to SolaBooks with authenticated, idempotent retry. SolaStock never writes directly to finance tables.</p>
                    </div>
                    <div className="panel">
                        <h2>Connection</h2>
                        <p className="muted">The API key is encrypted at rest and is never returned by this API.</p>
                        <div className="fg2">
                            <label className="field"><span className="field-label">Mode</span><select className="input" disabled={!gate.allowed} value={connection.mode} onChange={(e) => setConnection({ ...connection, mode: e.target.value })}><option value="connected_readonly">Read only</option><option value="connected_pending_mapping">Pending mappings</option><option value="active">Active</option><option value="paused">Paused</option></select></label>
                            <label className="field"><span className="field-label">Central client ID</span><input className="input" type="number" disabled={!gate.allowed} value={connection.client_id} onChange={(e) => setConnection({ ...connection, client_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">SolaBooks organization ID</span><input className="input" type="number" disabled={!gate.allowed} value={connection.solabooks_organization_id} onChange={(e) => setConnection({ ...connection, solabooks_organization_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">SolaBooks API key</span><input className="input" type="password" autoComplete="new-password" disabled={!gate.allowed} value={connection.api_key} onChange={(e) => setConnection({ ...connection, api_key: e.target.value })} placeholder={s?.delivery_configured ? 'Configured — leave blank to keep' : 'Paste newly generated key'} /></label>
                        </div>
                        <button className="btn btn--primary" disabled={!gate.allowed || savingConnection} onClick={saveConnection}>{savingConnection ? 'Saving…' : 'Save connection'}</button>
                        <button className="btn" style={{ marginLeft: 8 }} disabled={!gate.allowed || rotatingKey || !s?.delivery_configured} onClick={rotateSigningKey}>{rotatingKey ? 'Rotating…' : (s?.signing?.configured ? 'Rotate signing key' : 'Generate signing key')}</button>
                        <p className="muted">The signing secret is generated in SolaBooks, transferred once over the authenticated internal API, encrypted at rest, and never returned by Inventory status APIs.</p>
                    </div>
                </>
            ))}

            {tab === 'accounts' && <AccountMappings gate={gate} toast={toast} qc={qc} />}
            {tab === 'taxes' && <TaxMappings gate={gate} toast={toast} qc={qc} />}
            {tab === 'items' && <ItemMappings gate={gate} toast={toast} qc={qc} />}
        </section>
    );
}

function TaxMappings({ gate, toast, qc }) {
    const { data, isLoading } = useApiQuery(['integration-taxes'], api.integrationTaxMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);
    function set(i, key, value) { setRows((current) => current.map((row, index) => index === i ? { ...row, [key]: value } : row)); }
    async function save() {
        setSaving(true);
        try {
            await api.saveIntegrationTaxMappings(rows);
            toast.push('Tax mappings saved.', 'success');
            qc.invalidateQueries({ queryKey: ['integration-taxes'] });
            qc.invalidateQueries({ queryKey: ['integration-status'] });
        } catch (error) { toast.push(error.message, 'error'); } finally { setSaving(false); }
    }
    if (isLoading) return <Skeleton />;
    return <div className="panel">
        <p className="muted">Map stable Inventory tax codes to Finance tax entities and VAT accounts.</p>
        <table className="data-table"><thead><tr><th>Inventory tax</th><th>Treatment</th><th>Finance tax ID</th><th>Finance code</th><th>Input VAT account</th><th>Output VAT account</th><th>Status</th></tr></thead>
            <tbody>{rows.map((row, i) => <tr key={row.tax_code}>
                <td>{row.tax_code} — {row.tax_name}</td><td>{row.treatment}</td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_id ?? ''} onChange={(e) => set(i, 'solabooks_tax_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_code ?? ''} onChange={(e) => set(i, 'solabooks_tax_code', e.target.value)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.input_tax_account_id ?? ''} onChange={(e) => set(i, 'input_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.output_tax_account_id ?? ''} onChange={(e) => set(i, 'output_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><select className="input" disabled={!gate.allowed} value={row.status} onChange={(e) => set(i, 'status', e.target.value)}><option value="unmapped">Unmapped</option><option value="mapped">Mapped</option><option value="inactive">Inactive</option></select></td>
            </tr>)}</tbody></table>
        <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? 'Saving…' : 'Save tax mappings'}</button></div>
    </div>;
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
