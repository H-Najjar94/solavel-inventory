import React from 'react';
import './ConnectionSummaryCard.css';
import financeIcon from './solacount-logo-gradient.svg?inline';

/** Plain-language group for a preparation failure code; the raw code stays under Details. */
export function failureGroup(reason = '') {
    if (reason.startsWith('default_connection_credentials_not_ready')) return 'credentials';
    if (reason.startsWith('default_connection_activation_gate_closed')) return 'gate';
    if (reason.startsWith('default_connection_retry_in_progress')) return 'busy';
    if (/^(default_plan_scope_invalid|default_required_role_missing|default_account_invalid|default_mapping_validation_failed)/.test(reason)) return 'accounts';
    if (reason === 'preparation_stalled') return 'stalled';
    return 'generic';
}

function describe(summary, tr) {
    const { state, reason, plan_requirement: plan, progress } = summary;
    if (state === 'plan_required') {
        const bundles = (plan?.bundled_with_finance_plans || []).map((p) => `SolaCount ${p.charAt(0).toUpperCase()}${p.slice(1)}`).join(tr('integration.summary.or'));
        return tr(`integration.summary.text.plan_required.${plan?.missing || 'both'}`, { bundles: bundles || 'SolaCount Advanced' });
    }
    if (state === 'finance_provisioning') return tr('integration.summary.text.finance_provisioning');
    if (state === 'preparing') return tr('integration.summary.text.preparing.connecting');
    if (state === 'failed') return tr(`integration.summary.text.failed.${failureGroup(reason)}`);
    if (state === 'needs_input') {
        if (reason === 'decisions_remaining') return tr('integration.summary.text.needs_input.decisions', { count: progress?.decisions_remaining ?? 0 });
        if (reason === 'review_result') return tr('integration.summary.text.needs_input.review');
        if (reason === 'separate_review_required') return tr('integration.summary.text.needs_input.separate');
        return tr('integration.summary.text.needs_input.existing');
    }
    if (state === 'needs_attention') return tr('integration.summary.text.needs_attention');
    return tr(`integration.summary.text.${state}`);
}

/**
 * The connection at a glance: organization, connected app, plain status and ONE
 * primary action. The same summary (from SolaStock's backend) drives SolaCount's
 * Connection Status page, so both apps always say the same thing.
 */
export default function ConnectionSummaryCard({ summary, tr, busy = false, error = '', onAction, compact = false }) {
    if (!summary) return null;
    const { state, action } = summary;
    const kind = action?.kind || 'none';
    const tone = state === 'connected' ? 'success' : ['failed', 'needs_attention', 'plan_required'].includes(state) ? 'warning' : state === 'preparing' ? 'progress' : 'neutral';
    const label = kind === 'none' || kind === 'ask_admin' ? null : tr(`integration.summary.action.${kind}`);
    const lastSync = summary.last_sync_at ? new Date(summary.last_sync_at) : null;
    return <section className={`connection-summary${compact ? ' connection-summary--compact' : ''}`} aria-labelledby="connection-summary-title" data-state={state}>
        <div className="connection-summary__head">
            <img className="connection-summary__icon" src={financeIcon} alt="" />
            <div className="connection-summary__title">
                <h1 id="connection-summary-title">{tr('integration.summary.title')}</h1>
                <dl className="connection-summary__facts">
                    <div><dt>{tr('integration.summary.organization')}</dt><dd><bdi>{summary.organization || '—'}</bdi></dd></div>
                    <div><dt>{tr('integration.summary.connectedApp')}</dt><dd><bdi>SolaCount</bdi></dd></div>
                </dl>
            </div>
            <span className="connection-summary__badge" data-tone={tone} role="status">
                {state === 'preparing' && <span className="connection-summary__spinner" aria-hidden="true" />}
                {tr(`integration.summary.state.${state}`)}
            </span>
        </div>
        <p className="connection-summary__text">{describe(summary, tr)}</p>
        {state === 'connected' && <p className="connection-summary__meta">{lastSync ? tr('integration.summary.lastSync', { when: lastSync.toLocaleString(document.documentElement.lang || undefined) }) : tr('integration.summary.noSyncYet')}</p>}
        {kind === 'ask_admin' && <p className="connection-summary__meta">{tr('integration.summary.askAdmin')}</p>}
        {kind === 'none' && state !== 'connected' && <p className="connection-summary__meta">{tr('integration.summary.noActionYet')}</p>}
        {error && <p className="connection-summary__error" role="alert">{error}</p>}
        <div className="connection-summary__actions">
            {label && <button type="button" className="btn btn--primary" disabled={busy} aria-busy={busy} onClick={() => onAction?.(kind, summary)}>
                {busy && <i className="fa-solid fa-spinner fa-spin" aria-hidden="true" />} {busy ? tr('integration.summary.working') : label}
            </button>}
            {(summary.reason && ['failed', 'needs_attention', 'needs_input'].includes(state)) && <details className="connection-summary__details">
                <summary>{tr('integration.summary.details')}</summary>
                <p><bdi>{summary.reason}</bdi>{summary.failed_at ? <> · <bdi>{summary.failed_at}</bdi></> : null}</p>
            </details>}
        </div>
    </section>;
}
