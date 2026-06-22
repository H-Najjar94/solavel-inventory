import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';

export default function SettingsPage() {
    const { data, isMock } = useApiQuery(['settings'], api.settings, { fallback: { settings: null, units: [], categories: [], brands: [] } });
    const integration = useApiQuery(['integration'], api.integrationStatus, { fallback: { connected: false, planned_events: [], account_mappings: {} } });
    const s = data ?? {};
    const intg = integration.data ?? {};

    return (
        <section className="page">
            <header className="page-head"><h1>Settings</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>

            <div className="panel">
                <h2>Inventory Policy</h2>
                <dl className="kv">
                    <dt>Costing method</dt><dd>{s.settings?.default_costing_method ?? 'average'}</dd>
                    <dt>Negative stock</dt><dd>{s.settings?.allow_negative_stock ? 'Allowed' : 'Blocked'}</dd>
                </dl>
            </div>

            <div className="panel">
                <h2>Master Data</h2>
                <p className="muted">Units: {(s.units ?? []).length} · Categories: {(s.categories ?? []).length} · Brands: {(s.brands ?? []).length}</p>
            </div>

            <div className="panel">
                <h2>SolaBooks Integration <span className="badge badge--warn">placeholder</span></h2>
                <dl className="kv">
                    <dt>Status</dt><dd>{intg.connected ? 'Connected' : 'Not connected'}</dd>
                    <dt>Inventory asset</dt><dd>{intg.account_mappings?.inventory_asset ?? '— (unmapped)'}</dd>
                    <dt>COGS</dt><dd>{intg.account_mappings?.cogs ?? '— (unmapped)'}</dd>
                    <dt>GRNI / accrual</dt><dd>{intg.account_mappings?.grni_accrual ?? '— (unmapped)'}</dd>
                </dl>
                <p className="muted">Planned events: {(intg.planned_events ?? []).join(', ')}</p>
                <p className="muted">Posting outbox: not implemented yet.</p>
            </div>
        </section>
    );
}
