import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { getLocale, t } from '../i18n/index.js';

function accountLabel(account) {
    if (!account) return '—';
    const name = typeof account.name === 'object' && account.name !== null
        ? (account.name[getLocale()] ?? account.name.en ?? Object.values(account.name)[0])
        : account.name;
    return [account.code, name].filter(Boolean).join(' · ');
}

// Connected organizations only: shows whether landed costs journal to SolaCount
// and lets a connection manager who may review accounting roles choose the
// reviewed clearing account once.
export default function LandedCostConnectionPanel({ status }) {
    const manager = useCanCreate('inventory.integration.connection_manage');
    // Binding the clearing account is an accountant decision (same gate as the wizard).
    const reviewer = useCanCreate('inventory.integration.accounting_review');
    const owner = { allowed: manager.allowed && reviewer.allowed };
    const toast = useToast(); const qc = useQueryClient();
    const [accountId, setAccountId] = useState('');
    const [busy, setBusy] = useState(false);
    if (!status || status.mode !== 'connected') return null;

    if (status.enabled) {
        return <div className="panel" role="status">
            {t('landedCosts.connection.enabled')} {t('landedCosts.connection.clearing', undefined, { account: accountLabel(status.clearing_account) })}
        </div>;
    }

    async function enable() {
        if (!accountId || busy) return;
        setBusy(true);
        try {
            await api.enableLandedCostConnection(Number(accountId));
            toast.push(t('landedCosts.connection.enabled'), 'success');
            qc.invalidateQueries({ queryKey: ['landed-cost-connection'] });
            qc.invalidateQueries({ queryKey: ['landed-cost'] });
        } catch (e) { toast.failure(e, e.message); }
        finally { setBusy(false); }
    }

    return (
        <div className="panel">
            <h2>{t('landedCosts.connection.title')}</h2>
            <p className="muted">{t('landedCosts.connection.hint')}</p>
            {(status.missing_roles ?? []).filter((r) => r !== 'landed_cost_clearing').length > 0 &&
                <p className="banner banner--warn">{t('landedCosts.connection.missing', undefined, { roles: status.missing_roles.filter((r) => r !== 'landed_cost_clearing').join(', ') })}</p>}
            {owner.allowed ? (
                <div className="form-grid">
                    <label className="field"><span className="field-label">{t('landedCosts.connection.account')}</span>
                        <select className="input" value={accountId} onChange={(e) => setAccountId(e.target.value)}>
                            <option value="">{t('landedCosts.connection.select')}</option>
                            {(status.candidates ?? []).map((a) => <option key={a.id} value={a.id}>
                                {accountLabel(a)}{a.recommended ? ` (${t('landedCosts.connection.recommended')})` : ''}</option>)}
                        </select>
                    </label>
                    <div className="doc-actions"><button className="btn btn--primary" disabled={!accountId || busy} onClick={enable}>{t('landedCosts.connection.enable')}</button></div>
                </div>
            ) : <p className="banner banner--warn">{t('landedCosts.connection.ownerOnly')}</p>}
        </div>
    );
}
