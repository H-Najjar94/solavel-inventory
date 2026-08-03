import React, { useState } from 'react';
import { api } from '../services/api.js';

export default function GuidedConnectionAssistant({
    view, runUuid, run, gate, accountingGate, status, saving, tr, decisions, rows,
    totals, accounting, assistantStep, setAssistantStep, exceptionSearch,
    setExceptionSearch, allowedActions, canEdit, editableState, decide,
    bulkSelection, toggleBulk, bulk, exportComparison, start, runAction,
    confirmation, setConfirmation, organizationName, saveState, retrySave, reloadLatest,
}) {
    const [bulkReviewOpen, setBulkReviewOpen] = useState(false);
    const guided = view.guided_setup;
    const checks = guided.checks || {};
    const groups = guided.exception_groups || {};
    const byFingerprint = new Map(rows.map((row) => [row.fingerprint, row]));
    const groupOrder = ['items', 'units', 'parties', 'accounting', 'currencies', 'cutoff_documents', 'inventory_quantities'];
    const groupFingerprints = (group) => [...new Set([
        ...(groups[group] || []),
        ...(group === 'units' ? (groups.warehouses || []) : []),
    ])];
    const query = exceptionSearch.trim().toLowerCase();
    const dynamicText = (prefix, value, fallback) => tr([prefix, value].join(''), {}, fallback ?? value);
    const groupRows = (group) => groupFingerprints(group)
        .map((fingerprint) => byFingerprint.get(fingerprint))
        .filter(Boolean)
        .filter((row) => !query || [row.solastock?.name, row.solabooks?.name, row.solastock?.sku,
            row.solabooks?.sku, row.safe_details?.role].filter(Boolean).join(' ').toLowerCase().includes(query));
    const exactRows = rows.filter((row) => row.classification === 'exact_candidate_requires_owner_review');
    const step = Math.min(3, Math.max(1, assistantStep));
    const exceptionFingerprints = [...new Set(guided.visible_exception_fingerprints || [])];
    const completedDecisions = exceptionFingerprints.filter((fingerprint) => decisions.has(fingerprint)).length;
    const remaining = Math.max(0, exceptionFingerprints.length - completedDecisions);
    const completedGroups = groupOrder.filter((group) => groupFingerprints(group).every((fingerprint) => decisions.has(fingerprint))).length;
    const automaticChecks = [checks.organization_verified, checks.finance_connected, checks.base_currency_inherited,
        checks.account_exceptions === 0, checks.tax_exceptions === 0, checks.item_exceptions === 0].filter(Boolean).length;
    const reviewGatesComplete = remaining === 0
        && ['preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'].includes(run.data?.state)
        && Boolean(view.cutoff_at || view.snapshot_frozen_at);
    const approvalAvailable = reviewGatesComplete;
    const formatNumber = (value, maximumFractionDigits = 2) => {
        if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';
        return Number(value).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits });
    };
    const currencyCode = accounting.base_currency || guided.currency_summary?.base_currency || '';
    const currencyLabel = currencyCode === 'JOD' ? tr('integration.assistant.jod') : currencyCode;
    const formatMoney = (value) => {
        if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';
        return `${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currencyLabel}`.trim();
    };
    const stepMeta = [
        { number: 1, label: tr('integration.assistant.step1'), count: tr('integration.assistant.checkMetric', { done: automaticChecks, total: 6 }), complete: automaticChecks === 6, description: tr('integration.assistant.automaticDescription') },
        { number: 2, label: tr('integration.assistant.step2'), count: tr('integration.assistant.decisionMetric', { groups: groupOrder.length, records: remaining }), complete: remaining === 0, description: tr('integration.assistant.exceptionsDescription') },
        { number: 3, label: tr(reviewGatesComplete ? 'integration.assistant.step3' : 'integration.assistant.previewStep'), count: tr(reviewGatesComplete ? 'integration.assistant.readyForReview' : 'integration.assistant.notReady'), complete: view.state === 'activation_ready', blocked: !reviewGatesComplete, description: tr(reviewGatesComplete ? 'integration.assistant.reviewDescription' : 'integration.assistant.previewDescription') },
    ];
    const currentTask = remaining > 0 ? stepMeta[1] : stepMeta[2];
    const checkRows = [
        [checks.organization_verified, tr('integration.assistant.organizationVerified')],
        [checks.finance_connected, tr('integration.assistant.financeConnected')],
        [checks.base_currency_inherited, tr('integration.assistant.baseCurrencyInherited', { currency: guided.currency_summary?.base_currency || '—' })],
        [checks.account_exceptions === 0, tr('integration.assistant.accountsSummary', { resolved: checks.accounts_resolved || 0, exceptions: checks.account_exceptions || 0 })],
        [checks.tax_exceptions === 0, tr('integration.assistant.taxesSummary', { resolved: checks.taxes_resolved || 0, exceptions: checks.tax_exceptions || 0 })],
        [checks.item_exceptions === 0, tr('integration.assistant.itemsSummary', { exact: checks.items_exact || 0, exceptions: checks.item_exceptions || 0 })],
    ];
    const incompleteChecks = checkRows.filter(([passed]) => !passed);
    const completedChecks = checkRows.filter(([passed]) => passed);
    const completedCheckRegression = Boolean(guided.completed_check_error);
    const firstIncomplete = groupOrder.find((group) => groupFingerprints(group).some((fingerprint) => !decisions.has(fingerprint)));
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
        const namesMatch = row.solabooks?.name && row.solastock?.name
            && row.solabooks.name.trim().toLocaleLowerCase() === row.solastock.name.trim().toLocaleLowerCase();
        const skusMatch = row.solabooks?.sku && row.solastock?.sku
            && row.solabooks.sku.trim().toLocaleLowerCase() === row.solastock.sku.trim().toLocaleLowerCase();
        const hasDifference = Number(row.quantity_difference || 0) !== 0 || Number(row.value_difference || 0) !== 0;
        const proposed = row.solabooks && row.solastock
            ? tr('integration.assistant.exactMatchProposal')
            : dynamicText('integration.assistant.proposal.', row.classification);
        return <article className="assistant-exception-row" key={row.fingerprint}>
            <div className="assistant-record">
                <span className="assistant-row-label">{row.solabooks ? tr('integration.assistant.financeItem') : tr('integration.assistant.stockRecord')}</span>
                <strong><bdi>{source?.name || dynamicText('integration.wizard.entity.', row.entity_type)}</bdi></strong>
                <small>{source?.sku ? <><span>{tr('integration.assistant.sku')}: </span><bdi>{source.sku}</bdi></> : tr('integration.assistant.noReference')}</small>
            </div>
            <div className="assistant-proposal">
                <span className="assistant-row-label">{row.solastock ? tr('integration.assistant.proposedStockItem') : tr('integration.assistant.proposedAction')}</span>
                <strong><bdi>{row.solastock?.name || proposed}</bdi></strong>
                <small>{namesMatch && skusMatch ? tr('integration.assistant.identicalNameSku') : row.solabooks && row.solastock ? tr('integration.assistant.matchBasisExactSku') : dynamicText('integration.wizard.block.', row.blocking_reason)}</small>
                {hasDifference && <strong className="assistant-difference">{tr('integration.assistant.meaningfulDifference', { quantity: formatNumber(row.quantity_difference, 4), value: formatMoney(row.value_difference) })}</strong>}
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
            <details className="assistant-row-technical"><summary>{tr('integration.assistant.technicalDetails')}</summary><small><bdi>{dynamicText('integration.wizard.block.', row.blocking_reason)} · {row.fingerprint}</bdi></small></details>
        </article>;
    };

    const actions = <div className="assistant-actionbar">
        <span className={`assistant-save-state is-${saveState || 'idle'}`} role="status">
            {saving || saveState === 'saving' ? tr('integration.assistant.saving') : saveState === 'saved' ? tr('integration.assistant.savedAutomatically') : saveState === 'failed' ? tr('integration.assistant.saveFailed') : saveState === 'conflict' ? tr('integration.assistant.saveConflict') : ''}
            {saveState === 'failed' && <button type="button" className="btn btn--link" onClick={retrySave}>{tr('integration.assistant.retrySave')}</button>}
            {saveState === 'conflict' && <button type="button" className="btn btn--link" onClick={reloadLatest}>{tr('integration.assistant.reloadLatest')}</button>}
        </span>
        {step < 3 && <button className="btn" disabled={saving} onClick={() => setAssistantStep(Math.min(3, step + 1))}>{tr('integration.assistant.saveContinue')}</button>}
        {step === 3 && remaining > 0 && <button className="btn" onClick={() => setAssistantStep(2)}>{tr('integration.assistant.completeDecisions')}</button>}
        <button className="btn" onClick={() => setAssistantStep(3)}>{tr('integration.assistant.reviewSummary')}</button>
        <details className="assistant-more"><summary>{tr('integration.assistant.moreActions')}</summary><button className="btn" onClick={exportComparison}>{tr('integration.wizard.export')}</button></details>
    </div>;

    return <div className="wizard assistant" aria-live="polite">
        <header className="assistant-hero">
            <div className="assistant-hero-copy">
                <p className="assistant-kicker">{tr('integration.assistant.kicker')}</p>
                <h1>{tr('integration.assistant.pageTitle')}</h1>
                <span className="assistant-step-position">{tr('integration.assistant.stepPosition', { step, total: 3 })}</span>
                <p>{tr('integration.assistant.pageIntroduction')}</p>
                <div className="assistant-context">
                    <strong>{organizationName || tr('integration.assistant.currentOrganization')}</strong>
                    <span>{tr('integration.assistant.groupProgress', { done: completedGroups, total: groupOrder.length })}</span>
                </div>
            </div>
            <div className="assistant-next">
                <span>{tr('integration.assistant.nextAction')}</span>
                <strong>{currentTask.label}</strong>
            </div>
            <div className="assistant-status-line" role="status">
                <span>{tr('integration.assistant.safePause')}</span>
                <details><summary>{tr('integration.assistant.statusDetails')}</summary>
                    <p>{tr('integration.assistant.statusDetailsText', { activation: status?.activation_status || 'safely_paused' })}</p>
                </details>
            </div>
        </header>

        <nav className="assistant-progress" aria-label={tr('integration.assistant.progress')}>
            {stepMeta.map((item) => <button key={item.number} type="button"
                className={`${step === item.number ? 'is-current' : ''} ${item.complete ? 'is-complete' : ''} ${item.blocked ? 'is-blocked' : ''}`}
                aria-current={step === item.number ? 'step' : undefined}
                aria-label={`${item.label}. ${item.complete ? tr('integration.assistant.completed') : item.blocked ? tr('integration.assistant.blocked') : step === item.number ? tr('integration.assistant.current') : tr('integration.assistant.pending')}`}
                disabled={item.number > step && item.number !== 3}
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
                    <div className="assistant-checks assistant-checks--incomplete" aria-label={tr('integration.assistant.incompleteChecks')}>
                        {incompleteChecks.map(([, label]) => <div className="assistant-check" key={label} role="status">
                            <span className="check-attention" aria-hidden="true">!</span>
                            <span>{label}<span className="sr-only"> · {tr('integration.assistant.needsAttention')}</span></span>
                        </div>)}
                    </div>
                    <details className="assistant-checks assistant-checks--completed" open={completedCheckRegression}>
                        <summary>{tr('integration.assistant.completedChecks', { count: completedChecks.length })}</summary>
                        {completedChecks.map(([, label]) => <div className="assistant-check" key={label} role="status">
                            <span className="check-ok" aria-hidden="true">✓</span><span>{label}<span className="sr-only"> · {tr('integration.assistant.completed')}</span></span>
                        </div>)}
                    </details>
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
                    <h3 className="assistant-responsibility">{tr('integration.assistant.ownerDecisions')}</h3>
                    <div className="assistant-groups">{groupOrder.filter((group) => group !== 'accounting').map((group) => {
                        const fingerprints = groupFingerprints(group);
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
                    <h3 className="assistant-responsibility">{tr('integration.assistant.accountantDecisions')}</h3>
                    {!accountingGate.allowed && <div className="assistant-accountant-callout">{tr('integration.assistant.assignAccountant', { count: (groups.accounting || []).length })}</div>}
                    {groupOrder.filter((group) => group === 'accounting').map((group) => {
                        const fingerprints = groupFingerprints(group); const groupResolved = fingerprints.filter((fingerprint) => decisions.has(fingerprint)).length; const groupRemaining = fingerprints.length - groupResolved;
                        return <details className="assistant-group" key={group}><summary><span className={groupRemaining ? 'check-attention' : 'check-ok'}>{groupRemaining ? '!' : '✓'}</span><span className="assistant-group-title"><strong>{dynamicText('integration.assistant.group.', group)}</strong><small>{dynamicText('integration.assistant.groupNext.', group)}</small></span><span className="assistant-group-metrics"><small>{tr('integration.assistant.resolvedCount', { count: groupResolved })}</small><strong>{tr('integration.assistant.remainingCount', { count: groupRemaining })}</strong></span></summary><div className="assistant-exceptions">{groupRows(group).map(renderDecisionRow)}</div></details>;
                    })}
                    {exactRows.length > 0 && <div className="assistant-bulk"><p>{tr('integration.assistant.exactBulk', { count: exactRows.length })}</p>
                        <button className="btn" disabled={!runUuid || saving || !editableState} onClick={() => { exactRows.forEach((row) => !bulkSelection.includes(row.fingerprint) && toggleBulk(row.fingerprint)); setBulkReviewOpen(true); }}>{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</button></div>}
                    {bulkReviewOpen && <div className="assistant-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-review-title">
                        <div className="assistant-bulk-dialog__content"><h3 id="bulk-review-title">{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</h3>
                            <p>{tr('integration.assistant.bulkDraftOnly')}</p>
                            <div className="assistant-bulk-pairs">{exactRows.map((row) => <label key={row.fingerprint}>
                                <input type="checkbox" checked={bulkSelection.includes(row.fingerprint)} onChange={() => toggleBulk(row.fingerprint)} />
                                <span><bdi>{row.solabooks?.name}</bdi> → <bdi>{row.solastock?.name}</bdi><small>{tr('integration.assistant.matchBasisExactSku')} · <bdi>{row.solabooks?.sku}</bdi></small>
                                    {(Number(row.quantity_difference || 0) !== 0 || Number(row.value_difference || 0) !== 0) && <small className="assistant-difference">{tr('integration.assistant.meaningfulDifference', { quantity: formatNumber(row.quantity_difference, 4), value: formatMoney(row.value_difference) })}</small>}
                                </span>
                            </label>)}</div>
                            <div className="doc-actions"><button className="btn" onClick={() => setBulkReviewOpen(false)}>{tr('settings.common.cancel')}</button>
                                <button className="btn" disabled={saving || bulkSelection.length === 0} onClick={async () => { if (await bulk('approve_exact_sku_candidates')) setBulkReviewOpen(false); }}>{tr('integration.assistant.confirmSelectedMatches', { count: bulkSelection.length })}</button></div>
                        </div>
                    </div>}
                    {actions}
                </section>}

                {step === 3 && <section className="assistant-card">
                    <header><h2>{tr(reviewGatesComplete ? 'integration.assistant.step3' : 'integration.assistant.previewStep')}</h2><p>{tr(reviewGatesComplete ? 'integration.assistant.reviewDescription' : 'integration.assistant.previewDescription')}</p></header>
                    <div className="assistant-review-dashboard">
                        {[
                            ['inventoryAuthority', tr('integration.assistant.solastockAuthority'), true],
                            ['matchedItems', checks.items_exact || 0, true],
                            ['excludedServices', decisions.size ? [...decisions.values()].filter((d) => d.action === 'classify_service_non_inventory').length : 0, true],
                            ['physicalCount', groups.inventory_quantities?.length ? tr('integration.assistant.required') : tr('integration.assistant.complete'), !groups.inventory_quantities?.length],
                            ['quantityDifference', <bdi dir="ltr">{formatNumber(totals.total_quantity_difference, 4)}</bdi>, Number(totals.total_quantity_difference) === 0],
                            ['valueDifference', <bdi dir="ltr">{formatMoney(totals.total_valuation_difference)}</bdi>, Number(totals.total_valuation_difference) === 0],
                            ['financeGl', accounting.inventory_control_balance ?? tr('integration.assistant.pending'), false],
                            ['cutoff', view.cutoff_at || tr('integration.wizard.notFrozen'), Boolean(view.cutoff_at)],
                            ['excludedHistory', guided.historical_exclusion_count || 0, true],
                            ['ownerApproval', run.data?.owner_approved_at ? tr('integration.assistant.complete') : tr('integration.assistant.pending'), Boolean(run.data?.owner_approved_at)],
                            ['accountantApproval', run.data?.accountant_approved_at ? tr('integration.assistant.complete') : tr('integration.assistant.pending'), Boolean(run.data?.accountant_approved_at)],
                        ].map(([key, value, passed]) => <div className="assistant-review-tile" key={key}><span className={passed ? 'check-ok' : 'check-attention'}>{passed ? '✓' : '!'}</span><div><small>{dynamicText('integration.assistant.review.', key)}</small><strong>{value}</strong></div></div>)}
                    </div>
                    <div className="assistant-reconciliation-wrap"><table className="assistant-reconciliation">
                        <thead><tr><th>{tr('integration.assistant.reconciliation.measure')}</th><th>{tr('integration.assistant.reconciliation.finance')}</th><th>{tr('integration.assistant.reconciliation.stock')}</th><th>{tr('integration.assistant.reconciliation.difference')}</th><th>{tr('integration.assistant.reconciliation.action')}</th></tr></thead>
                        <tbody>
                            <tr><th>{tr('integration.assistant.reconciliation.quantity')}</th><td><bdi dir="ltr">{formatNumber(totals.solabooks_quantity, 4)}</bdi></td><td><bdi dir="ltr">{formatNumber(totals.solastock_quantity, 4)}</bdi></td><td><bdi dir="ltr">{formatNumber(totals.total_quantity_difference, 4)}</bdi></td><td>{Number(totals.total_quantity_difference) === 0 ? tr('integration.assistant.noAction') : tr('integration.assistant.physicalCountRequired')}</td></tr>
                            <tr><th>{tr('integration.assistant.reconciliation.value')}</th><td><bdi dir="ltr">{formatMoney(totals.solabooks_inventory_value)}</bdi></td><td><bdi dir="ltr">{formatMoney(totals.solastock_inventory_value)}</bdi></td><td><bdi dir="ltr">{formatMoney(totals.total_valuation_difference)}</bdi></td><td>{Number(totals.total_valuation_difference) === 0 ? tr('integration.assistant.noAction') : tr('integration.assistant.accountantAndCount')}</td></tr>
                            <tr><th>{tr('integration.assistant.reconciliation.gl')}</th><td><bdi dir="ltr">{formatMoney(accounting.inventory_control_balance)}</bdi></td><td><bdi dir="ltr">{formatMoney(totals.solastock_inventory_value)}</bdi></td><td><bdi dir="ltr">{formatMoney(Number(totals.solastock_inventory_value || 0) - Number(accounting.inventory_control_balance || 0))}</bdi></td><td>{tr('integration.assistant.accountantReview')}</td></tr>
                        </tbody>
                    </table></div>
                    {totals.proposed_accounting_effect?.amount && <div className="assistant-correction"><strong>{tr('integration.assistant.review.proposedCorrection')}</strong><span><bdi dir="ltr">{formatMoney(totals.proposed_accounting_effect.amount)}</bdi></span><p>{tr('integration.assistant.previewOnly')}</p></div>}
                    {remaining > 0 && <div className="assistant-blockers"><h3>{tr('integration.assistant.blockerList')}</h3><p>{tr('integration.assistant.blockerDescription')}</p>
                        {groupOrder.filter((group) => groupFingerprints(group).some((fingerprint) => !decisions.has(fingerprint))).map((group) =>
                            <button key={group} className="btn" onClick={() => setAssistantStep(2)}>{dynamicText('integration.assistant.group.', group)} · {tr('integration.assistant.remainingCount', { count: groupFingerprints(group).filter((fingerprint) => !decisions.has(fingerprint)).length })}</button>)}</div>}
                    <p className="assistant-rollback">{tr('integration.assistant.rollback')}</p>
                    <details className="assistant-details"><summary>{tr('integration.assistant.advanced')}</summary><dl className="kv">
                        <dt>{tr('integration.wizard.snapshot')}</dt><dd className="wizard__hash">{view.snapshot_hash}</dd>
                        <dt>{tr('integration.assistant.sourceHash')}</dt><dd className="wizard__hash">{guided.source_invalidation_hash}</dd>
                        <dt>{tr('integration.assistant.currencyRemediation')}</dt><dd>{guided.currency_summary?.advanced_remediation_count || 0} · {(guided.currency_summary?.reserved_codes || []).join(', ') || '—'}</dd>
                    </dl></details>
                    {approvalAvailable && <div className="assistant-final-approval">
                        <label className="field"><span className="field-label">{tr('integration.wizard.confirmation')}</span><input className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
                        <button className="btn btn--primary" disabled={!gate.allowed || saving || Boolean(run.data.owner_approved_at)} onClick={() => runAction(() => api.approveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash, confirmation }), 'integration.wizard.ownerApproved')}>{tr('integration.wizard.ownerApprove')}</button>
                        {accountingGate.allowed && <button className="btn" disabled={saving || Boolean(run.data.accountant_approved_at)} onClick={() => runAction(() => api.accountantApproveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash }), 'integration.wizard.accountantApproved')}>{tr('integration.wizard.accountantApprove')}</button>}
                    </div>}
                    {actions}
                </section>}
            </main>

            <aside className="assistant-side" aria-label={tr('integration.assistant.summary')}>
                <div className="assistant-side-card assistant-now"><h2>{tr('integration.assistant.whatNow')}</h2><strong>{currentTask.label}</strong><p>{tr('integration.assistant.groupProgress', { done: completedGroups, total: groupOrder.length })}</p><p>{tr('integration.assistant.remainingDecisions', { count: remaining })}</p>{remaining > 0 && <p className="is-blocked">{tr('integration.assistant.blockingPrerequisite')}</p>}{!approvalAvailable && <button className="btn btn--primary" onClick={() => setAssistantStep(remaining ? 2 : 3)}>{tr(remaining ? 'integration.assistant.saveContinue' : 'integration.assistant.reviewSummary')}</button>}</div>
            </aside>
        </div>
    </div>;
}
