import React, { useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../services/api.js';

const unique = (values) => [...new Set(values || [])];

function SectionIcon({ section }) {
    const paths = {
        units: <><path d="M4 8h14"/><path d="m15 5 3 3-3 3"/><path d="M20 16H6"/><path d="m9 13-3 3 3 3"/></>,
        categories: <><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></>,
        customers: <><circle cx="12" cy="8" r="3"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/></>,
        suppliers: <><path d="M4 9h16v11H4z"/><path d="M7 9V5h10v4"/><path d="M4 13h16"/><path d="M9 13v2h6v-2"/></>,
        warehouses: <><path d="m3 10 9-6 9 6v10H3z"/><path d="M8 20v-6h8v6"/></>,
        currencies: <><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.7-1-3-1-1.7 0-3 .9-3 2.2 0 3.5 6 1.4 6 4.8 0 1.3-1.3 2.2-3 2.2-1.3 0-2.5-.4-3.3-1.2"/><path d="M12 5.5v13"/></>,
        items: <><path d="m4 7 8-4 8 4-8 4z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/></>,
    };

    return <svg className="focus-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {paths[section] || paths.items}
    </svg>;
}

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
    const [savedFeedbackVisible, setSavedFeedbackVisible] = useState(false);
    const [sectionSaveNotice, setSectionSaveNotice] = useState(null);
    const [bulkReviewOpen, setBulkReviewOpen] = useState(false);
    const [openOwnerSection, setOpenOwnerSection] = useState(undefined);
    const [itemFilter, setItemFilter] = useState('all');
    const [expandedAffectedRows, setExpandedAffectedRows] = useState(() => new Set());
    const [pendingActions, setPendingActions] = useState({});
    const [conversionDrafts, setConversionDrafts] = useState({});
    const [partyDrafts, setPartyDrafts] = useState({});
    const [manualChoiceRows, setManualChoiceRows] = useState(() => new Set());
    const [excludedRecommendations, setExcludedRecommendations] = useState(() => new Set());
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
    const phase = task === 1 ? 1 : task === 6 ? 3 : 2;
    const resolvedOwner = ownerRows.length - ownerPending.length;
    const resolvedCounts = physicalRows.length - physicalPending.length;
    const resolvedAccounting = accountingRows.length - accountingPending.length;
    const itemRows = ownerRows.filter((row) => row.entity_type === 'item');
    const itemCounts = {
        solabooks: itemRows.filter((row) => row.solabooks).length,
        solastock: itemRows.filter((row) => row.solastock).length,
        both: itemRows.filter((row) => row.solabooks && row.solastock).length,
        solabooksOnly: itemRows.filter((row) => row.solabooks && !row.solastock).length,
        solastockOnly: itemRows.filter((row) => !row.solabooks && row.solastock).length,
    };
    const ownerSections = [
        ['units', ownerRows.filter((row) => row.entity_type === 'unit')],
        ['categories', ownerRows.filter((row) => row.entity_type === 'category')],
        ['customers', ownerRows.filter((row) => row.entity_type === 'customer')],
        ['suppliers', ownerRows.filter((row) => row.entity_type === 'supplier')],
        ['warehouses', ownerRows.filter((row) => row.entity_type === 'warehouse')],
        ['currencies', ownerRows.filter((row) => row.entity_type === 'currency')],
        ['items', itemRows],
    ].filter(([, sectionRows]) => sectionRows.length > 0);
    const firstIncompleteSection = ownerSections.find(([, sectionRows]) =>
        sectionRows.some((row) => !confirmedDecisions.has(row.fingerprint)))?.[0];
    const activeOwnerSection = ownerSections.find(([section]) => section === openOwnerSection) || ownerSections[0];

    useEffect(() => {
        if (task === 2 && firstIncompleteSection && openOwnerSection === undefined) {
            setOpenOwnerSection(firstIncompleteSection);
        }
    }, [task, firstIncompleteSection, openOwnerSection]);

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

    useEffect(() => {
        if (saveState === 'idle') {
            setSavedFeedbackVisible(false);
            return undefined;
        }
        setSavedFeedbackVisible(true);
        if (saveState !== 'saved') return undefined;
        const timer = window.setTimeout(() => setSavedFeedbackVisible(false), 3000);
        return () => window.clearTimeout(timer);
    }, [saveState]);

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
            : saveState === 'saved' && savedFeedbackVisible ? tr('integration.focus.saved')
                : saveState === 'failed' ? tr('integration.assistant.saveFailed')
                    : saveState === 'conflict' ? tr('integration.assistant.saveConflict') : ''}
        {saveState === 'failed' && <button type="button" className="btn btn--link" onClick={retrySave}>{tr('integration.assistant.retrySave')}</button>}
        {saveState === 'conflict' && <><button type="button" className="btn btn--link" onClick={reloadLatest}>{tr('integration.assistant.reloadLatest')}</button><button type="button" className="btn btn--link" onClick={retrySave}>{tr('integration.assistant.retrySave')}</button></>}
    </span>;

    const choose = async (row, action, details = {}) => {
        const saved = await decide(row, action, details);
        if (!saved) return null;
        const canonical = saved.canonical_decision
            || (saved.decisions || []).find((decision) => decision.candidate_fingerprint === row.fingerprint)
            || confirmedDecisions.get(row.fingerprint);
        setLastSaved({ row, decision: canonical });
        if (task === 2) setOwnerCursor(0);
        if (task === 3) setCountCursor(0);
        if (task === 4) setAccountCursor(0);
        setManualChoiceRows((currentValues) => {
            if (!currentValues.has(row.fingerprint)) return currentValues;
            const next = new Set(currentValues); next.delete(row.fingerprint); return next;
        });
        return saved;
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
        if (row.entity_type === 'unit') {
            if (row.solastock) return [
                ['select_unit', tr('integration.focus.useStockUnit'), tr('integration.focus.useStockUnitEffect')],
                ['retain_blocked', tr('integration.focus.keepUnitBlocked'), tr('integration.focus.keepUnitBlockedEffect')],
            ];
            if (row.solabooks?.name) return [
                ['propose_unit_creation', tr('integration.focus.proposeUnitCreation'), tr('integration.focus.proposeUnitCreationEffect')],
                ...(row.safe_details?.available_stock_units?.length ? [['define_unit_conversion', tr('integration.focus.defineConversion'), tr('integration.focus.defineConversionEffect')]] : []),
                ['retain_blocked', tr('integration.focus.keepUnitBlocked'), tr('integration.focus.keepUnitBlockedEffect')],
            ];
            return [['retain_blocked', tr('integration.focus.keepBrokenUnitBlocked'), tr('integration.focus.keepBrokenUnitBlockedEffect')]];
        }
        if (row.entity_type === 'category') return row.solastock ? [
            ['select_category', tr('integration.focus.useStockCategory'), tr('integration.focus.useStockCategoryEffect')],
            ['retain_blocked', tr('integration.focus.keepCategoryBlocked'), tr('integration.focus.keepCategoryBlockedEffect')],
        ] : [
            ['propose_category_creation', tr('integration.focus.proposeCategoryCreation'), tr('integration.focus.proposeCategoryCreationEffect')],
            ['retain_blocked', tr('integration.focus.keepCategoryBlocked'), tr('integration.focus.keepCategoryBlockedEffect')],
        ];
        if (['customer', 'supplier'].includes(row.entity_type)) return [
            ...(!row.solabooks ? [[
                'propose_finance_party_creation',
                tr(row.entity_type === 'customer' ? 'integration.focus.addCustomerToFinancePlan' : 'integration.focus.addSupplierToFinancePlan'),
                tr('integration.focus.addPartyToFinancePlanEffect'),
            ]] : []),
            ['select_party', tr(row.solabooks
                ? (row.entity_type === 'customer' ? 'integration.focus.useFinanceCustomer' : 'integration.focus.useFinanceSupplier')
                : (row.entity_type === 'customer' ? 'integration.focus.matchFinanceCustomer' : 'integration.focus.matchFinanceSupplier')),
            tr(row.solabooks ? 'integration.focus.useFinancePartyEffect' : 'integration.focus.matchFinancePartyEffect')],
            ['retain_blocked', tr('integration.focus.reviewPartyLater'), tr('integration.focus.reviewPartyLaterEffect')],
        ];
        return allowedActions(row).map((action) => [action, tr(`integration.wizard.action.${action}`), tr(`integration.assistant.effect.${action}`)]);
    };

    const recommendedChoice = (row) => {
        const choices = choiceCopy(row);
        const recommendedAction = row.classification === 'exact_candidate_requires_owner_review'
            ? 'approve_exact_binding'
            : row.entity_type === 'item' && row.classification === 'missing_solastock_record'
                && row.solabooks?.item_type === 'inventory' ? 'create_solastock_record'
                : row.entity_type === 'item' && row.classification === 'missing_solastock_record'
                    && ['service', 'non_inventory'].includes(row.solabooks?.item_type) ? 'classify_service_non_inventory'
                    : row.entity_type === 'item' && row.classification === 'missing_solabooks_record'
                        ? 'keep_solastock_authority'
            : row.entity_type === 'unit' && row.solastock ? 'select_unit'
                : row.entity_type === 'unit' && row.solabooks?.name ? 'propose_unit_creation'
                : row.entity_type === 'category' && row.solastock ? 'select_category'
                    : row.entity_type === 'category' && row.solabooks?.name ? 'propose_category_creation'
                        : ['customer', 'supplier'].includes(row.entity_type) && row.solabooks ? 'select_party'
                            : ['customer', 'supplier'].includes(row.entity_type) && row.solastock ? 'propose_finance_party_creation'
                            : row.entity_type === 'account_role' && row.classification === 'candidate_requires_accountant_approval' ? 'select_account_role'
                                : null;
        const choice = recommendedAction ? choices.find(([action]) => action === recommendedAction) : null;
        return choice ? [...choice, ['retain_blocked', 'retain_account_role_unresolved'].includes(recommendedAction) ? 'hold' : 'recommended'] : null;
    };

    const activeSectionRows = activeOwnerSection?.[1] || [];
    const activeSectionPending = activeSectionRows.filter((row) => !confirmedDecisions.has(row.fingerprint));
    const selectedOwnerRecommendations = activeSectionPending.filter((row) => recommendedChoice(row)
        && !manualChoiceRows.has(row.fingerprint)
        && !excludedRecommendations.has(row.fingerprint));

    const recommendationDetails = (row, action) => (
        ['select_unit', 'select_category', 'select_party', 'select_account_role'].includes(action)
            && (row.solastock?.id || row.solabooks?.id)
            ? { selected_record_id: row.solastock?.id || row.solabooks?.id } : {}
    );

    const saveRecommendationsAndContinue = async () => {
        const selected = selectedOwnerRecommendations;
        setSectionSaveNotice(null);
        for (const row of selected) {
            const action = recommendedChoice(row)?.[0];
            if (!action || !await choose(row, action, recommendationDetails(row, action))) return;
        }
        const unresolved = activeSectionPending.filter((row) => !selected.some((saved) => saved.fingerprint === row.fingerprint));
        if (unresolved.length > 0) return;
        if (selected.length > 0) {
            setSectionSaveNotice({ count: selected.length, section: activeOwnerSection?.[0] });
        }
        const currentIndex = ownerSections.findIndex(([section]) => section === activeOwnerSection?.[0]);
        const nextSection = ownerSections[currentIndex + 1]?.[0];
        if (nextSection) return setOpenOwnerSection(nextSection);
        go(3);
    };

    const saveAccountingRecommendationsAndContinue = async () => {
        const selected = accountingPending.filter((row) => recommendedChoice(row)
            && !manualChoiceRows.has(row.fingerprint)
            && !excludedRecommendations.has(row.fingerprint));
        for (const row of selected) {
            const action = recommendedChoice(row)?.[0];
            if (!action || !await choose(row, action, recommendationDetails(row, action))) return;
        }
        if (accountingPending.length === selected.length) go(5);
    };

    const physicalValueFor = (row) => {
        const saved = confirmedDecisions.get(row.fingerprint)?.safe_details?.physical_quantity;
        if (physicalValues[row.fingerprint] !== undefined) return physicalValues[row.fingerprint];
        if (saved !== undefined && saved !== null && saved !== '') return String(saved);
        const stockQuantity = row.solastock?.quantity;
        return stockQuantity !== undefined && stockQuantity !== null ? String(stockQuantity) : '';
    };

    const savePhysicalCountsAndContinue = async () => {
        for (const row of physicalPending) {
            const value = physicalValueFor(row);
            if (!/^\d+(\.\d+)?$/.test(value)) return;
            const saved = await choose(row, confirmedDecisions.get(row.fingerprint)?.action || 'physical_count_required', {
                physical_quantity: value,
                physical_count_reference: 'wizard_draft_owner_confirmed',
            });
            if (!saved) return;
        }
        go(4);
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

    const compactDecisionRow = (row) => {
        const exact = row.classification === 'exact_candidate_requires_owner_review';
        const current = decisions.get(row.fingerprint);
        const choices = choiceCopy(row).filter(([action]) => action);
        const recommendation = !current?.action ? recommendedChoice(row) : null;
        const recommendationKind = recommendation?.[3] || 'recommended';
        const recommendationSelected = recommendation && !excludedRecommendations.has(row.fingerprint);
        const showManualChoice = manualChoiceRows.has(row.fingerprint) || (!recommendation && !current?.action);
        const selectedAction = pendingActions[row.fingerprint] ?? current?.action ?? '';
        const selectedChoice = choices.find(([action]) => action === selectedAction);
        const conversionOpen = row.entity_type === 'unit' && pendingActions[row.fingerprint] === 'define_unit_conversion';
        const partyNeedsManualMatch = ['customer', 'supplier'].includes(row.entity_type) && !row.solabooks;
        const partyMatchOpen = partyNeedsManualMatch && showManualChoice;
        const availableFinanceParties = row.safe_details?.available_finance_parties || [];
        const partyTargetId = partyDrafts[row.fingerprint]?.targetId ?? '';
        const availableStockUnits = row.safe_details?.available_stock_units || [];
        const defaultTargetId = current?.safe_details?.selected_record_id || row.solastock?.id || '';
        const conversionTargetId = conversionDrafts[row.fingerprint]?.targetId ?? defaultTargetId;
        const conversionFactor = conversionDrafts[row.fingerprint]
            ?.factor ?? current?.safe_details?.conversion_factor ?? '';
        const conversionTarget = availableStockUnits.find((unit) => String(unit.id) === String(conversionTargetId));
        const conversionValid = /^(?:0*[1-9]\d{0,11})(?:\.\d{1,6})?$|^0\.\d{0,5}[1-9]$/.test(String(conversionFactor).trim());
        const decisionId = `wizard-decision-${row.fingerprint.slice(0, 12)}`;
        const factorId = `wizard-conversion-${row.fingerprint.slice(0, 12)}`;
        const comparedMaster = ['item', 'unit', 'category', 'customer', 'supplier'].includes(row.entity_type);
        const sourceLabel = row.entity_type === 'item' ? tr('integration.assistant.originalFinanceItem')
            : row.entity_type === 'unit' ? tr('integration.focus.originalFinanceUnit')
                : row.entity_type === 'category' ? tr('integration.focus.originalFinanceCategory')
                    : tr(row.entity_type === 'customer' ? 'integration.focus.originalFinanceCustomer' : 'integration.focus.originalFinanceSupplier');
        const proposedLabel = row.entity_type === 'item' ? tr('integration.assistant.proposedStockItem')
            : row.entity_type === 'unit' ? tr('integration.focus.proposedStockUnit')
                : row.entity_type === 'category' ? tr('integration.focus.proposedStockCategory')
                    : tr(row.entity_type === 'customer' ? 'integration.focus.proposedStockCustomer' : 'integration.focus.proposedStockSupplier');
        const missingSource = row.entity_type === 'unit' && !row.solabooks?.name
            ? tr('integration.focus.missingFinanceUnit', { id: row.safe_details?.finance_reference_id || row.solabooks?.id || '—' })
            : ['customer', 'supplier'].includes(row.entity_type) && !row.solabooks
                ? tr(row.entity_type === 'customer' ? 'integration.focus.noFinanceCustomer' : 'integration.focus.noFinanceSupplier') : '—';
        const missingStock = row.entity_type === 'unit' ? tr('integration.focus.noStockUnit')
            : row.entity_type === 'category' ? tr('integration.focus.noStockCategory')
                : ['customer', 'supplier'].includes(row.entity_type)
                    ? tr(row.entity_type === 'customer' ? 'integration.focus.noStockCustomer' : 'integration.focus.noStockSupplier')
                    : tr('integration.assistant.noProposedStockItem');
        const identityLabel = row.entity_type === 'item' ? tr('integration.assistant.sku') : tr('integration.focus.codeOrReference');
        const affectedItems = row.safe_details?.affected_items || [];
        const affectedExpanded = expandedAffectedRows.has(row.fingerprint);
        const visibleAffectedItems = affectedExpanded ? affectedItems : affectedItems.slice(0, 3);
        const hiddenAffectedCount = Math.max(0, affectedItems.length - visibleAffectedItems.length);
        const toggleAffectedItems = () => setExpandedAffectedRows((current) => {
            const next = new Set(current);
            if (next.has(row.fingerprint)) next.delete(row.fingerprint);
            else next.add(row.fingerprint);
            return next;
        });
        const decisionPrompt = row.entity_type === 'item'
            ? tr(exact ? 'integration.focus.decisionPrompt.exactItem' : 'integration.focus.decisionPrompt.item')
            : tr(`integration.focus.decisionPrompt.${row.entity_type}`, {}, tr('integration.focus.decisionPrompt.record'));
        const decisionEffect = selectedChoice?.[2] || tr('integration.focus.decisionNotApplied');
        const brokenFinanceUnit = row.entity_type === 'unit'
            && row.safe_details?.source === 'dangling_finance_item_unit_reference';
        return <div className="focus-list-row" key={row.fingerprint}>
            <div className={`focus-list-record ${comparedMaster ? 'focus-item-comparison' : ''}`}>
                {comparedMaster ? <>
                    <div className="focus-app-record is-original">
                        <span>{sourceLabel}</span>
                        <strong><bdi>{row.solabooks?.name || missingSource}</bdi></strong>
                        <small>{identityLabel}: <bdi>{row.solabooks?.sku || row.solabooks?.code || row.solabooks?.id || '—'}</bdi></small>
                    </div>
                    <span className="focus-compare-mark" aria-hidden="true">⇄</span>
                    <div className="focus-app-record is-proposed">
                        <span>{proposedLabel}</span>
                        <strong><bdi>{row.solastock?.name || missingStock}</bdi></strong>
                        <small>{identityLabel}: <bdi>{row.solastock?.sku || row.solastock?.code || row.solastock?.id || '—'}</bdi></small>
                    </div>
                    {row.entity_type !== 'item' && affectedItems.length > 0 ? <div className="focus-affected-items">
                        <div className="focus-affected-heading"><strong>{tr('integration.focus.affectedItems', { count: affectedItems.length })}</strong>
                            <span><button type="button" className="btn btn--link" onClick={() => setOpenOwnerSection('items')}>{tr('integration.focus.reviewAffectedItems')}</button>
                            {affectedItems.length > 3 && <button type="button" className="btn btn--link" aria-expanded={affectedExpanded} onClick={toggleAffectedItems}>
                                {tr(affectedExpanded ? 'integration.focus.showFewerAffected' : 'integration.focus.showMoreAffected', { count: hiddenAffectedCount || affectedItems.length - 3 })}
                            </button>}</span></div>
                        <ul className={affectedExpanded ? 'is-expanded' : ''}>{visibleAffectedItems.map((item) => <li key={item.id || item.sku || item.name}>
                            <strong><bdi>{item.name || '—'}</bdi></strong>{item.sku && <small><bdi>{item.sku}</bdi></small>}
                            {item.item_type === 'inventory' && <small>{tr('integration.focus.inventoryQuantity', { quantity: formatNumber(item.quantity, 4) })}</small>}
                        </li>)}</ul>
                        {!affectedExpanded && hiddenAffectedCount > 0 && <small className="focus-affected-overflow">{tr('integration.focus.moreAffectedSummary', { count: hiddenAffectedCount })}</small>}
                        {row.entity_type === 'unit' && <small className={brokenFinanceUnit ? 'is-error' : ''}>
                            {tr(brokenFinanceUnit ? 'integration.focus.missingUnitInventoryWarning' : 'integration.focus.unitDependsOnItemType')}
                        </small>}
                    </div> : <small className="focus-match-reason">{exact ? tr('integration.assistant.identicalNameSku') : tr(`integration.focus.question.${row.entity_type}`)}</small>}
                </> : <>
                    <strong><bdi>{row.solabooks?.name || row.solastock?.name || '—'}</bdi></strong>
                    <span><bdi>{row.solabooks?.sku || row.solabooks?.code || '—'}</bdi>{row.solastock?.name && <> · {tr('integration.focus.proposedMatch')}: <bdi>{row.solastock.name}</bdi></>}</span>
                    <small>{tr(`integration.focus.question.${row.entity_type}`)}</small>
                </>}
            </div>
            <div className={`focus-list-decision ${current?.action ? 'is-decided' : ''} ${recommendation && !showManualChoice ? 'has-default' : ''}`}>
                {showManualChoice && <span className="focus-action-title">{tr('integration.focus.yourDecision')}</span>}
                {showManualChoice ? <label className="focus-action-prompt" htmlFor={decisionId}>{decisionPrompt}</label>
                    : <span className="focus-action-prompt">{decisionPrompt}</span>}
                {recommendation && !showManualChoice && <div className={`focus-recommendation is-${recommendationKind} ${recommendationSelected ? 'is-selected' : ''}`}>
                    <div className="focus-recommendation-top">
                        <label><input type="checkbox" checked={recommendationSelected} onChange={() => setExcludedRecommendations((currentValues) => {
                            const next = new Set(currentValues);
                            if (next.has(row.fingerprint)) next.delete(row.fingerprint); else next.add(row.fingerprint);
                            return next;
                        })} /><small>{tr(recommendationKind === 'hold' ? 'integration.focus.safeDefault' : 'integration.focus.recommended')}</small></label>
                        <button type="button" className="focus-change-action" onClick={() => setManualChoiceRows((currentValues) => new Set(currentValues).add(row.fingerprint))}>{tr('integration.focus.changeDecision')}</button>
                    </div>
                    <strong>{recommendation[1]}</strong>
                    <em>{recommendation[2]}</em>
                </div>}
                {current?.action && !showManualChoice && <div className="focus-current-choice">
                    <span><small>{tr('integration.focus.savedDecision')}</small><strong>{choices.find(([action]) => action === current.action)?.[1] || current.action}</strong></span>
                    <button type="button" className="btn btn--link" onClick={() => setManualChoiceRows((currentValues) => new Set(currentValues).add(row.fingerprint))}>{tr('integration.focus.changeDecision')}</button>
                </div>}
                {showManualChoice && !partyMatchOpen && <select id={decisionId} aria-label={tr('integration.focus.decisionFor', { name: row.solabooks?.name || row.solastock?.name || '' })}
                    className="input" value={selectedAction} disabled={!runUuid || !editableState || !canEdit(row) || saving}
                    onChange={(event) => {
                        const action = event.target.value;
                        if (!action) return;
                        if (action === 'define_unit_conversion') {
                            setPendingActions((values) => ({ ...values, [row.fingerprint]: action }));
                            setConversionDrafts((values) => ({ ...values, [row.fingerprint]: {
                                targetId: values[row.fingerprint]?.targetId ?? defaultTargetId,
                                factor: values[row.fingerprint]?.factor ?? current?.safe_details?.conversion_factor ?? '',
                            } }));
                            return;
                        }
                        if (action === 'select_party' && !row.solabooks) {
                            setPendingActions((values) => ({ ...values, [row.fingerprint]: action }));
                            setPartyDrafts((values) => ({ ...values, [row.fingerprint]: {
                                targetId: values[row.fingerprint]?.targetId ?? '',
                            } }));
                            return;
                        }
                        setPendingActions((values) => { const next = { ...values }; delete next[row.fingerprint]; return next; });
                        choose(row, action, ['select_unit', 'select_category'].includes(action) && row.solastock?.id
                            ? { selected_record_id: row.solastock.id }
                            : action === 'select_party' && row.solabooks?.id ? { selected_record_id: row.solabooks.id } : {});
                    }}>
                    <option value="">{tr('integration.focus.chooseDecision')}</option>
                    {choices.map(([action, label]) => <option value={action} key={action}>{label}</option>)}
                </select>}
                {partyMatchOpen && <div className="focus-party-match" role="group">
                    <strong>{tr('integration.focus.manualPartyMatchRequired')}</strong>
                    <small>{tr('integration.focus.noSafePartyDefault')}</small>
                    <label htmlFor={`${decisionId}-party`}>{tr(row.entity_type === 'customer'
                        ? 'integration.focus.chooseFinanceCustomer' : 'integration.focus.chooseFinanceSupplier')}</label>
                    <select id={`${decisionId}-party`} className="input" value={partyTargetId}
                        onChange={(event) => setPartyDrafts((values) => ({ ...values, [row.fingerprint]: { targetId: event.target.value } }))}>
                        <option value="">{tr(row.entity_type === 'customer'
                            ? 'integration.focus.chooseFinanceCustomer' : 'integration.focus.chooseFinanceSupplier')}</option>
                        {availableFinanceParties.map((party) => <option value={party.id} key={party.id}>
                            {party.name}{party.code ? ` (${party.code})` : ''}
                        </option>)}
                    </select>
                    <small>{tr('integration.focus.partyMatchDraftOnly')}</small>
                    <div className="focus-party-match-actions">
                        <button type="button" className="btn" disabled={!partyTargetId || saving} onClick={async () => {
                            const saved = await choose(row, 'select_party', { selected_record_id: partyTargetId });
                            if (saved) setPendingActions((values) => { const next = { ...values }; delete next[row.fingerprint]; return next; });
                        }}>{tr('integration.focus.savePartyMatch')}</button>
                        <button type="button" className="btn btn--link" disabled={saving} onClick={() => {
                            choose(row, 'retain_blocked');
                        }}>{tr('integration.focus.reviewPartyLater')}</button>
                    </div>
                </div>}
                {conversionOpen && <div className="focus-conversion" role="group" aria-labelledby={`${factorId}-title`}>
                    <strong id={`${factorId}-title`}>{tr('integration.focus.conversionTitle')}</strong>
                    <label htmlFor={`${factorId}-target`}>{tr('integration.focus.conversionTarget')}</label>
                    <select id={`${factorId}-target`} className="input" value={conversionTargetId}
                        onChange={(event) => setConversionDrafts((values) => ({ ...values, [row.fingerprint]: {
                            targetId: event.target.value, factor: values[row.fingerprint]?.factor ?? '',
                        } }))}>
                        <option value="">{tr('integration.focus.chooseTargetUnit')}</option>
                        {availableStockUnits.map((unit) => <option value={unit.id} key={unit.id}>{unit.name}{unit.code ? ` (${unit.code})` : ''}</option>)}
                    </select>
                    <div className="focus-conversion-equation">
                        <span><bdi>1 {row.solabooks?.name || '—'}</bdi></span>
                        <span aria-hidden="true">=</span>
                        <label htmlFor={factorId} className="sr-only">{tr('integration.focus.conversionFactor', { unit: row.solastock?.name || '' })}</label>
                        <input id={factorId} className="input" type="text" inputMode="decimal" autoComplete="off"
                            value={conversionFactor} placeholder="12" aria-invalid={conversionFactor !== '' && !conversionValid}
                            onChange={(event) => setConversionDrafts((values) => ({ ...values, [row.fingerprint]: {
                                targetId: values[row.fingerprint]?.targetId ?? defaultTargetId, factor: event.target.value,
                            } }))} />
                        <span><bdi>{conversionTarget?.name || '—'}</bdi></span>
                    </div>
                    <small>{tr('integration.focus.conversionExample', { source: row.solabooks?.name || '—', target: conversionTarget?.name || '—' })}</small>
                    {conversionFactor !== '' && !conversionValid && <small className="is-error" role="alert">{tr('integration.focus.conversionInvalid')}</small>}
                    <small>{tr('integration.focus.conversionDraftOnly')}</small>
                    <div className="focus-conversion-actions">
                        <button type="button" className="btn" disabled={!conversionTargetId || !conversionValid || saving} onClick={async () => {
                            const saved = await choose(row, 'define_unit_conversion', {
                                selected_record_id: conversionTargetId,
                                conversion_factor: String(conversionFactor).trim(),
                            });
                            if (saved) setPendingActions((values) => { const next = { ...values }; delete next[row.fingerprint]; return next; });
                        }}>{tr('integration.focus.saveConversion')}</button>
                        <button type="button" className="btn btn--link" disabled={saving} onClick={() => {
                            setPendingActions((values) => { const next = { ...values }; delete next[row.fingerprint]; return next; });
                            setConversionDrafts((values) => { const next = { ...values }; delete next[row.fingerprint]; return next; });
                        }}>{tr('integration.focus.cancelConversion')}</button>
                    </div>
                </div>}
                {!conversionOpen && current?.action === 'define_unit_conversion' && <div className="focus-conversion-saved">
                    <span>{tr('integration.focus.savedConversion')}</span>
                    <strong><bdi>1 {row.solabooks?.name || '—'} = {current.safe_details?.conversion_factor} {current.safe_details?.target_unit_name || '—'}</bdi></strong>
                    <button type="button" className="btn btn--link" disabled={saving} onClick={() => {
                        setPendingActions((values) => ({ ...values, [row.fingerprint]: 'define_unit_conversion' }));
                        setConversionDrafts((values) => ({ ...values, [row.fingerprint]: {
                            targetId: current.safe_details?.selected_record_id || '', factor: current.safe_details?.conversion_factor || '',
                        } }));
                    }}>{tr('integration.focus.editConversion')}</button>
                </div>}
                {(showManualChoice || current?.action) && <><small className="focus-action-effect">{decisionEffect}</small>
                    {(current?.persistence_state === 'saving' || current?.persistence_state === 'failed' || !current?.action)
                        && <small className={`focus-action-status ${current?.persistence_state === 'failed' ? 'is-error' : ''}`}>
                            {current?.persistence_state === 'saving' ? tr('integration.assistant.saving')
                                : current?.persistence_state === 'failed' ? tr('integration.assistant.saveFailed')
                                    : tr('integration.focus.awaitingDecision')}
                        </small>}</>}
                {recommendation && !showManualChoice && !recommendationSelected
                    && <small className="focus-recommendation-note">{tr('integration.focus.recommendationExcluded')}</small>}
            </div>
        </div>;
    };

    const footer = (primaryLabel, onPrimary, { disabled = false, hideBack = false } = {}) => <footer className="focus-footer">
        <div>{!hideBack && task > 1 && <button type="button" className="btn btn--link" onClick={() => go(task - 1)}>{tr('integration.focus.back')}</button>}
            <span className="focus-footer-feedback">{saveIndicator}{lastSaved && <button type="button" className="btn btn--link focus-footer-undo" onClick={undo}>{tr('integration.focus.undo')}</button>}</span>
        </div>
        <div><button type="button" className="btn btn--primary" disabled={disabled || saving} onClick={onPrimary}>{primaryLabel}</button></div>
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

        if (task === 2) return <section className="focus-card focus-list-card">
            <div className="focus-list-heading"><div><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.businessListTitle')}</h2><p>{tr('integration.focus.businessListText')}</p></div><strong><bdi>{tr('integration.focus.remainingCount', { count: ownerPending.length })}</bdi></strong></div>
            <nav className="focus-category-nav" aria-label={tr('integration.focus.businessSections')}>
                {ownerSections.map(([section, sectionRows]) => {
                    const remaining = sectionRows.filter((row) => !confirmedDecisions.has(row.fingerprint)).length;
                    return <button type="button" key={section} data-section={section} className={activeOwnerSection?.[0] === section ? 'is-active' : remaining === 0 ? 'is-complete' : ''}
                        aria-current={activeOwnerSection?.[0] === section ? 'page' : undefined} onClick={() => setOpenOwnerSection(section)}>
                        <SectionIcon section={section} />
                        <span>{tr(`integration.focus.section.${section}`)}</span>
                        <bdi>{remaining === 0 ? '✓' : remaining}</bdi>
                    </button>;
                })}
            </nav>
            {selectedOwnerRecommendations.length > 0 && <div className="focus-defaults-summary" role="status">
                <span>{tr('integration.focus.recommendationsSelected', { count: selectedOwnerRecommendations.length })}</span>
            </div>}
            {sectionSaveNotice && <div className="focus-section-save-result" role="status">
                ✓ {tr('integration.focus.recommendationsSaved', {
                    count: sectionSaveNotice.count,
                    section: tr(`integration.focus.section.${sectionSaveNotice.section}`),
                })}
            </div>}
            {activeOwnerSection?.[0] === 'items' && exactRows.length > 1 && <button type="button" className="btn btn--link focus-bulk-link" onClick={() => { exactRows.forEach((row) => !bulkSelection.includes(row.fingerprint) && toggleBulk(row.fingerprint)); setBulkReviewOpen(true); }}>{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</button>}
            <div className="focus-active-section">{activeOwnerSection ? (() => {
                const [section, sectionRows] = activeOwnerSection;
                const remaining = sectionRows.filter((row) => !confirmedDecisions.has(row.fingerprint)).length;
                const visibleSectionRows = section !== 'items' ? sectionRows : sectionRows.filter((row) => {
                    if (itemFilter === 'both') return row.solabooks && row.solastock;
                    if (itemFilter === 'solabooksOnly') return row.solabooks && !row.solastock;
                    if (itemFilter === 'solastockOnly') return !row.solabooks && row.solastock;
                    return true;
                });
                return <section className="focus-list-section" key={section}>
                    <header><span><strong>{tr(`integration.focus.section.${section}`)}</strong><small>{tr(`integration.focus.sectionHelp.${section}`)}</small></span><bdi>{tr('integration.focus.sectionProgress', { done: sectionRows.length - remaining, total: sectionRows.length })}</bdi></header>
                    <div className="focus-list-columns" aria-hidden="true"><span>{tr('integration.focus.comparisonColumn')}</span><span>{tr('integration.focus.decisionColumn')}</span></div>
                    {section === 'items' && <div className="focus-item-catalog-summary" aria-label={tr('integration.focus.itemCatalogSummary')}>
                        <div><span>SolaBooks</span><strong><bdi>{itemCounts.solabooks}</bdi></strong></div>
                        <div><span>SolaStock</span><strong><bdi>{itemCounts.solastock}</bdi></strong></div>
                        <div><span>{tr('integration.focus.itemsInBoth')}</span><strong><bdi>{itemCounts.both}</bdi></strong></div>
                        <div><span>{tr('integration.focus.itemsBooksOnly')}</span><strong><bdi>{itemCounts.solabooksOnly}</bdi></strong></div>
                        <div><span>{tr('integration.focus.itemsStockOnly')}</span><strong><bdi>{itemCounts.solastockOnly}</bdi></strong></div>
                    </div>}
                    {section === 'items' && <nav className="focus-item-filters" aria-label={tr('integration.focus.itemFilters')}>
                        {[
                            ['all', tr('integration.focus.filterAll'), itemRows.length],
                            ['both', tr('integration.focus.itemsInBoth'), itemCounts.both],
                            ['solabooksOnly', tr('integration.focus.itemsBooksOnly'), itemCounts.solabooksOnly],
                            ['solastockOnly', tr('integration.focus.itemsStockOnly'), itemCounts.solastockOnly],
                        ].map(([filter, label, count]) => <button type="button" key={filter} className={itemFilter === filter ? 'is-active' : ''}
                            aria-pressed={itemFilter === filter} onClick={() => setItemFilter(filter)}>{label} <bdi>{count}</bdi></button>)}
                    </nav>}
                    <div className="focus-decision-list">{visibleSectionRows.length ? visibleSectionRows.map(compactDecisionRow)
                        : <p className="focus-empty-filter">{tr('integration.focus.noItemsInFilter')}</p>}</div>
                </section>;
            })() : <p>{tr('integration.focus.businessCompleteText')}</p>}</div>
            {footer(tr('integration.focus.continue'), saveRecommendationsAndContinue, {
                disabled: activeSectionPending.some((row) => !recommendedChoice(row)
                    || manualChoiceRows.has(row.fingerprint)
                    || excludedRecommendations.has(row.fingerprint))
                    && !activeSectionPending.some((row) => recommendedChoice(row)
                        && !manualChoiceRows.has(row.fingerprint)
                        && !excludedRecommendations.has(row.fingerprint)),
            })}
        </section>;

        if (task === 3) return <section className="focus-card focus-list-card">
            <div className="focus-list-heading"><div><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.physicalTitle')}</h2><p>{tr('integration.focus.physicalExplanation')}</p></div><strong><bdi>{tr('integration.focus.remainingCount', { count: physicalPending.length })}</bdi></strong></div>
            <div className="focus-count-list">{physicalRows.map((row) => {
                const savedQuantity = confirmedDecisions.get(row.fingerprint)?.safe_details?.physical_quantity ?? '';
                const value = physicalValueFor(row);
                const isSaved = savedQuantity !== '' && String(value) === String(savedQuantity);
                return <div className={`focus-count-row ${isSaved ? 'is-saved' : ''}`} key={row.fingerprint}>
                    <div className="focus-count-item"><strong><bdi>{row.solabooks?.name || row.solastock?.name}</bdi></strong><small><bdi>{row.solabooks?.sku || row.solastock?.sku || '—'}</bdi></small></div>
                    <div className="focus-count-comparison">
                        <span><small>SolaBooks</small><strong><bdi>{formatNumber(row.solabooks?.quantity, 4)}</bdi></strong></span>
                        <span><small>SolaStock</small><strong><bdi>{formatNumber(row.solastock?.quantity, 4)}</bdi></strong></span>
                    </div>
                    <label className="focus-count-input"><span>{tr('integration.focus.actualQuantity')}</span><input className="input" inputMode="decimal" value={value} aria-describedby={`count-note-${row.fingerprint}`}
                        onChange={(event) => setPhysicalValues((values) => ({ ...values, [row.fingerprint]: event.target.value }))} />
                        <small id={`count-note-${row.fingerprint}`}>{isSaved ? tr('integration.focus.countSaved') : tr('integration.focus.stockQuantitySuggested')}</small>
                    </label>
                </div>;
            })}</div>
            {!physicalRows.length && <div className="focus-complete"><h2>{tr('integration.focus.countComplete')}</h2><p>{tr('integration.focus.countCompleteText')}</p></div>}
            {footer(tr('integration.focus.confirmCountsContinue'), savePhysicalCountsAndContinue, {
                disabled: physicalPending.some((row) => !/^\d+(\.\d+)?$/.test(physicalValueFor(row))),
            })}
        </section>;

        if (task === 4) return <section className="focus-card">
            {!accountingGate.allowed ? <div className="focus-accountant-card"><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.accountantRequired')}</h2><p>{tr('integration.focus.accountantRequiredText', { count: accountingPending.length })}</p><a className="btn" href="/settings/users">{tr('integration.focus.assignAccountant')}</a></div>
                : <><div className="focus-list-heading"><div><h2 ref={headingRef} tabIndex="-1">{tr('integration.focus.accountingListTitle')}</h2><p>{tr('integration.focus.accountingCompleteText')}</p></div><strong><bdi>{tr('integration.focus.remainingCount', { count: accountingPending.length })}</bdi></strong></div><div className="focus-decision-list">{accountingRows.map(compactDecisionRow)}</div></>}
            {footer(tr('integration.focus.continue'), saveAccountingRecommendationsAndContinue, {
                disabled: !accountingGate.allowed || (accountingPending.some((row) => !recommendedChoice(row)
                    || manualChoiceRows.has(row.fingerprint)
                    || excludedRecommendations.has(row.fingerprint))
                    && !accountingPending.some((row) => recommendedChoice(row)
                        && !manualChoiceRows.has(row.fingerprint)
                        && !excludedRecommendations.has(row.fingerprint))),
            })}
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
        <section className="focus-overview">
            <div><span className="focus-overview-kicker">{tr('integration.focus.overviewKicker')}</span><h1>{tr('integration.focus.title')}</h1><p>{tr('integration.focus.subtitle')}</p></div>
            <div className="focus-overview-context"><span>{tr('integration.focus.organization')}</span><strong><bdi>{organizationName}</bdi></strong><small>{scenarioText}</small></div>
        </section>
        <nav className="focus-phase-stepper" aria-label={tr('integration.focus.setupProgress')}>
            {[
                [1, tr('integration.focus.phase.prepare'), 1],
                [2, tr('integration.focus.phase.decisions'), 2],
                [3, tr('integration.focus.phase.review'), 6],
            ].map(([number, label, destination]) => <button type="button" key={number} className={phase === number ? 'is-current' : phase > number ? 'is-complete' : ''}
                aria-current={phase === number ? 'step' : undefined} disabled={number > phase} onClick={() => go(destination)}>
                <span>{phase > number ? '✓' : number}</span><strong>{label}</strong>
            </button>)}
        </nav>
        <header className="focus-header">
            <div><span>{tr('integration.focus.stepOf', { current: task, total: totalSteps })}</span><strong>{taskLabels[task - 1]}</strong></div>
            <span>{tr('integration.focus.stepsRemaining', { count: totalSteps - task })}</span>
            <details className="focus-all-steps"><summary>{tr('integration.focus.allSteps')}</summary><ol>{taskLabels.map((label, index) => <li key={label}><button type="button" onClick={() => go(index + 1)} disabled={index + 1 > task}>{label}</button></li>)}</ol></details>
        </header>
        <div className="focus-safety-bar">{tr('integration.focus.safety')} {technical}</div>
        <div className="focus-workspace">
            <main className="focus-stage">{taskContent()}</main>
            <aside className="focus-summary" aria-labelledby="focus-summary-title">
                <h2 id="focus-summary-title">{tr('integration.focus.summaryTitle')}</h2>
                <p>{tr('integration.focus.summaryText')}</p>
                <div className="focus-summary-list">
                    <button type="button" onClick={() => go(2)}><span>{tr('integration.focus.summary.business')}</span><strong><bdi>{resolvedOwner}/{ownerRows.length}</bdi></strong></button>
                    <button type="button" onClick={() => go(3)}><span>{tr('integration.focus.summary.count')}</span><strong><bdi>{resolvedCounts}/{physicalRows.length}</bdi></strong></button>
                    <button type="button" onClick={() => go(4)}><span>{tr('integration.focus.summary.accounting')}</span><strong><bdi>{resolvedAccounting}/{accountingRows.length}</bdi></strong></button>
                    <button type="button" onClick={() => go(5)}><span>{tr('integration.focus.summary.documents')}</span><strong><bdi>{cutoffRows.length}</bdi></strong></button>
                </div>
                <div className="focus-authority"><p><span>{tr('integration.focus.inventoryAuthority')}</span><strong>SolaStock</strong></p><p><span>{tr('integration.focus.accountingAuthority')}</span><strong>SolaBooks</strong></p></div>
            </aside>
        </div>
        {bulkReviewOpen && <div className="assistant-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-title"><div className="assistant-bulk-dialog__content"><h2 id="bulk-title">{tr('integration.assistant.confirmExactMatches', { count: exactRows.length })}</h2><p>{tr('integration.assistant.bulkDraftOnly')}</p>{exactRows.map((row) => <label key={row.fingerprint}><input type="checkbox" checked={bulkSelection.includes(row.fingerprint)} onChange={() => toggleBulk(row.fingerprint)} /><span><bdi>{row.solabooks?.name}</bdi> → <bdi>{row.solastock?.name}</bdi></span></label>)}<div className="doc-actions"><button className="btn" onClick={() => setBulkReviewOpen(false)}>{tr('settings.common.cancel')}</button><button className="btn btn--primary" onClick={async () => { if (await bulk('approve_exact_sku_candidates')) setBulkReviewOpen(false); }}>{tr('integration.assistant.confirmSelectedMatches', { count: bulkSelection.length })}</button></div></div></div>}
    </div>;
}
