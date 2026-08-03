import React from 'react';
import { api } from '../services/api.js';

export default function GuidedConnectionAssistant({
    view, runUuid, run, gate, accountingGate, status, saving, tr, decisions, rows,
    totals, accounting, assistantStep, setAssistantStep, exceptionSearch,
    setExceptionSearch, allowedActions, canEdit, editableState, decide,
    bulkSelection, toggleBulk, bulk, exportComparison, start, runAction,
    confirmation, setConfirmation, organizationName,
}) {
    const guided = view.guided_setup;
    const checks = guided.checks || {};
    const groups = guided.exception_groups || {};
    const byFingerprint = new Map(rows.map((row) => [row.fingerprint, row]));
    const groupOrder = ['items', 'units', 'parties', 'accounting', 'currencies', 'cutoff_documents', 'inventory_quantities'];
    const query = exceptionSearch.trim().toLowerCase();
    const dynamicText = (prefix, value, fallback) => tr([prefix, value].join(''), {}, fallback ?? value);
    const groupRows = (group) => (groups[group] || [])
        .map((fingerprint) => byFingerprint.get(fingerprint))
        .filter(Boolean)
        .filter((row) => !query || [row.solastock?.name, row.solabooks?.name, row.solastock?.sku,
            row.solabooks?.sku, row.safe_details?.role].filter(Boolean).join(' ').toLowerCase().includes(query));
    const exactRows = rows.filter((row) => row.classification === 'exact_candidate_requires_owner_review');
    const step = Math.min(3, Math.max(1, assistantStep));
    const exceptionFingerprints = [...new Set(guided.visible_exception_fingerprints || [])];
    const completedDecisions = exceptionFingerprints.filter((fingerprint) => decisions.has(fingerprint)).length;
    const remaining = Math.max(0, exceptionFingerprints.length - completedDecisions);
    const progress = exceptionFingerprints.length === 0 ? 100 : Math.round((completedDecisions / exceptionFingerprints.length) * 80 + 20);
    const automaticChecks = [checks.organization_verified, checks.finance_connected, checks.base_currency_inherited,
        checks.account_exceptions === 0, checks.tax_exceptions === 0].filter(Boolean).length;
    const stepMeta = [
        { number: 1, label: tr('integration.assistant.step1'), count: checks.account_exceptions + checks.tax_exceptions, complete: automaticChecks === 5, description: tr('integration.assistant.automaticDescription') },
        { number: 2, label: tr('integration.assistant.step2'), count: remaining, complete: remaining === 0, description: tr('integration.assistant.exceptionsDescription') },
        { number: 3, label: tr('integration.assistant.step3'), count: view.blocking?.length || remaining, complete: view.state === 'activation_ready', description: tr('integration.assistant.reviewDescription') },
    ];
    const checkRows = [
        [checks.organization_verified, tr('integration.assistant.organizationVerified')],
        [checks.finance_connected, tr('integration.assistant.financeConnected')],
        [checks.base_currency_inherited, tr('integration.assistant.baseCurrencyInherited', { currency: guided.currency_summary?.base_currency || '—' })],
        [checks.account_exceptions === 0, tr('integration.assistant.accountsSummary', { resolved: checks.accounts_resolved || 0, exceptions: checks.account_exceptions || 0 })],
        [checks.tax_exceptions === 0, tr('integration.assistant.taxesSummary', { resolved: checks.taxes_resolved || 0, exceptions: checks.tax_exceptions || 0 })],
        [checks.item_exceptions === 0, tr('integration.assistant.itemsSummary', { exact: checks.items_exact || 0, exceptions: checks.item_exceptions || 0 })],
    ];
    const firstIncomplete = groupOrder.find((group) => (groups[group] || []).some((fingerprint) => !decisions.has(fingerprint)));
    const selectedEffect = (row) => {
        const action = decisions.get(row.fingerprint)?.action;
        return action ? dynamicText('integration.assistant.effect.', action) : tr('integration.assistant.effect.pending');
    };
    const placeholder = (row) => row.classification === 'exact_candidate_requires_owner_review'
        ? tr('integration.assistant.chooseMatch')
        : row.entity_type === 'item'
            ? tr('integration.assistant.chooseItemTreatment')
            : tr('integration.assistant.chooseRequiredAction');
    const saveDraft = async () => {
        if (!runUuid) return start();
        await run.refetch();
    };

    const renderDecisionRow = (row) => {
        const decision = decisions.get(row.fingerprint);
        const source = row.solabooks || row.solastock;
        const proposed = row.solabooks && row.solastock
            ? `${row.solastock.name || 'SolaStock'} ↔ ${row.solabooks.name || 'SolaBooks'}`
            : dynamicText('integration.assistant.proposal.', row.classification);
        return <article className="assistant-exception-row" key={row.fingerprint}>
            <div className="assistant-record">
                <span className="assistant-row-label">{tr('integration.assistant.sourceRecord')}</span>
                <strong>{source?.name || dynamicText('integration.wizard.entity.', row.entity_type)}</strong>
                <small>{[source?.sku && `${tr('integration.assistant.sku')}: ${source.sku}`, source?.code, source?.barcode].filter(Boolean).join(' · ') || tr('integration.assistant.noReference')}</small>
            </div>
            <div className="assistant-proposal">
                <span className="assistant-row-label">{tr('integration.assistant.proposedAction')}</span>
                <strong>{proposed}</strong>
                <small>{dynamicText('integration.wizard.block.', row.blocking_reason)}</small>
            </div>
            <label className="assistant-decision">
                <span className="assistant-row-label">{tr('integration.wizard.decision')}</span>
                <select className="input" disabled={!runUuid || !editableState || !canEdit(row) || saving}
                    value={decision?.action || ''}
                    onChange={(event) => event.target.value && decide(row, event.target.value)}>
                    <option value="">{placeholder(row)}</option>
                    {allowedActions(row).map((action) => <option key={action} value={action}>{dynamicText('integration.wizard.action.', action)}</option>)}
                </select>
                <small>{selectedEffect(row)}</small>
            </label>
            <span className={`assistant-row-status ${decision ? 'is-resolved' : 'is-pending'}`}>
                {tr(decision ? 'integration.assistant.resolved' : 'integration.assistant.pending')}
            </span>
        </article>;
    };

    const actions = <div className="assistant-actionbar">
        <button className="btn" disabled={saving || !gate.allowed} onClick={saveDraft}>{tr('integration.assistant.saveDraft')}</button>
        <button className="btn btn--primary" disabled={step >= 3} onClick={() => setAssistantStep(Math.min(3, step + 1))}>{tr('integration.assistant.saveContinue')}</button>
        <button className="btn" onClick={() => setAssistantStep(3)}>{tr('integration.assistant.reviewSummary')}</button>
        <button className="btn assistant-export" onClick={exportComparison}>{tr('integration.wizard.export')}</button>
    </div>;

    return <div className="wizard assistant" aria-live="polite">
        <header className="assistant-hero">
            <div className="assistant-hero-copy">
                <p className="assistant-kicker">{tr('integration.assistant.kicker')}</p>
                <h1>{tr('integration.assistant.pageTitle')}</h1>
                <p>{tr('integration.assistant.pageIntroduction')}</p>
                <div className="assistant-context">
                    <strong>{organizationName || tr('integration.assistant.currentOrganization')}</strong>
                    <span>{tr('integration.assistant.overallProgress', { progress })}</span>
                </div>
            </div>
            <div className="assistant-next">
                <span>{tr('integration.assistant.nextAction')}</span>
                <strong>{stepMeta.find((item) => !item.complete)?.label || tr('integration.assistant.readyForReview')}</strong>
                <button className="btn btn--primary" onClick={() => setAssistantStep(remaining ? 2 : 3)}>{tr(remaining ? 'integration.assistant.continueSetup' : 'integration.assistant.reviewSummary')}</button>
            </div>
            <div className="assistant-status-line" role="status">
                <strong>{tr('integration.assistant.statusLine', { remaining })}</strong>
                <span>{tr('integration.assistant.safePause')}</span>
                <details><summary>{tr('integration.assistant.statusDetails')}</summary>
                    <p>{tr('integration.assistant.statusDetailsText', { activation: status?.activation_status || 'safely_paused' })}</p>
                </details>
            </div>
        </header>

        <nav className="assistant-progress" aria-label={tr('integration.assistant.progress')}>
            {stepMeta.map((item) => <button key={item.number} type="button"
                className={`${step === item.number ? 'is-current' : ''} ${item.complete ? 'is-complete' : ''}`}
                aria-current={step === item.number ? 'step' : undefined}
                onClick={() => setAssistantStep(item.number)}>
                <span className="assistant-step-number">{item.complete ? '✓' : item.number}</span>
                <span className="assistant-step-copy"><strong>{item.label}</strong>{step === item.number && <small>{item.description}</small>}</span>
                <span className="assistant-step-count">{item.count}</span>
            </button>)}
        </nav>

        <div className="assistant-layout">
            <main className="assistant-main">
                {step === 1 && <section className="assistant-card">
                    <header><h2>{tr('integration.assistant.step1')}</h2><p>{tr('integration.assistant.automaticDescription')}</p></header>
                    <div className="assistant-checks">
                        {checkRows.map(([passed, label]) => <div className="assistant-check" key={label}>
                            <span className={passed ? 'check-ok' : 'check-attention'} aria-hidden="true">{passed ? '✓' : '!'}</span>
                            <span>{label}</span>
                        </div>)}
                    </div>
                    {accountingGate.allowed && <details className="assistant-details">
                        <summary>{tr('integration.assistant.accountingProfile')}</summary>
                        <div className="assistant-profile">{rows.filter((row) => row.entity_type === 'account_role' && row.classification === 'candidate_requires_accountant_approval').map((row) =>
                            <div key={row.fingerprint}><strong>{dynamicText('integration.mapping.', row.safe_details?.role)}</strong><span>{row.solabooks?.code} · {row.solabooks?.name}</span></div>)}</div>
                    </details>}
                    {actions}
                </section>}

                {step === 2 && <section className="assistant-card">
                    <header><h2>{tr('integration.assistant.exceptionsHeading')}</h2><p>{tr('integration.assistant.exceptionsDescription')}</p></header>
                    <label className="assistant-search"><span className="sr-only">{tr('integration.assistant.search')}</span>
                        <input className="input" type="search" value={exceptionSearch} onChange={(event) => setExceptionSearch(event.target.value)} placeholder={tr('integration.assistant.search')} />
                    </label>
                    <div className="assistant-groups">{groupOrder.map((group) => {
                        const fingerprints = groups[group] || [];
                        const groupResolved = fingerprints.filter((fingerprint) => decisions.has(fingerprint)).length;
                        const groupRemaining = fingerprints.length - groupResolved;
                        const physicalCount = group === 'inventory_quantities';
                        return <details className={`assistant-group ${groupRemaining === 0 ? 'is-complete' : ''}`} key={group} open={group === firstIncomplete}>
                            <summary>
                                <span className={groupRemaining ? 'check-attention' : 'check-ok'}>{groupRemaining ? '!' : '✓'}</span>
                                <span className="assistant-group-title"><strong>{dynamicText('integration.assistant.group.', group)}</strong>
                                    <small>{groupRemaining ? dynamicText('integration.assistant.groupNext.', group) : tr('integration.assistant.noExceptions')}</small></span>
                                <span className="assistant-group-metrics"><small>{tr('integration.assistant.resolvedCount', { count: groupResolved })}</small><strong>{tr('integration.assistant.remainingCount', { count: groupRemaining })}</strong></span>
                            </summary>
                            {groupRemaining === 0 ? <p>{tr('integration.assistant.emptySection')}</p> : physicalCount
                                ? <div className="assistant-physical-warning">{tr('integration.assistant.physicalCountWarning')}</div>
                                : <>{group === 'accounting' && <a className="btn btn--sm" href="/finance/accounts" target="_blank" rel="noreferrer">{tr('integration.assistant.openFinanceSettings')}</a>}
                                    <div className="assistant-exceptions">{groupRows(group).map(renderDecisionRow)}</div></>}
                        </details>;
                    })}</div>
                    {exactRows.length > 0 && <div className="assistant-bulk"><p>{tr('integration.assistant.exactBulk', { count: exactRows.length })}</p>
                        <button className="btn" disabled={!runUuid || saving} onClick={() => exactRows.forEach((row) => !bulkSelection.includes(row.fingerprint) && toggleBulk(row.fingerprint))}>{tr('integration.assistant.selectExact')}</button>
                        <button className="btn btn--primary" disabled={saving || !exactRows.every((row) => bulkSelection.includes(row.fingerprint))} onClick={() => bulk('approve_exact_sku_candidates')}>{tr('integration.wizard.bulkExact')}</button></div>}
                    {actions}
                </section>}

                {step === 3 && <section className="assistant-card">
                    <header><h2>{tr('integration.assistant.step3')}</h2><p>{tr('integration.assistant.reviewDescription')}</p></header>
                    <div className="assistant-review-dashboard">
                        {[
                            ['inventoryAuthority', tr('integration.assistant.solastockAuthority'), true],
                            ['matchedItems', checks.items_exact || 0, true],
                            ['excludedServices', decisions.size ? [...decisions.values()].filter((d) => d.action === 'classify_service_non_inventory').length : 0, true],
                            ['physicalCount', groups.inventory_quantities?.length ? tr('integration.assistant.required') : tr('integration.assistant.complete'), !groups.inventory_quantities?.length],
                            ['quantityDifference', totals.total_quantity_difference || '0.0000', totals.total_quantity_difference === '0.0000'],
                            ['valueDifference', `${totals.total_valuation_difference || '0.00'} ${accounting.base_currency || ''}`, totals.total_valuation_difference === '0.00'],
                            ['financeGl', accounting.inventory_control_balance ?? tr('integration.assistant.pending'), false],
                            ['cutoff', view.cutoff_at || tr('integration.wizard.notFrozen'), Boolean(view.cutoff_at)],
                            ['excludedHistory', guided.historical_exclusion_count || 0, true],
                            ['ownerApproval', run.data?.owner_approved_at ? tr('integration.assistant.complete') : tr('integration.assistant.pending'), Boolean(run.data?.owner_approved_at)],
                            ['accountantApproval', run.data?.accountant_approved_at ? tr('integration.assistant.complete') : tr('integration.assistant.pending'), Boolean(run.data?.accountant_approved_at)],
                        ].map(([key, value, passed]) => <div className="assistant-review-tile" key={key}><span className={passed ? 'check-ok' : 'check-attention'}>{passed ? '✓' : '!'}</span><div><small>{dynamicText('integration.assistant.review.', key)}</small><strong>{value}</strong></div></div>)}
                    </div>
                    {totals.proposed_accounting_effect?.amount && <div className="assistant-correction"><strong>{tr('integration.assistant.review.proposedCorrection')}</strong><span>{totals.proposed_accounting_effect.amount} {accounting.base_currency || guided.currency_summary?.base_currency}</span><p>{tr('integration.assistant.previewOnly')}</p></div>}
                    {remaining > 0 && <div className="assistant-blockers"><h3>{tr('integration.assistant.blockerList')}</h3><p>{tr('integration.assistant.blockerDescription')}</p>
                        {groupOrder.filter((group) => (groups[group] || []).some((fingerprint) => !decisions.has(fingerprint))).map((group) =>
                            <button key={group} className="btn" onClick={() => setAssistantStep(2)}>{dynamicText('integration.assistant.group.', group)} · {tr('integration.assistant.remainingCount', { count: (groups[group] || []).filter((fingerprint) => !decisions.has(fingerprint)).length })}</button>)}</div>}
                    <p className="assistant-rollback">{tr('integration.assistant.rollback')}</p>
                    <details className="assistant-details"><summary>{tr('integration.assistant.advanced')}</summary><dl className="kv">
                        <dt>{tr('integration.wizard.snapshot')}</dt><dd className="wizard__hash">{view.snapshot_hash}</dd>
                        <dt>{tr('integration.assistant.sourceHash')}</dt><dd className="wizard__hash">{guided.source_invalidation_hash}</dd>
                        <dt>{tr('integration.assistant.currencyRemediation')}</dt><dd>{guided.currency_summary?.advanced_remediation_count || 0} · {(guided.currency_summary?.reserved_codes || []).join(', ') || '—'}</dd>
                    </dl></details>
                    {['preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'].includes(run.data?.state) && <div className="assistant-final-approval">
                        <label className="field"><span className="field-label">{tr('integration.wizard.confirmation')}</span><input className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
                        <button className="btn btn--primary" disabled={!gate.allowed || saving || Boolean(run.data.owner_approved_at)} onClick={() => runAction(() => api.approveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash, confirmation }), 'integration.wizard.ownerApproved')}>{tr('integration.wizard.ownerApprove')}</button>
                        {accountingGate.allowed && <button className="btn" disabled={saving || Boolean(run.data.accountant_approved_at)} onClick={() => runAction(() => api.accountantApproveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash }), 'integration.wizard.accountantApproved')}>{tr('integration.wizard.accountantApprove')}</button>}
                    </div>}
                    {actions}
                </section>}
            </main>

            <aside className="assistant-side" aria-label={tr('integration.assistant.summary')}>
                <div className="assistant-side-card"><div className="assistant-progress-ring" style={{ '--progress': `${progress * 3.6}deg` }}><strong>{progress}%</strong></div>
                    <div><h2>{tr('integration.assistant.setupProgress')}</h2><p>{tr('integration.assistant.remainingDecisions', { count: remaining })}</p></div></div>
                <div className="assistant-side-card assistant-side-list"><h3>{tr('integration.assistant.summary')}</h3>
                    <p><span className="check-ok">✓</span>{tr('integration.assistant.completedChecks', { count: automaticChecks, total: 5 })}</p>
                    <p><span className={remaining ? 'check-attention' : 'check-ok'}>{remaining ? '!' : '✓'}</span>{tr('integration.assistant.remainingDecisions', { count: remaining })}</p>
                    <p><span className="check-attention">!</span>{tr('integration.assistant.activationPaused')}</p></div>
                <div className="assistant-side-card"><h3>{tr('integration.assistant.nextRequirement')}</h3><p>{stepMeta.find((item) => !item.complete)?.description || tr('integration.assistant.readyForReview')}</p></div>
            </aside>
        </div>
    </div>;
}
