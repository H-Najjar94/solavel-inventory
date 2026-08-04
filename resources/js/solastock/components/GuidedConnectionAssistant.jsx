import React, { useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../services/api.js';

const unique = (values) => [...new Set(values || [])];

export default function GuidedConnectionAssistant({
    view, runUuid, run, gate, accountingGate, status, saving, tr, decisions,
    confirmedDecisions, pendingDecisions, rows, totals, accounting,
    assistantStep, setAssistantStep, allowedActions, canEdit, editableState,
    decide, undoDecision, bulkSelection, toggleBulk, bulk, exportComparison,
    start, runAction, cutoffAt, setCutoffAt, saveState, retrySave, reloadLatest,
    organizationName,
}) {
    const guided = view.guided_setup || {};
    const checks = guided.checks || {};
    const groups = guided.exception_groups || {};
    const byFingerprint = useMemo(() => new Map(rows.map((row) => [row.fingerprint, row])), [rows]);
    const [task, setTask] = useState(Math.min(6, Math.max(1, assistantStep || 1)));
    const [ownerCursor, setOwnerCursor] = useState(0);
    const [accountCursor, setAccountCursor] = useState(0);
    const [countCursor, setCountCursor] = useState(0);
    const [physicalValues, setPhysicalValues] = useState({});
    const [lastSaved, setLastSaved] = useState(null);
    const [bulkReviewOpen, setBulkReviewOpen] = useState(false);
    const headingRef = useRef(null);
    const resumedRunRef = useRef(null);

    const rowsFor = (...names) => unique(names.flatMap((name) => groups[name] || []))
        .map((fingerprint) => byFingerprint.get(fingerprint)).filter(Boolean);
    const ownerRows = rowsFor('items', 'units', 'warehouses', 'parties', 'currencies')
        .filter((row) => row.decision_class !== 'accountant_decision');
    const ownerPending = ownerRows.filter((row) => !confirmedDecisions.has(row.fingerprint));
    const accountingRows = rowsFor('accounting').filter((row) => row.decision_class === 'accountant_decision');
    const accountingPending = accountingRows.filter((row) => !confirmedDecisions.has(row.fingerprint));
    const physicalRows = rowsFor('inventory_quantities').filter((row) => {
        const action = confirmedDecisions.get(row.fingerprint)?.action;
        return !['classify_service_non_inventory', 'exclude_initial_connection'].includes(action);
    });
    const physicalPending = physicalRows.filter((row) => {
        const value = confirmedDecisions.get(row.fingerprint)?.safe_details?.physical_quantity;
        return value === undefined || value === null || value === '';
    });
    const cutoffRows = rowsFor('cutoff_documents');
    const exactRows = ownerRows.filter((row) => row.classification === 'exact_candidate_requires_owner_review'
        && !confirmedDecisions.has(row.fingerprint));
    const currentOwner = ownerPending[Math.min(ownerCursor, Math.max(0, ownerPending.length - 1))];
    const currentAccount = accountingPending[Math.min(accountCursor, Math.max(0, accountingPending.length - 1))];
    const currentCount = physicalPending[Math.min(countCursor, Math.max(0, physicalPending.length - 1))];
    const scenario = guided.customer_scenario || 'previously_separate';
    const scenarioText = tr(`integration.focus.scenario.${scenario}`);
    const totalSteps = 6;

    useEffect(() => {
        if (!runUuid || resumedRunRef.current === runUuid) return;
        resumedRunRef.current = runUuid;
        if (confirmedDecisions.size === 0) return;
        if (ownerPending.length > 0) setTask(2);
        else if (physicalPending.length > 0) setTask(3);
        else if (accountingPending.length > 0) setTask(4);
        else if (!view.cutoff_at) setTask(5);
        else setTask(6);
    }, [runUuid, confirmedDecisions.size]);

    useEffect(() => {
        setAssistantStep(task);
        requestAnimationFrame(() => headingRef.current?.focus());
    }, [task]);

    const go = (next) => setTask(Math.min(totalSteps, Math.max(1, next)));
    const formatNumber = (value, digits = 2) => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: digits });
    const baseCurrency = accounting.base_currency || guided.currency_summary?.base_currency || '';
    const formatMoney = (value) => `${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${baseCurrency === 'JOD' ? tr('integration.assistant.jod') : baseCurrency}`;
    const taskLabels = [
        tr('integration.focus.steps.prepare'), tr('integration.focus.steps.business'),
        tr('integration.focus.steps.count'), tr('integration.focus.steps.accountant'),
        tr('integration.focus.steps.startDate'), tr('integration.focus.steps.final'),
    ];

    const saveIndicator = <span className={`focus-save is-${saveState || 'idle'}`} role="status" aria-live="polite">
        {saving || saveState === 'saving' ? tr('integration.assistant.saving')
            : saveState === 'saved' ? tr('integration.focus.saved')
                : saveState === 'failed' ? tr('integration.assistant.saveFailed')
                    : saveState === 'conflict' ? tr('integration.assistant.saveConflict') : ''}
        {saveState === 'failed' && <button type="button" className="btn btn--link" onClick={retrySave}>{tr('integration.assistant.retrySave')}</button>}
        {saveState === 'conflict' && <><button type="button" className="btn btn--link" onClick={reloadLatest}>{tr('integration.assistant.reloadLatest')}</button><button type="button" className="btn btn--link" onClick={retrySave}>{tr('integration.assistant.retrySave')}</button></>}
    </span>;

    const choose = async (row, action, details = {}) => {
        const saved = await decide(row, action, details);
        if (!saved) return;
        const canonical = saved.canonical_decision
            || (saved.decisions || []).find((decision) => decision.candidate_fingerprint === row.fingerprint)
            || confirmedDecisions.get(row.fingerprint);
        setLastSaved({ row, decision: canonical });
        if (task === 2) setOwnerCursor(0);
        if (task === 3) setCountCursor(0);
        if (task === 4) setAccountCursor(0);
    };

    const undo = async () => {
        if (!lastSaved?.decision?.decision_uuid) return;
        if (await undoDecision(lastSaved.decision.decision_uuid)) {
            setLastSaved(null);
            if (task === 2) setOwnerCursor(0);
            if (task === 3) setCountCursor(0);
            if (task === 4) setAccountCursor(0);
        }
    };

    const choiceCopy = (row) => {
        if (row.classification === 'exact_candidate_requires_owner_review') return [
            ['approve_exact_binding', tr('integration.focus.sameItem'), tr('integration.focus.sameItemEffect')],
            ['reject_exact_binding', tr('integration.focus.differentItems'), tr('integration.focus.differentItemsEffect')],
            ['physical_count_required', tr('integration.focus.notSure'), tr('integration.focus.notSureEffect')],
        ];
        if (row.entity_type === 'item' && row.classification === 'missing_solastock_record') return [
            ['create_solastock_record', tr('integration.focus.createStockItem'), tr('integration.focus.createStockItemEffect')],
            ['classify_service_non_inventory', tr('integration.focus.keepService'), tr('integration.focus.keepServiceEffect')],
            ['exclude_initial_connection', tr('integration.focus.exclude'), tr('integration.focus.excludeEffect')],
            [null, tr('integration.focus.reviewLater'), tr('integration.focus.reviewLaterEffect')],
        ];
        return allowedActions(row).map((action) => [action, tr(`integration.wizard.action.${action}`), tr(`integration.assistant.effect.${action}`)]);
    };

    const recordCard = (row, role = 'owner') => {
        const exact = row.classification === 'exact_candidate_requires_owner_review';
        const currentNumber = role === 'accountant' ? accountingRows.indexOf(row) + 1 : ownerRows.indexOf(row) + 1;
        const total = role === 'accountant' ? accountingRows.length : ownerRows.length;
        return <div className="focus-question" key={row.fingerprint}>
            <div className="focus-question-count">{tr('integration.focus.recordPosition', { current: currentNumber, total })}</div>
            <h2 ref={headingRef} tabIndex="-1">{exact ? tr('integration.focus.sameQuestion') : row.entity_type === 'item' ? tr('integration.focus.itemQuestion') : tr(`integration.focus.question.${row.entity_type}`)}</h2>
            <p>{exact ? tr('integration.focus.sameExplanation') : tr('integration.focus.draftOnly')}</p>
            <div className="focus-record-pair">
                <div><span>SolaBooks</span><strong><bdi>{row.solabooks?.name || '—'}</bdi></strong><small><bdi>{row.solabooks?.sku || row.solabooks?.code || '—'}</bdi></small></div>
                {row.solastock && <div><span>SolaStock</span><strong><bdi>{row.solastock.name}</bdi></strong><small><bdi>{row.solastock.sku || row.solastock.code || '—'}</bdi></small></div>}
            </div>
            {exact && <p className="focus-system-note">✓ {tr('integration.assistant.identicalNameSku')}</p>}
            <div className="focus-choices" role="group" aria-label={tr('integration.focus.availableChoices')}>
                {choiceCopy(row).map(([action, label, effect]) => <button type="button" key={action || 'later'} className="focus-choice"
                    disabled={!runUuid || !editableState || !canEdit(row) || saving}
                    onClick={() => action ? choose(row, action) : setOwnerCursor((value) => value + 1)}>
                    <strong>{label}</strong><span>{effect}</span>
                </button>)}
            </div>
        </div>;
    };

    const footer = (primaryLabel, onPrimary, { disabled = false, hideBack = false } = {}) => <footer className="focus-footer">
        <div>{!hideBack && task > 1 && <button type="button" className="btn btn--link" onClick={() => go(task - 1)}>{tr('integration.focus.back')}</button>}{saveIndicator}</div>
        <div><button type="button" className="btn btn--link" onClick={() => window.location.assign('/inventory/dashboard')}>{tr('integration.focus.saveExit')}</button>
            <button type="button" className="btn btn--primary" disabled={disabled} onClick={onPrimary}>{primaryLabel}</button></div>
    </footer>;

    const technical = <details className="focus-details"><summary>{tr('integration.assistant.statusDetails')}</summary>
        <p>{tr('integration.focus.fullSafety')}</p><p><bdi>{view.run_uuid}</bdi></p>
        <button type="button" className="btn btn--link" onClick={exportComparison}>{tr('integration.wizard.export')}</button>
    </details>;

    const taskContent = () => {
        if (!runUuid) return <section className="focus-card focus-landing">
            <h1 ref={headingRef} tabIndex="-1">{tr('integration.focus.title')}</h1>
            <p className="focus-lead">{tr('integration.focus.subtitle')}</p>
            <p className="focus-scenario">{scenarioText}</p>
            <details><summary>{tr('integration.focus.whatHappens')}</summary><p>{tr('integration.focus.whatHappensText')}</p></details>
            {footer(tr('integration.focus.continueSetup'), start, { hideBack: true, disabled: !gate.allowed || saving })}
        </section>;

        if (task === 1) return <section className="focus-card focus-prepared">
            <h1 ref={headingRef} tabIndex="-1">{tr('integration.focus.preparedTitle')}</h1>
            <p>{tr('integration.focus.preparedIntro')}</p>
            <ul className="focus-check-list">
                <li>✓ {tr('integration.focus.organizationReady')}</li>
                <li>✓ {tr('integration.focus.baseCurrency', { currency: guided.currency_summary?.base_currency || '—' })}</li>
                <li>✓ {tr('integration.focus.taxesReady')}</li>
                <li>✓ {tr('integration.focus.accountsReady')}</li>
            </ul>
            <div className="focus-attention"><strong>{tr('integration.focus.needsReview', { count: 2 })}</strong><p>{tr('integration.focus.needsReviewText')}</p></div>
            <details><summary>{tr('integration.focus.showDetails')}</summary><p>{tr('integration.focus.automaticDetails', { accounts: checks.accounts_resolved || 0, taxes: checks.taxes_resolved || 0 })}</p></details>
            {footer(tr('integration.focus.startReview'), () => go(2), { hideBack: true })}
        </section>;

        if (task === 2) return <section className="focus-card">
            {currentOwner ? recordCard(currentOwner) : <div className="focus-complete"><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.businessComplete')}</h2><p>{tr('integration.focus.businessCompleteText')}</p></div>}
            {exactRows.length > 1 && <button type="button" className="btn btn--link focus-bulk-link" onClick={() => { exactRows.forEach((row) => !bulkSelection.includes(row.fingerprint) && toggleBulk(row.fingerprint)); setBulkReviewOpen(true); }}>{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</button>}
            {lastSaved && <div className="focus-undo" role="status">{tr('integration.focus.saved')} <button type="button" className="btn btn--link" onClick={undo}>{tr('integration.focus.undo')}</button></div>}
            {footer(tr('integration.focus.continue'), () => go(3), { disabled: ownerPending.length > 0 })}
        </section>;

        if (task === 3) return <section className="focus-card">
            {currentCount ? <div className="focus-question">
                <div className="focus-question-count">{tr('integration.focus.recordPosition', { current: physicalRows.indexOf(currentCount) + 1, total: physicalRows.length })}</div>
                <h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.physicalTitle')}</h2>
                <p>{tr('integration.focus.physicalExplanation')}</p>
                <strong className="focus-item-name"><bdi>{currentCount.solabooks?.name || currentCount.solastock?.name}</bdi></strong>
                <div className="focus-quantity-compare"><div><span>SolaBooks</span><bdi>{formatNumber(currentCount.solabooks?.quantity, 4)}</bdi></div><div><span>SolaStock</span><bdi>{formatNumber(currentCount.solastock?.quantity, 4)}</bdi></div></div>
                <label className="field"><span className="field-label">{tr('integration.focus.actualQuantity')}</span><input className="input" inputMode="decimal" value={physicalValues[currentCount.fingerprint] || ''} onChange={(event) => setPhysicalValues((values) => ({ ...values, [currentCount.fingerprint]: event.target.value }))} /></label>
                <p className="focus-draft-note">{tr('integration.focus.physicalDraftNote')}</p>
            </div> : <div className="focus-complete"><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.countComplete')}</h2><p>{tr('integration.focus.countCompleteText')}</p></div>}
            {footer(currentCount ? tr('integration.focus.saveNext') : tr('integration.focus.continue'), currentCount
                ? () => choose(currentCount, confirmedDecisions.get(currentCount.fingerprint)?.action || 'physical_count_required', { physical_quantity: physicalValues[currentCount.fingerprint], physical_count_reference: 'wizard_draft' })
                : () => go(4), { disabled: Boolean(currentCount && !/^\d+(\.\d+)?$/.test(physicalValues[currentCount.fingerprint] || '')) })}
        </section>;

        if (task === 4) return <section className="focus-card">
            {!accountingGate.allowed ? <div className="focus-accountant-card"><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.accountantRequired')}</h2><p>{tr('integration.focus.accountantRequiredText', { count: accountingPending.length })}</p><a className="btn" href="/settings/users">{tr('integration.focus.assignAccountant')}</a></div>
                : currentAccount ? recordCard(currentAccount, 'accountant')
                    : <div className="focus-complete"><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.accountingComplete')}</h2><p>{tr('integration.focus.accountingCompleteText')}</p></div>}
            {footer(tr('integration.focus.continue'), () => go(5), { disabled: accountingGate.allowed && accountingPending.length > 0 })}
        </section>;

        if (task === 5) return <section className="focus-card">
            <h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.startDateTitle')}</h2>
            <p>{tr('integration.focus.startDateExplanation')}</p>
            <div className="focus-attention"><strong>{tr('integration.focus.openDocuments', { count: cutoffRows.length })}</strong><p>{tr('integration.focus.openDocumentsText')}</p></div>
            <label className="field"><span className="field-label">{tr('integration.focus.startDateLabel')}</span><input className="input" type="datetime-local" value={cutoffAt} onChange={(event) => setCutoffAt(event.target.value)} /></label>
            {run.data?.state !== 'cutoff_review' && <p className="focus-draft-note">{tr('integration.focus.startDatePrerequisites')}</p>}
            {footer(run.data?.state === 'cutoff_review' ? tr('integration.focus.saveStartDate') : tr('integration.focus.continue'), async () => {
                if (run.data?.state === 'decisions_complete') return runAction(() => api.requestIntegrationWizardSnapshot(runUuid, { expected_lock_version: run.data.lock_version }), 'integration.wizard.snapshotRequested');
                if (run.data?.state === 'snapshot_required') return runAction(() => api.freezeIntegrationWizardSnapshot(runUuid, { expected_lock_version: run.data.lock_version }), 'integration.wizard.snapshotFrozen');
                if (run.data?.state === 'cutoff_review' && cutoffAt) return runAction(() => api.reviewIntegrationWizardCutoff(runUuid, { cutoff_at: cutoffAt, physical_counts: [], unexplained_variance: '0.00', expected_lock_version: run.data.lock_version }), 'integration.wizard.cutoffReviewed');
                return go(6);
            }, { disabled: run.data?.state === 'cutoff_review' && !cutoffAt })}
        </section>;

        const blockers = [
            physicalPending.length && tr('integration.focus.blocker.count'),
            accountingPending.length && tr('integration.focus.blocker.accounting', { count: accountingPending.length }),
            cutoffRows.length && !view.cutoff_at && tr('integration.focus.blocker.documents'),
            Number(totals.total_valuation_difference || 0) !== 0 && tr('integration.focus.blocker.value'),
            (!view.owner_approved_at || !view.accountant_approved_at) && tr('integration.focus.blocker.approvals'),
        ].filter(Boolean);
        const ready = blockers.length === 0 && Number(totals.total_quantity_difference || 0) === 0;
        return <section className="focus-card focus-result">
            <div className={`focus-outcome ${ready ? 'is-ready' : 'is-warning'}`}><h2 ref={headingRef} tabIndex="-1">{tr(ready ? 'integration.focus.ready' : 'integration.focus.notReady')}</h2><p>{tr(ready ? 'integration.focus.readyText' : 'integration.focus.notReadyText')}</p></div>
            {!ready && <ol className="focus-blockers">{blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ol>}
            <div className="focus-result-summary"><div><span>{tr('integration.focus.quantityDifference')}</span><strong><bdi>{formatNumber(totals.total_quantity_difference, 4)}</bdi></strong></div><div><span>{tr('integration.focus.valueDifference')}</span><strong><bdi>{formatMoney(totals.total_valuation_difference)}</bdi></strong></div><div><span>{tr('integration.focus.startDateLabel')}</span><strong><bdi>{view.cutoff_at || '—'}</bdi></strong></div></div>
            {footer(tr('integration.focus.backToTasks'), () => go(blockers[0] === tr('integration.focus.blocker.count') ? 3 : 2), { disabled: false })}
        </section>;
    };

    return <div className="wizard focus-wizard" aria-live="polite">
        <header className="focus-header">
            <div><span>{tr('integration.focus.stepOf', { current: task, total: totalSteps })}</span><strong>{taskLabels[task - 1]}</strong></div>
            <span>{tr('integration.focus.stepsRemaining', { count: totalSteps - task })}</span>
            <details className="focus-all-steps"><summary>{tr('integration.focus.allSteps')}</summary><ol>{taskLabels.map((label, index) => <li key={label}><button type="button" onClick={() => go(index + 1)} disabled={index + 1 > task}>{label}</button></li>)}</ol></details>
        </header>
        <div className="focus-safety-bar">{tr('integration.focus.safety')} {technical}</div>
        <main className="focus-stage">{taskContent()}</main>
        {bulkReviewOpen && <div className="assistant-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-title"><div className="assistant-bulk-dialog__content"><h2 id="bulk-title">{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</h2><p>{tr('integration.assistant.bulkDraftOnly')}</p>{exactRows.map((row) => <label key={row.fingerprint}><input type="checkbox" checked={bulkSelection.includes(row.fingerprint)} onChange={() => toggleBulk(row.fingerprint)} /><span><bdi>{row.solabooks?.name}</bdi> → <bdi>{row.solastock?.name}</bdi></span></label>)}<div className="doc-actions"><button className="btn" onClick={() => setBulkReviewOpen(false)}>{tr('settings.common.cancel')}</button><button className="btn btn--primary" onClick={async () => { if (await bulk('approve_exact_sku_candidates')) setBulkReviewOpen(false); }}>{tr('integration.assistant.confirmSelectedMatches', { count: bulkSelection.length })}</button></div></div></div>}
    </div>;
}
