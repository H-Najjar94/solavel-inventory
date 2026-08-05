import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useTenant } from '../stores/tenant.jsx';
import { useToast } from '../stores/toast.jsx';
import { useMeta } from '../stores/meta.jsx';
import { api } from '../services/api.js';
import { Breadcrumbs, QuickCreateSelect } from '../components/ui.jsx';
import { t } from '../i18n/index.js';

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
    const meta = useMeta();
    const qc = useQueryClient();

    const [step, setStep] = useState(1);
    const [busy, setBusy] = useState(false);
    const [provisionResult, setProvisionResult] = useState(null);
    const [wh, setWh] = useState({ id: null, code: '', name: '' });
    const [item, setItem] = useState({ id: null, sku: '', name: '', category_id: null, base_unit_id: null });
    const ready = tenant.dataState === 'real';

    async function provision() {
        setBusy(true);
        try {
            const res = await tenant.provision();
            setProvisionResult(res);
            if (res?.provisioned) { toast.push(t('onboarding.provisioned'), 'success'); setStep(4); }
            else toast.push(res?.message || t('onboarding.provisionAdmin'), 'error');
        } catch (e) { toast.push(e.message, 'error'); setProvisionResult({ provisioned: false, message: e.message }); }
        finally { setBusy(false); }
    }

    async function createWarehouse() {
        setBusy(true);
        try {
            const res = await api.createWarehouse({ code: wh.code || 'MAIN', name: wh.name || 'Main Warehouse', type: 'warehouse', is_active: true });
            setWh({ ...wh, id: res?.data?.id });
            toast.push(t('onboarding.warehouseCreated'), 'success'); setStep(6);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function createItem() {
        setBusy(true);
        try {
            const res = await api.createItem({ ...item, item_type: 'inventory', tracking_type: 'none', costing_method: 'average', is_active: true });
            setItem({ ...item, id: res?.data?.id });
            toast.push(t('onboarding.itemCreated'), 'success'); setStep(7);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function quickCreate(kind, name) {
        try {
            const res = await (kind === 'unit' ? api.createUnit(name) : api.createCategory(name));
            await qc.invalidateQueries({ queryKey: ['meta'] });
            return res?.data ?? res;
        } catch (e) { toast.push(e.message, 'error'); return null; }
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
            <Breadcrumbs items={[{ label: t('onboarding.title') }]} />
            <header className="page-head"><h1>{t('onboarding.title')}</h1></header>
            <p className="muted">{t('onboarding.subtitle')}</p>

            <div className="onb-wizard">
                <Step n={1} title={t('onboarding.organizationDetected')}>
                    <p>{t('onboarding.organizationConnected', undefined, { id: tenant.organization_id ?? '—' })}</p>
                    <button className="btn btn--primary" onClick={() => setStep(2)}>{t('onboarding.continue')}</button>
                </Step>

                <Step n={2} title={t('onboarding.checkTables')}>
                    <p>{t(ready ? 'onboarding.tablesInstalled' : 'onboarding.tablesMissing')}</p>
                    <button className="btn btn--primary" onClick={() => setStep(ready ? 4 : 3)}>{t(ready ? 'onboarding.skipSettings' : 'onboarding.continue')}</button>
                </Step>

                <Step n={3} title={t('onboarding.provisionTables')}>
                    <p>{t('onboarding.provisionHint')} <code>migrated_at_inv</code></p>
                    {tenant.can_provision
                        ? <button className="btn btn--primary" disabled={busy} onClick={provision}>{t(busy ? 'onboarding.provisioning' : 'onboarding.provision')}</button>
                        : <p className="muted">{t('onboarding.manageSettingsRequired')}</p>}
                    {provisionResult && !provisionResult.provisioned && provisionResult.admin_command && (
                        <div className="setup-error" style={{ marginTop: 12 }}>{t('onboarding.adminCommand')}<pre className="payload-view">{provisionResult.admin_command}</pre></div>
                    )}
                </Step>

                <Step n={4} title={t('onboarding.basicSettings')}>
                    <p>{t('onboarding.settingsHint')}</p>
                    <button className="btn btn--primary" onClick={() => setStep(5)}>{t('onboarding.continue')}</button>
                </Step>

                <Step n={5} title={t('onboarding.firstWarehouse')}>
                    <div className="form-grid">
                        <input className="input" placeholder={t('onboarding.warehouseCode')} value={wh.code} onChange={(e) => setWh({ ...wh, code: e.target.value })} />
                        <input className="input" placeholder={t('onboarding.warehouseName')} value={wh.name} onChange={(e) => setWh({ ...wh, name: e.target.value })} />
                    </div>
                    <button className="btn btn--primary" disabled={busy} onClick={createWarehouse}>{t(busy ? 'onboarding.creating' : 'onboarding.createWarehouse')}</button>
                    <button className="btn" onClick={() => setStep(6)}>{t('onboarding.skip')}</button>
                </Step>

                <Step n={6} title={t('onboarding.firstItem')}>
                    <div className="form-grid">
                        <input className="input" placeholder={t('onboarding.itemSku')} value={item.sku} onChange={(e) => setItem({ ...item, sku: e.target.value })} />
                        <input className="input" placeholder={t('onboarding.name')} value={item.name} onChange={(e) => setItem({ ...item, name: e.target.value })} />
                        <QuickCreateSelect label={t('category')} required value={item.category_id}
                            onChange={(value) => setItem({ ...item, category_id: value })} options={meta.lookups?.categories ?? []}
                            onCreate={(name) => quickCreate('category', name)} />
                        <QuickCreateSelect label={t('unit')} required value={item.base_unit_id}
                            onChange={(value) => setItem({ ...item, base_unit_id: value })} options={meta.lookups?.units ?? []}
                            onCreate={(name) => quickCreate('unit', name)} />
                    </div>
                    <button className="btn btn--primary" disabled={busy || !item.name || !item.sku || !item.category_id || !item.base_unit_id} onClick={createItem}>{t(busy ? 'onboarding.creating' : 'onboarding.createItem')}</button>
                    <button className="btn" onClick={() => setStep(7)}>{t('onboarding.skip')}</button>
                </Step>

                <Step n={7} title={t('onboarding.openingStock')}>
                    <p>{t('onboarding.openingStockHint')}</p>
                    <button className="btn" onClick={() => nav('/opening-stock/new')}>{t('onboarding.goOpeningStock')}</button>
                    <button className="btn btn--primary" onClick={() => setStep(8)}>{t('onboarding.continue')}</button>
                </Step>

                <Step n={8} title={t('onboarding.finish')}>
                    <p>{t('onboarding.complete')}</p>
                    <button className="btn btn--primary" onClick={finish}>{t('onboarding.dashboard')}</button>
                </Step>
            </div>
        </section>
    );
}
