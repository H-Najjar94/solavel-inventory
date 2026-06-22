import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useTenant } from '../stores/tenant.jsx';
import { useToast } from '../stores/toast.jsx';
import { api } from '../services/api.js';
import { Breadcrumbs } from '../components/ui.jsx';

/**
 * SolaStock first-run onboarding — the Solavel-app setup flow. Steps:
 *   1. Organization detected
 *   2. Tenant tables check
 *   3. Provision inventory tables (admin only)
 *   4. Basic settings
 *   5. First warehouse
 *   6. First item
 *   7. Opening stock
 *   8. Finish → dashboard
 *
 * Provisioning runs ONLY SolaStock migrations (never Finance). No sample data is
 * inserted unless the user explicitly chooses "Load demo starter data".
 */
export default function OnboardingPage() {
    const tenant = useTenant();
    const nav = useNavigate();
    const toast = useToast();
    const qc = useQueryClient();

    const [step, setStep] = useState(1);
    const [busy, setBusy] = useState(false);
    const [provisionResult, setProvisionResult] = useState(null);
    const [wh, setWh] = useState({ id: null, code: '', name: '' });
    const [item, setItem] = useState({ id: null, sku: '', name: '' });
    const ready = tenant.dataState === 'real';

    async function provision() {
        setBusy(true);
        try {
            const res = await tenant.provision();
            setProvisionResult(res);
            if (res?.provisioned) { toast.push('SolaStock provisioned.', 'success'); setStep(4); }
            else toast.push(res?.message || 'Provisioning needs a server admin.', 'error');
        } catch (e) { toast.push(e.message, 'error'); setProvisionResult({ provisioned: false, message: e.message }); }
        finally { setBusy(false); }
    }

    async function createWarehouse() {
        setBusy(true);
        try {
            const res = await api.createWarehouse({ code: wh.code || 'MAIN', name: wh.name || 'Main Warehouse', type: 'warehouse', is_active: true });
            setWh({ ...wh, id: res?.data?.id });
            toast.push('Warehouse created.', 'success'); setStep(6);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function createItem() {
        setBusy(true);
        try {
            const res = await api.createItem({ sku: item.sku || 'ITEM-001', name: item.name || 'First Item', item_type: 'inventory', tracking_type: 'none', is_active: true });
            setItem({ ...item, id: res?.data?.id });
            toast.push('Item created.', 'success'); setStep(7);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    function finish() {
        qc.invalidateQueries();
        nav('/dashboard');
    }

    const Step = ({ n, title, children }) => (
        <div className={`onb-step ${step === n ? 'onb-step--active' : step > n ? 'onb-step--done' : ''}`}>
            <div className="onb-step__num">{step > n ? '✓' : n}</div>
            <div className="onb-step__body"><h3>{title}</h3>{step === n && <div className="onb-step__content">{children}</div>}</div>
        </div>
    );

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Set up SolaStock' }]} />
            <header className="page-head"><h1>Set up SolaStock</h1></header>
            <p className="muted">A quick first-run setup to get your inventory workspace ready.</p>

            <div className="onb-wizard">
                <Step n={1} title="Organization detected">
                    <p>Organization <strong>#{tenant.organization_id ?? '—'}</strong> is connected.</p>
                    <button className="btn btn--primary" onClick={() => setStep(2)}>Continue</button>
                </Step>

                <Step n={2} title="Check SolaStock tables">
                    <p>{ready ? 'SolaStock tables are already installed for this organization.' : 'SolaStock tables are not installed yet.'}</p>
                    <button className="btn btn--primary" onClick={() => setStep(ready ? 4 : 3)}>{ready ? 'Skip to settings' : 'Continue'}</button>
                </Step>

                <Step n={3} title="Provision inventory tables">
                    <p>This creates SolaStock’s tables in your organization’s database (marker <code>migrated_at_inv</code>). No Finance tables are touched.</p>
                    {tenant.can_provision
                        ? <button className="btn btn--primary" disabled={busy} onClick={provision}>{busy ? 'Provisioning…' : 'Provision SolaStock'}</button>
                        : <p className="muted">An administrator with “Manage settings” must run this step.</p>}
                    {provisionResult && !provisionResult.provisioned && provisionResult.admin_command && (
                        <div className="setup-error" style={{ marginTop: 12 }}>A server admin must run:<pre className="payload-view">{provisionResult.admin_command}</pre></div>
                    )}
                </Step>

                <Step n={4} title="Basic settings">
                    <p>Default costing method and policies can be adjusted later in Settings.</p>
                    <button className="btn btn--primary" onClick={() => setStep(5)}>Continue</button>
                </Step>

                <Step n={5} title="Create your first warehouse">
                    <div className="form-grid">
                        <input className="input" placeholder="Code (e.g. MAIN)" value={wh.code} onChange={(e) => setWh({ ...wh, code: e.target.value })} />
                        <input className="input" placeholder="Name (e.g. Main Warehouse)" value={wh.name} onChange={(e) => setWh({ ...wh, name: e.target.value })} />
                    </div>
                    <button className="btn btn--primary" disabled={busy} onClick={createWarehouse}>{busy ? 'Creating…' : 'Create warehouse'}</button>
                    <button className="btn" onClick={() => setStep(6)}>Skip</button>
                </Step>

                <Step n={6} title="Create your first item">
                    <div className="form-grid">
                        <input className="input" placeholder="SKU (e.g. ITEM-001)" value={item.sku} onChange={(e) => setItem({ ...item, sku: e.target.value })} />
                        <input className="input" placeholder="Name" value={item.name} onChange={(e) => setItem({ ...item, name: e.target.value })} />
                    </div>
                    <button className="btn btn--primary" disabled={busy} onClick={createItem}>{busy ? 'Creating…' : 'Create item'}</button>
                    <button className="btn" onClick={() => setStep(7)}>Skip</button>
                </Step>

                <Step n={7} title="Add opening stock (optional)">
                    <p>You can record opening stock now from the Opening Stock screen, or later.</p>
                    <button className="btn" onClick={() => nav('/opening-stock/new')}>Go to Opening Stock</button>
                    <button className="btn btn--primary" onClick={() => setStep(8)}>Continue</button>
                </Step>

                <Step n={8} title="Finish">
                    <p>Setup complete. Your SolaStock workspace is ready.</p>
                    <button className="btn btn--primary" onClick={finish}>Go to dashboard</button>
                </Step>
            </div>
        </section>
    );
}
