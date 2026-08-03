import React from 'react';
import { api } from '../services/api.js';

export default function GuidedConnectionAssistant({
    view, runUuid, run, gate, accountingGate, status, saving, tr, decisions, rows,
    totals, accounting, assistantStep, setAssistantStep, exceptionSearch,
    setExceptionSearch, allowedActions, canEdit, editableState, decide,
    bulkSelection, toggleBulk, bulk, exportComparison, start, runAction,
    confirmation, setConfirmation, PhaseBadges,
}) {
    const guided = view.guided_setup;
    const checks = guided.checks || {};
    const groups = guided.exception_groups || {};
    const byFingerprint = new Map(rows.map((row) => [row.fingerprint, row]));
    const groupOrder = ['items', 'inventory_quantities', 'units', 'warehouses', 'parties', 'accounting', 'currencies', 'cutoff_documents'];
    const query = exceptionSearch.trim().toLowerCase();
    const dynamicText = (prefix, value, fallback) => tr([prefix, value].join(''), {}, fallback ?? value);
    const groupRows = (group) => (groups[group] || [])
        .map((fingerprint) => byFingerprint.get(fingerprint))
        .filter(Boolean)
        .filter((row) => !query || [
            row.solastock?.name, row.solabooks?.name, row.solastock?.sku,
            row.solabooks?.sku, row.safe_details?.role,
        ].filter(Boolean).join(' ').toLowerCase().includes(query));
    const exactRows = rows.filter((row) => row.classification === 'exact_candidate_requires_owner_review');
    const step = Math.min(3, Math.max(1, assistantStep));
    const checkRows = [
        ['organization', checks.organization_verified, tr('integration.assistant.organizationVerified')],
        ['finance', checks.finance_connected, tr('integration.assistant.financeConnected')],
        ['currency', checks.base_currency_inherited, tr('integration.assistant.baseCurrencyInherited', { currency: guided.currency_summary?.base_currency || '—' })],
        ['accounts', checks.account_exceptions === 0, tr('integration.assistant.accountsSummary', { resolved: checks.accounts_resolved || 0, exceptions: checks.account_exceptions || 0 })],
        ['taxes', checks.tax_exceptions === 0, tr('integration.assistant.taxesSummary', { resolved: checks.taxes_resolved || 0, exceptions: checks.tax_exceptions || 0 })],
        ['items', checks.item_exceptions === 0, tr('integration.assistant.itemsSummary', { exact: checks.items_exact || 0, exceptions: checks.item_exceptions || 0 })],
    ];
    const exceptionCount = new Set(guided.visible_exception_fingerprints || []).size;
    const saveDraft = async () => {
        if (!runUuid) return start();
        await run.refetch();
    };

    const renderDecisionRow = (row) => <div className="assistant-exception-row" key={row.fingerprint}>
        <div className="assistant-exception-main">
            <strong>{row.solastock?.name || row.solabooks?.name || dynamicText('integration.wizard.entity.', row.entity_type)}</strong>
            <span>{row.solastock?.sku || row.solabooks?.sku || row.solastock?.code || row.solabooks?.code || '—'}</span>
        </div>
        <div className="assistant-exception-reason">{dynamicText('integration.wizard.block.', row.blocking_reason)}</div>
        <label className="assistant-decision">
            <span className="sr-only">{tr('integration.wizard.decision')}</span>
            <select className="input" disabled={!runUuid || !editableState || !canEdit(row) || saving}
                value={decisions.get(row.fingerprint)?.action || ''}
                onChange={(event) => event.target.value && decide(row, event.target.value)}>
                <option value="">{tr(runUuid ? 'integration.wizard.choose' : 'integration.wizard.pendingDecision')}</option>
                {allowedActions(row).map((action) => <option key={action} value={action}>{dynamicText('integration.wizard.action.', action)}</option>)}
            </select>
        </label>
    </div>;

    return <div className="wizard assistant" aria-live="polite">
        <div className="assistant-status">
            <div><strong>{tr('integration.assistant.title')}</strong><p>{tr('integration.phase.setupWhilePaused')}</p></div>
            <PhaseBadges status={status} setupAllowed={gate.allowed} tr={tr} />
        </div>

        <nav className="assistant-progress" aria-label={tr('integration.assistant.progress')}>
            {[1, 2, 3].map((number) => <button key={number} type="button"
                className={step === number ? 'is-current' : ''}
                onClick={() => setAssistantStep(number)}>
                <span>{number}</span>{dynamicText('integration.assistant.step', number)}
            </button>)}
        </nav>

        {step === 1 && <section className="assistant-card">
            <header><h2>{tr('integration.assistant.step1')}</h2><p>{tr('integration.assistant.automaticDescription')}</p></header>
            <div className="assistant-checks">
                {checkRows.map(([key, passed, label]) => <div className="assistant-check" key={key}>
                    <span className={passed ? 'check-ok' : 'check-attention'} aria-hidden="true">{passed ? '✓' : '!'}</span>
                    <span>{label}</span>
                </div>)}
            </div>
            <div className="assistant-summary-grid">
                <div><span>{tr('integration.assistant.itemsMatched')}</span><strong>{checks.items_exact || 0}</strong></div>
                <div><span>{tr('integration.assistant.needsAttention')}</span><strong>{exceptionCount}</strong></div>
                <div><span>{tr('integration.assistant.quantityDifference')}</span><strong>{totals.total_quantity_difference || '0.0000'}</strong></div>
                <div><span>{tr('integration.assistant.valueDifference')}</span><strong>{totals.total_valuation_difference || '0.00'} {accounting.base_currency || guided.currency_summary?.base_currency || ''}</strong></div>
            </div>
            {accountingGate.allowed && <details className="assistant-details">
                <summary>{tr('integration.assistant.accountingProfile')}</summary>
                <div className="assistant-profile">
                    {rows.filter((row) => row.entity_type === 'account_role' && row.classification === 'candidate_requires_accountant_approval').map((row) =>
                        <div key={row.fingerprint}>
                            <strong>{dynamicText('integration.mapping.', row.safe_details?.role)}</strong>
                            <span>{row.solabooks?.code} · {row.solabooks?.name}</span>
                        </div>)}
                </div>
            </details>}
        </section>}

        {step === 2 && <section className="assistant-card">
            <header><h2>{tr('integration.assistant.step2')}</h2><p>{tr('integration.assistant.exceptionsDescription')}</p></header>
            <label className="assistant-search">
                <span className="sr-only">{tr('integration.assistant.search')}</span>
                <input className="input" type="search" value={exceptionSearch}
                    onChange={(event) => setExceptionSearch(event.target.value)}
                    placeholder={tr('integration.assistant.search')} />
            </label>
            {groupOrder.map((group) => {
                const exceptionRows = groupRows(group);
                const count = (groups[group] || []).length;
                if (group === 'inventory_quantities') return <div className="assistant-group assistant-group--summary" key={group}>
                    <span className={count ? 'check-attention' : 'check-ok'}>{count ? '!' : '✓'}</span>
                    <div><strong>{dynamicText('integration.assistant.group.', group)}</strong>
                        <p>{count ? tr('integration.assistant.physicalCountRequired', { count }) : tr('integration.assistant.noExceptions')}</p></div>
                    <span className="assistant-count">{count}</span>
                </div>;
                return <details className="assistant-group" key={group} open={count > 0 && count <= 7}>
                    <summary>
                        <span className={count ? 'check-attention' : 'check-ok'}>{count ? '!' : '✓'}</span>
                        <span>{dynamicText('integration.assistant.group.', group)}</span>
                        <span className="assistant-count">{count}</span>
                    </summary>
                    {count === 0 ? <p>{tr('integration.assistant.noExceptions')}</p> : <>
                        {group === 'accounting' && <a className="btn btn--sm" href="/finance/accounts" target="_blank" rel="noreferrer">{tr('integration.assistant.openFinanceSettings')}</a>}
                        <div className="assistant-exceptions">{exceptionRows.map(renderDecisionRow)}</div>
                    </>}
                </details>;
            })}
            {exactRows.length > 0 && <div className="assistant-bulk">
                <p>{tr('integration.assistant.exactBulk', { count: exactRows.length })}</p>
                <button className="btn" disabled={!runUuid || saving}
                    onClick={() => exactRows.forEach((row) => !bulkSelection.includes(row.fingerprint) && toggleBulk(row.fingerprint))}>
                    {tr('integration.assistant.selectExact')}
                </button>
                <button className="btn btn--primary" disabled={saving || !exactRows.every((row) => bulkSelection.includes(row.fingerprint))}
                    onClick={() => bulk('approve_exact_sku_candidates')}>{tr('integration.wizard.bulkExact')}</button>
            </div>}
        </section>}

        {step === 3 && <section className="assistant-card">
            <header><h2>{tr('integration.assistant.step3')}</h2><p>{tr('integration.assistant.reviewDescription')}</p></header>
            <dl className="assistant-review">
                <dt>{tr('integration.assistant.inventoryAuthority')}</dt><dd>{tr('integration.assistant.solastockAuthority')}</dd>
                <dt>{tr('integration.wizard.quantityDifference')}</dt><dd>{totals.total_quantity_difference || '0.0000'}</dd>
                <dt>{tr('integration.wizard.valueDifference')}</dt><dd>{totals.total_valuation_difference || '0.00'} {accounting.base_currency || ''}</dd>
                <dt>{tr('integration.assistant.physicalCount')}</dt><dd>{groups.inventory_quantities?.length ? tr('integration.assistant.required') : tr('integration.assistant.complete')}</dd>
                <dt>{tr('integration.wizard.cutoff')}</dt><dd>{view.cutoff_at || tr('integration.wizard.notFrozen')}</dd>
                <dt>{tr('integration.assistant.excludedHistory')}</dt><dd>{guided.historical_exclusion_count || 0}</dd>
                <dt>{tr('integration.assistant.unresolved')}</dt><dd>{view.blocking?.length ?? exceptionCount}</dd>
            </dl>
            {totals.proposed_accounting_effect?.amount && <div className="assistant-correction">
                <strong>{tr('integration.wizard.separateApproval')}</strong>
                <span>{totals.proposed_accounting_effect.amount} {accounting.base_currency || guided.currency_summary?.base_currency}</span>
                <p>{tr('integration.assistant.previewOnly')}</p>
            </div>}
            <p>{tr('integration.assistant.rollback')}</p>
            <details className="assistant-details">
                <summary>{tr('integration.assistant.advanced')}</summary>
                <dl className="kv">
                    <dt>{tr('integration.wizard.snapshot')}</dt><dd className="wizard__hash">{view.snapshot_hash}</dd>
                    <dt>{tr('integration.assistant.sourceHash')}</dt><dd className="wizard__hash">{guided.source_invalidation_hash}</dd>
                    <dt>{tr('integration.assistant.currencyRemediation')}</dt><dd>{guided.currency_summary?.advanced_remediation_count || 0} · {(guided.currency_summary?.reserved_codes || []).join(', ') || '—'}</dd>
                </dl>
            </details>
            {['preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'].includes(run.data?.state) &&
                <div className="assistant-final-approval">
                    <label className="field"><span className="field-label">{tr('integration.wizard.confirmation')}</span>
                        <input className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
                    <button className="btn btn--primary" disabled={!gate.allowed || saving || Boolean(run.data.owner_approved_at)}
                        onClick={() => runAction(() => api.approveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash, confirmation }), 'integration.wizard.ownerApproved')}>
                        {tr('integration.wizard.ownerApprove')}
                    </button>
                    {accountingGate.allowed && <button className="btn" disabled={saving || Boolean(run.data.accountant_approved_at)}
                        onClick={() => runAction(() => api.accountantApproveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash }), 'integration.wizard.accountantApproved')}>
                        {tr('integration.wizard.accountantApprove')}
                    </button>}
                </div>}
        </section>}

        <div className="assistant-actionbar">
            <button className="btn" disabled={saving || !gate.allowed} onClick={saveDraft}>{tr('integration.assistant.saveDraft')}</button>
            <button className="btn" disabled={step >= 3} onClick={() => setAssistantStep(Math.min(3, step + 1))}>{tr('integration.assistant.continue')}</button>
            <button className="btn btn--primary" onClick={() => setAssistantStep(3)}>{tr('integration.assistant.review')}</button>
            <button className="btn" onClick={exportComparison}>{tr('integration.wizard.export')}</button>
        </div>
    </div>;
}
