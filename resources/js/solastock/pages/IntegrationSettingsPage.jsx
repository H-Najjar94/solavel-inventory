import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { useSettingsTranslation } from '../i18n/settingsPages.js';

function HealthBadge({ health, tr }) {
    const map = { healthy: 'badge--live', needs_mapping: 'badge--demo', error: 'badge--warn', disconnected: 'badge--muted' };
    return <span className={`badge ${map[health] ?? 'badge--muted'}`}>{tr(`integration.health.${health ?? 'unknown'}`, {}, health ?? tr('integration.health.unknown'))}</span>;
}

export default function IntegrationSettingsPage() {
    const tr = useSettingsTranslation();
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
            toast.push(tr('integration.connection.saved'), 'success');
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); }
        finally { setSavingConnection(false); }
    }

    async function rotateSigningKey() {
        setRotatingKey(true);
        try {
            await api.rotateIntegrationSigningKey();
            await qc.invalidateQueries({ queryKey: ['integration-status'] });
            toast.push(tr('integration.connection.keyGenerated'), 'success');
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); }
        finally { setRotatingKey(false); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: tr('integration.settingsBreadcrumb'), to: '/settings' }, { label: tr('integration.title') }]} />
            <header className="page-head">
                <h1>{tr('integration.title')}</h1>
                {s && <HealthBadge health={s.health} tr={tr} />}
                <span className="badge badge--live">{tr('integration.outboxDelivery')}</span>
            </header>

            <Tabs tabs={[{ key: 'status', label: tr('integration.tabs.status') }, { key: 'accounts', label: tr('integration.tabs.accounts') }, { key: 'taxes', label: tr('integration.tabs.taxes') }, { key: 'items', label: tr('integration.tabs.items') }]} active={tab} onChange={setTab} />

            {tab === 'status' && (status.isLoading ? <Skeleton /> : !s ? <EmptyState title={tr('integration.unavailable')} hint={tr('integration.selectTenant')} /> : (
                <>
                    <div className="widget-grid">
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.mode')}</div><div className="widget-card-value" style={{ fontSize: 18 }}>{tr(`integration.connection.${s.mode === 'connected_readonly' ? 'readOnly' : s.mode === 'connected_pending_mapping' ? 'pendingMappings' : s.mode}`, {}, s.mode)}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.pending')}</div><div className="widget-card-value">{s.events?.pending ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.failed')}</div><div className="widget-card-value">{s.events?.failed ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.ignored')}</div><div className="widget-card-value">{s.events?.ignored ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.mapping')}</div><div className="widget-card-value">{s.mapping_completeness_pct ?? 0}%</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.taxMapping')}</div><div className="widget-card-value">{s.tax_mapping_completeness_pct ?? 0}%</div></div>
                    </div>
                    <div className="panel">
                        <dl className="kv">
                            <dt>{tr('integration.details.linkedOrg')}</dt><dd>{s.solabooks_organization_id ? `#${s.solabooks_organization_id}` : tr('integration.details.notLinked')}</dd>
                            <dt>{tr('integration.details.lastSync')}</dt><dd>{s.last_sync_at ?? '—'}</dd>
                            <dt>{tr('integration.details.lastGenerated')}</dt><dd>{s.last_event_generated_at ?? '—'}</dd>
                            <dt>{tr('integration.details.implemented')}</dt><dd>{s.connection_implemented ? tr('integration.details.yes') : tr('integration.details.placeholder')}</dd>
                            <dt>{tr('integration.details.signingProtocol')}</dt><dd>{s.signing?.protocol_version ?? tr('integration.details.notConfigured')}</dd>
                            <dt>{tr('integration.details.signingKeyId')}</dt><dd>{s.signing?.key_id ?? '—'}</dd>
                            <dt>{tr('integration.details.lastSigned')}</dt><dd>{s.signing?.last_successful_delivery_at ?? '—'}</dd>
                        </dl>
                        <div className="doc-actions">
                            <Link className="btn btn--primary" to="/integrations/solabooks/events">{tr('integration.details.viewEvents')}</Link>
                        </div>
                        <p className="muted">{tr('integration.details.deliveryDescription')}</p>
                    </div>
                    <div className="panel">
                        <h2>{tr('integration.connection.title')}</h2>
                        <p className="muted">{tr('integration.connection.apiKeySecurity')}</p>
                        <div className="fg2">
                            <label className="field"><span className="field-label">{tr('integration.metrics.mode')}</span><select className="input" disabled={!gate.allowed} value={connection.mode} onChange={(e) => setConnection({ ...connection, mode: e.target.value })}><option value="connected_readonly">{tr('integration.connection.readOnly')}</option><option value="connected_pending_mapping">{tr('integration.connection.pendingMappings')}</option><option value="active">{tr('integration.connection.active')}</option><option value="paused">{tr('integration.connection.paused')}</option></select></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.clientId')}</span><input className="input" type="number" disabled={!gate.allowed} value={connection.client_id} onChange={(e) => setConnection({ ...connection, client_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.organizationId')}</span><input className="input" type="number" disabled={!gate.allowed} value={connection.solabooks_organization_id} onChange={(e) => setConnection({ ...connection, solabooks_organization_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.apiKey')}</span><input className="input" type="password" autoComplete="new-password" disabled={!gate.allowed} value={connection.api_key} onChange={(e) => setConnection({ ...connection, api_key: e.target.value })} placeholder={s?.delivery_configured ? tr('integration.connection.keepKey') : tr('integration.connection.pasteKey')} /></label>
                        </div>
                        <button className="btn btn--primary" disabled={!gate.allowed || savingConnection} onClick={saveConnection}>{savingConnection ? tr('integration.connection.saving') : tr('integration.connection.save')}</button>
                        <button className="btn" style={{ marginInlineStart: 8 }} disabled={!gate.allowed || rotatingKey || !s?.delivery_configured} onClick={rotateSigningKey}>{rotatingKey ? tr('integration.connection.rotating') : (s?.signing?.configured ? tr('integration.connection.rotate') : tr('integration.connection.generate'))}</button>
                        <p className="muted">{tr('integration.connection.signingDescription')}</p>
                    </div>
                </>
            ))}

            {tab === 'accounts' && <AccountMappings gate={gate} toast={toast} qc={qc} tr={tr} />}
            {tab === 'taxes' && <TaxMappings gate={gate} toast={toast} qc={qc} tr={tr} />}
            {tab === 'items' && <ItemMappings gate={gate} toast={toast} qc={qc} tr={tr} />}
        </section>
    );
}

function TaxMappings({ gate, toast, qc, tr }) {
    const { data, isLoading } = useApiQuery(['integration-taxes'], api.integrationTaxMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);
    function set(i, key, value) { setRows((current) => current.map((row, index) => index === i ? { ...row, [key]: value } : row)); }
    async function save() {
        setSaving(true);
        try {
            await api.saveIntegrationTaxMappings(rows);
            toast.push(tr('integration.tax.saved'), 'success');
            qc.invalidateQueries({ queryKey: ['integration-taxes'] });
            qc.invalidateQueries({ queryKey: ['integration-status'] });
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); } finally { setSaving(false); }
    }
    if (isLoading) return <Skeleton />;
    return <div className="panel">
        <p className="muted">{tr('integration.tax.description')}</p>
        <table className="data-table"><thead><tr><th>{tr('integration.tax.inventoryTax')}</th><th>{tr('integration.tax.treatment')}</th><th>{tr('integration.tax.financeTaxId')}</th><th>{tr('integration.tax.financeCode')}</th><th>{tr('integration.tax.inputVat')}</th><th>{tr('integration.tax.outputVat')}</th><th>{tr('integration.tax.status')}</th></tr></thead>
            <tbody>{rows.map((row, i) => <tr key={row.tax_code}>
                <td>{row.tax_code} — {row.tax_name}</td><td>{row.treatment}</td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_id ?? ''} onChange={(e) => set(i, 'solabooks_tax_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_code ?? ''} onChange={(e) => set(i, 'solabooks_tax_code', e.target.value)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.input_tax_account_id ?? ''} onChange={(e) => set(i, 'input_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.output_tax_account_id ?? ''} onChange={(e) => set(i, 'output_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><select className="input" disabled={!gate.allowed} value={row.status} onChange={(e) => set(i, 'status', e.target.value)}><option value="unmapped">{tr('integration.tax.unmapped')}</option><option value="mapped">{tr('integration.tax.mapped')}</option><option value="inactive">{tr('integration.tax.inactive')}</option></select></td>
            </tr>)}</tbody></table>
        <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? tr('integration.connection.saving') : tr('integration.tax.save')}</button></div>
    </div>;
}

function AccountMappings({ gate, toast, qc, tr }) {
    const { data, isLoading } = useApiQuery(['integration-accounts'], api.integrationAccountMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);

    function set(i, k, v) { setRows((r) => r.map((row, idx) => idx === i ? { ...row, [k]: v } : row)); }
    async function save() {
        setSaving(true);
        try { await api.saveIntegrationAccountMappings(rows); toast.push(tr('integration.account.saved'), 'success'); qc.invalidateQueries({ queryKey: ['integration-accounts'] }); qc.invalidateQueries({ queryKey: ['integration-status'] }); }
        catch (e) { toast.push(e.message || tr('settings.common.errorFallback'), 'error'); } finally { setSaving(false); }
    }

    if (isLoading) return <Skeleton />;
    return (
        <div className="panel">
            <p className="muted">{tr('integration.account.description')}</p>
            <table className="data-table">
                <thead><tr><th>{tr('integration.account.mapping')}</th><th>{tr('integration.account.accountId')}</th><th>{tr('integration.account.code')}</th><th>{tr('integration.account.name')}</th><th>{tr('integration.account.status')}</th></tr></thead>
                <tbody>{rows.map((m, i) => (
                    <tr key={m.mapping_type}>
                        <td>{tr(`integration.mapping.${m.mapping_type}`, {}, m.mapping_type)}</td>
                        <td><input className="input" disabled={!gate.allowed} value={m.solabooks_account_id ?? ''} onChange={(e) => set(i, 'solabooks_account_id', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_code ?? ''} onChange={(e) => set(i, 'account_code', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_name ?? ''} onChange={(e) => set(i, 'account_name', e.target.value)} /></td>
                        <td>{tr(`integration.status.${m.status}`, {}, m.status)}</td>
                    </tr>
                ))}</tbody>
            </table>
            <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? tr('integration.connection.saving') : tr('integration.account.save')}</button></div>
        </div>
    );
}

function ItemMappings({ tr }) {
    const { data, isLoading } = useApiQuery(['integration-items'], () => api.integrationItemMappings({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <Skeleton />;
    return (
        <div className="panel">
            <p className="muted">{tr('integration.item.description')}</p>
            {rows.length === 0 ? <EmptyState title={tr('integration.item.noItems')} /> : (
                <table className="data-table">
                    <thead><tr><th>{tr('integration.item.sku')}</th><th>{tr('integration.item.item')}</th><th>{tr('integration.item.solabooksItem')}</th><th>{tr('integration.item.syncStatus')}</th><th></th></tr></thead>
                    <tbody>{rows.map((r) => (
                        <tr key={r.id}><td>{r.sku}</td><td>{r.name}</td><td>{r.solabooks_item_id ?? '—'}</td><td>{r.sync_status ? tr(`integration.status.${r.sync_status}`, {}, r.sync_status) : tr('integration.item.notSynced')}</td>
                            <td><Link className="btn btn--sm" to={`/items/${r.id}`}>{tr('integration.item.open')}</Link></td></tr>
                    ))}</tbody>
                </table>
            )}
        </div>
    );
}
