import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { useSettingsTranslation } from '../i18n/useSettingsTranslation.js';
import { useTenant } from '../stores/tenant.jsx';
import GuidedConnectionAssistant from '../components/GuidedConnectionAssistant.jsx';
import { canonicalDecision, createSerializedMutationQueue, mayAcceptCanonicalDraft, unwrapCanonicalDraft } from '../services/wizardDraftSync.js';

function ConnectionPhaseBadges({ status, setupAllowed, tr }) {
    const setup = status?.setup_status === 'available' || setupAllowed ? 'available' : 'unavailable';
    const draft = status?.draft_status === 'in_progress' ? 'in_progress' : 'not_started';
    const activation = status?.activation_status || 'safely_paused';
    const delivery = status?.delivery_status || (status?.delivery_enabled ? 'enabled' : 'disabled');
    return <div className="connection-phase-badges" role="status" aria-label={tr('integration.phase.summary')}>
        <span className={`badge ${setup === 'available' ? 'badge--live' : 'badge--muted'}`}><strong>{tr('integration.phase.setup')}:</strong> {tr(`integration.phase.${setup}`)}</span>
        <span className={`badge ${draft === 'in_progress' ? 'badge--demo' : 'badge--muted'}`}><strong>{tr('integration.phase.draft')}:</strong> {tr(`integration.phase.${draft}`)}</span>
        <span className="badge badge--warn"><strong>{tr('integration.phase.activation')}:</strong> {tr(`integration.phase.${activation}`)}</span>
        <span className={`badge ${delivery === 'enabled' ? 'badge--live' : 'badge--warn'}`}><strong>{tr('integration.phase.delivery')}:</strong> {tr(`integration.phase.${delivery}`)}</span>
    </div>;
}

function CompactIntegrationStatus({ status, tr, organizationName, onContinue }) {
    const wizard = status.connection_wizard || {};
    const remaining = wizard.decisions_remaining;
    const setupInProgress = status.draft_status === 'in_progress';
    return <div className="connection-business-status">
        <header className="assistant-hero connection-status-hero">
            <div className="assistant-hero-copy">
                <p className="assistant-kicker">{tr('integration.assistant.kicker')}</p>
                <h1>{tr('integration.assistant.pageTitle')}</h1>
                <p>{tr('integration.assistant.pageIntroduction')}</p>
                <div className="assistant-context"><strong>{organizationName}</strong></div>
            </div>
            <div className="assistant-next">
                <span>{tr('integration.assistant.nextAction')}</span>
                <strong>{tr('integration.assistant.continueSetup')}</strong>
                <button type="button" className="btn btn--primary" onClick={onContinue}>{tr('integration.assistant.continueSetup')}</button>
            </div>
            <div className="assistant-status-line" role="status">
                <span>{tr('integration.assistant.safePause')}</span>
                <details><summary>{tr('integration.assistant.statusDetails')}</summary><p>{tr('integration.assistant.statusDetailsText')}</p></details>
            </div>
        </header>
        <section className="connection-status-card" aria-labelledby="connection-status-heading">
            <h2 id="connection-status-heading">{tr('integration.businessStatus.title')}</h2>
            <div className="connection-status-list">
                <div><span>{tr('integration.businessStatus.setup')}</span><strong>{tr(setupInProgress ? 'integration.businessStatus.inProgress' : 'integration.phase.available')}</strong></div>
                <div><span>{tr('integration.businessStatus.currentStep')}</span><strong>{tr(`integration.businessStatus.step.${wizard.current_step || 'automatic_checks'}`)}</strong></div>
                {remaining !== null && remaining !== undefined && <div><span>{tr('integration.businessStatus.remaining')}</span><strong><bdi>{tr('integration.assistant.remainingRecords', { count: remaining })}</bdi></strong></div>}
                <div><span>{tr('integration.businessStatus.inventoryAuthority')}</span><strong><bdi>{tr('integration.businessStatus.solastock')}</bdi></strong></div>
                <div><span>{tr('integration.businessStatus.accountingAuthority')}</span><strong><bdi>{tr('integration.businessStatus.solabooks')}</bdi></strong></div>
                <div><span>{tr('integration.phase.activation')}</span><strong>{tr('integration.phase.safely_paused')}</strong></div>
                <div><span>{tr('integration.phase.delivery')}</span><strong>{tr('integration.phase.disabled')}</strong></div>
            </div>
            <details className="assistant-details connection-status-technical"><summary>{tr('integration.assistant.technicalDetails')}</summary>
                <dl className="kv">
                    <dt>{tr('integration.transport.worker')}</dt><dd>{tr(status.transport?.worker_enabled && status.transport?.worker_running ? 'integration.transport.running' : 'integration.transport.disabled')}</dd>
                    <dt>{tr('integration.transport.receiver')}</dt><dd>{tr(status.transport?.receiver_enabled ? 'integration.details.yes' : 'integration.details.no')}</dd>
                    <dt>{tr('integration.details.lastSync')}</dt><dd><bdi>{status.last_sync_at || '—'}</bdi></dd>
                    <dt>{tr('integration.authority.lastRead')}</dt><dd><bdi>{status.inventory_authority?.last_successful_read_at || '—'}</bdi></dd>
                    <dt>{tr('integration.metrics.pending')}</dt><dd><bdi>{status.events?.pending ?? 0}</bdi></dd>
                    <dt>{tr('integration.metrics.failed')}</dt><dd><bdi>{status.events?.failed ?? 0}</bdi></dd>
                    <dt>{tr('integration.metrics.ignored')}</dt><dd><bdi>{status.events?.ignored ?? 0}</bdi></dd>
                </dl>
            </details>
        </section>
    </div>;
}

export default function IntegrationSettingsPage() {
    const tr = useSettingsTranslation();
    const tenant = useTenant();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.integration.manage');
    const setupGate = useCanCreate('inventory.integration.setup');
    const accountingGate = useCanCreate('inventory.integration.accounting_review');
    const [tab, setTab] = useState('status');
    const [wizardResumeStep, setWizardResumeStep] = useState(1);
    const [connection, setConnection] = useState({ mode: 'connected_pending_mapping', client_id: '', solabooks_organization_id: '', api_key: '', require_mapping_before_post: true });
    const [savingConnection, setSavingConnection] = useState(false);
    const [rotatingKey, setRotatingKey] = useState(false);

    const status = useApiQuery(['integration-status'], api.integrationStatus, {
        fallback: null,
        refetchOnMount: 'always',
        refetchOnWindowFocus: 'always',
        refetchInterval: 15_000,
    });
    const s = status.data;
    const connectionActivated = Boolean(s && (s.mode === 'active' || s.connection_wizard?.state === 'connected'));
    useEffect(() => {
        if (s || tenant.client_id) {
            setConnection((current) => ({
                ...current,
                mode: s?.mode === 'disconnected' ? 'connected_pending_mapping' : (s?.mode ?? current.mode),
                client_id: String(s?.client_id ?? tenant.client_id ?? current.client_id),
                solabooks_organization_id: s?.solabooks_organization_id ?? '',
            }));
        }
    }, [s, tenant.client_id]);

    async function saveConnection() {
        setSavingConnection(true);
        try {
            await api.configureIntegration({ ...connection, client_id: Number(connection.client_id), solabooks_organization_id: Number(connection.solabooks_organization_id), api_key: connection.api_key || undefined });
            setConnection((current) => ({ ...current, api_key: '' }));
            await qc.invalidateQueries({ queryKey: ['integration-status'] });
            toast.push(tr('integration.connection.saved'), 'success');
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); }
        finally { setSavingConnection(false); }
    }

    async function rotateSigningKey() {
        setRotatingKey(true);
        try {
            await api.rotateIntegrationSigningKey();
            await qc.invalidateQueries({ queryKey: ['integration-status'] });
            toast.push(tr('integration.connection.keyGenerated'), 'success');
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); }
        finally { setRotatingKey(false); }
    }

    return (
        <section className="page page--connection-assistant">
            <Breadcrumbs items={[{ label: tr('integration.settingsBreadcrumb'), to: '/settings' }, { label: tr('integration.title') }]} />
            <header className="page-head">
                <h1>{tr('integration.title')}</h1>
            </header>

            {connectionActivated && <Tabs tabs={[{ key: 'status', label: tr('integration.tabs.status') }, { key: 'wizard', label: tr('integration.tabs.wizard') }]} active={tab} onChange={setTab} />}

            {connectionActivated && tab === 'status' && (status.isLoading ? <Skeleton /> : status.isError ? (
                <EmptyState
                    title={tr('integration.loadFailed')}
                    hint={status.error?.message || tr('settings.common.errorFallback')}
                    action={<button className="btn btn--primary" onClick={() => status.refetch()}>{tr('integration.retry')}</button>}
                />
            ) : !s ? <EmptyState title={tr('integration.unavailable')} hint={tr('integration.noStatus')} /> : (
                <>
                    <CompactIntegrationStatus status={s} tr={tr} organizationName={tenant.organization_name}
                        onContinue={() => { setWizardResumeStep(2); setTab('wizard'); }} />
                    <div hidden aria-hidden="true">
                    {!s.delivery_enabled && <div className="panel" role="status" style={{ borderInlineStart: '4px solid var(--warning, #b7791f)' }}>
                        <h2>{tr('integration.phase.activationPaused')}</h2>
                        <p>{tr('integration.phase.setupWhilePaused')}</p>
                    </div>}
                    <div className="widget-grid">
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.mode')}</div><div className="widget-card-value" style={{ fontSize: 18 }}>{tr(`integration.connection.${s.mode === 'connected_readonly' ? 'readOnly' : s.mode === 'connected_pending_mapping' ? 'pendingMappings' : s.mode}`, {}, s.mode)}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.pending')}</div><div className="widget-card-value">{s.events?.pending ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.failed')}</div><div className="widget-card-value">{s.events?.failed ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.ignored')}</div><div className="widget-card-value">{s.events?.ignored ?? 0}</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.mapping')}</div><div className="widget-card-value">{s.mapping_completeness_pct ?? 0}%</div></div>
                        <div className="widget-card"><div className="widget-card-label">{tr('integration.metrics.taxMapping')}</div><div className="widget-card-value">{s.tax_mapping_completeness_pct ?? 0}%</div></div>
                    </div>
                    <div className="panel" role="status">
                        <h2>{tr('integration.authority.title')}</h2>
                        <p>{tr('integration.authority.description')}</p>
                        <div className="widget-grid">
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.authority.mapped')}</div><div className="widget-card-value">{s.inventory_authority?.mapped ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.authority.missing')}</div><div className="widget-card-value">{s.inventory_authority?.missing ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.authority.conflicting')}</div><div className="widget-card-value">{s.inventory_authority?.conflicting ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.authority.archived')}</div><div className="widget-card-value">{s.inventory_authority?.archived ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.authority.review')}</div><div className="widget-card-value">{s.inventory_authority?.review_required ?? 0}</div></div>
                        </div>
                        <dl className="kv">
                            <dt>{tr('integration.authority.state')}</dt><dd>{tr(`integration.authority.${s.inventory_authority?.state ?? 'unavailable'}`)}</dd>
                            <dt>{tr('integration.authority.lastRead')}</dt><dd>{s.inventory_authority?.last_successful_read_at ?? '—'}</dd>
                            <dt>{tr('integration.authority.mappingId')}</dt><dd>{s.organization_mapping?.mapping_uuid ?? tr('integration.details.notConfigured')}</dd>
                            <dt>{tr('integration.authority.v2Scope')}</dt><dd>{s.organization_mapping?.v2_key_scope_status ?? tr('integration.details.notConfigured')}</dd>
                        </dl>
                    </div>
                    <div className="panel" role="status">
                        <h2>{tr('integration.workflow.title')}</h2>
                        <p>{tr('integration.workflow.description')}</p>
                        <div className="widget-grid">
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.purchasing')}</div><div className="widget-card-value">{s.workflow_lifecycle?.purchasing ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.sales')}</div><div className="widget-card-value">{s.workflow_lifecycle?.sales ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.matched')}</div><div className="widget-card-value">{s.workflow_lifecycle?.matched ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.pending')}</div><div className="widget-card-value">{s.workflow_lifecycle?.pending ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.conflicting')}</div><div className="widget-card-value">{s.workflow_lifecycle?.conflicting ?? 0}</div></div>
                            <div className="widget-card"><div className="widget-card-label">{tr('integration.workflow.review')}</div><div className="widget-card-value">{s.workflow_lifecycle?.review_required ?? 0}</div></div>
                        </div>
                        <p className="muted">{tr('integration.workflow.ownership')}</p>
                    </div>
                    <div className="panel" role="status">
                        <h2>{tr('integration.transport.title')}</h2>
                        <p>{tr('integration.transport.description')}</p>
                        <div className="widget-grid">
                            {['ready', 'processing', 'retry_scheduled', 'failed', 'dead_letter', 'blocked_mapping'].map((state) =>
                                <div className="widget-card" key={state}>
                                    <div className="widget-card-label">{tr(`integration.status.${state}`)}</div>
                                    <div className="widget-card-value">{s.transport?.counts?.[state] ?? 0}</div>
                                </div>)}
                        </div>
                        <dl className="kv">
                            <dt>{tr('integration.transport.worker')}</dt><dd>{tr(s.transport?.worker_enabled && s.transport?.worker_running ? 'integration.transport.running' : 'integration.transport.disabled')}</dd>
                            <dt>{tr('integration.transport.receiver')}</dt><dd>{tr(s.transport?.receiver_enabled ? 'integration.details.yes' : 'integration.details.no')}</dd>
                            <dt>{tr('integration.transport.queue')}</dt><dd>{s.transport?.queue ?? '—'}</dd>
                            <dt>{tr('integration.transport.oldest')}</dt><dd>{s.transport?.oldest_actionable_at ?? '—'}</dd>
                            <dt>{tr('integration.transport.expired')}</dt><dd>{s.transport?.expired_leases ?? 0}</dd>
                            <dt>{tr('integration.transport.reconciliation')}</dt><dd>{s.transport?.last_reconciliation_at ?? tr('integration.transport.notScheduled')}</dd>
                        </dl>
                    </div>
                    <div className="panel">
                        <dl className="kv">
                            <dt>{tr('integration.details.linkedOrg')}</dt><dd>{s.solabooks_organization_id ? `#${s.solabooks_organization_id}` : tr('integration.details.notLinked')}</dd>
                            <dt>{tr('integration.details.lastSync')}</dt><dd>{s.last_sync_at ?? '—'}</dd>
                            <dt>{tr('integration.details.lastGenerated')}</dt><dd>{s.last_event_generated_at ?? '—'}</dd>
                            <dt>{tr('integration.events.lastError')}</dt><dd>{s.last_error ?? '—'}</dd>
                            <dt>{tr('integration.details.implemented')}</dt><dd>{s.connection_implemented ? tr('integration.details.yes') : tr('integration.details.placeholder')}</dd>
                            <dt>{tr('integration.details.signingProtocol')}</dt><dd>{s.signing?.protocol_version ?? tr('integration.details.notConfigured')}</dd>
                            <dt>{tr('integration.details.signingKeyId')}</dt><dd>{s.signing?.key_id ?? '—'}</dd>
                            <dt>{tr('integration.details.lastSigned')}</dt><dd>{s.signing?.last_successful_delivery_at ?? '—'}</dd>
                            <dt>{tr('integration.details.deliveryEnabled')}</dt><dd>{tr(s.delivery_enabled ? 'integration.details.yes' : 'integration.details.no')}</dd>
                            <dt>{tr('integration.details.deliveryReason')}</dt><dd>{s.delivery_disabled_reason ? tr('integration.safetyHold.reason') : '—'}</dd>
                            <dt>{tr('integration.details.legacyBlocked')}</dt><dd>{tr(s.legacy_finance_inventory_writes_blocked ? 'integration.details.yes' : 'integration.details.no')}</dd>
                        </dl>
                        <div className="doc-actions">
                            <Link className="btn btn--primary" to="/integrations/solabooks/events">{tr('integration.details.viewEvents')}</Link>
                        </div>
                        <p className="muted">{tr('integration.details.deliveryDescription')}</p>
                    </div>
                    <div className="panel">
                        <h2>{tr('integration.connection.title')}</h2>
                        <p className="muted">{tr('integration.connection.apiKeySecurity')}</p>
                        <div className="fg2">
                            <label className="field"><span className="field-label">{tr('integration.metrics.mode')}</span><select className="input" disabled={!gate.allowed || !s.delivery_enabled} value={connection.mode} onChange={(e) => setConnection({ ...connection, mode: e.target.value })}><option value="connected_readonly">{tr('integration.connection.readOnly')}</option><option value="connected_pending_mapping">{tr('integration.connection.pendingMappings')}</option><option value="active">{tr('integration.connection.active')}</option><option value="paused">{tr('integration.connection.paused')}</option></select></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.clientId')}</span><input className="input" type="number" disabled={!gate.allowed} value={connection.client_id} onChange={(e) => setConnection({ ...connection, client_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.organizationId')}</span><input className="input" type="number" disabled={!gate.allowed} value={connection.solabooks_organization_id} onChange={(e) => setConnection({ ...connection, solabooks_organization_id: e.target.value })} /></label>
                            <label className="field"><span className="field-label">{tr('integration.connection.apiKey')}</span><input className="input" type="password" autoComplete="new-password" disabled={!gate.allowed} value={connection.api_key} onChange={(e) => setConnection({ ...connection, api_key: e.target.value })} placeholder={s?.delivery_configured ? tr('integration.connection.keepKey') : tr('integration.connection.pasteKey')} /></label>
                        </div>
                        <button className="btn btn--primary" disabled={!gate.allowed || savingConnection || !s.delivery_enabled} onClick={saveConnection}>{savingConnection ? tr('integration.connection.saving') : tr('integration.connection.save')}</button>
                        <button className="btn" style={{ marginInlineStart: 8 }} disabled={!gate.allowed || rotatingKey || !s?.delivery_configured || !s.delivery_enabled} onClick={rotateSigningKey}>{rotatingKey ? tr('integration.connection.rotating') : (s?.signing?.configured ? tr('integration.connection.rotate') : tr('integration.connection.generate'))}</button>
                        <p className="muted">{tr('integration.connection.signingDescription')}</p>
                    </div>
                    </div>
                </>
            ))}

            {(!connectionActivated || tab === 'wizard') && <ConnectionWizard key={`wizard-${wizardResumeStep}`} initialAssistantStep={wizardResumeStep} gate={setupGate} accountingGate={accountingGate} status={s} toast={toast} tr={tr} organizationName={tenant.organization_name} />}
        </section>
    );
}

function ConnectionWizard({ gate, accountingGate, status, toast, tr, organizationName, initialAssistantStep = 1 }) {
    const queryClient = useQueryClient();
    const actionsByEntity = {
        item: {
            exact_candidate_requires_owner_review: ['approve_exact_binding', 'reject_exact_binding', 'physical_count_required', 'retain_blocked'],
            // A Finance-only record cannot be classified as inventory without
            // proposing its Stock record. Those were two internal actions for
            // the same owner decision and produced duplicate dropdown labels.
            missing_solastock_record: ['create_solastock_record', 'classify_service_non_inventory', 'exclude_initial_connection', 'physical_count_required', 'retain_blocked'],
            missing_solabooks_record: ['keep_solastock_authority', 'exclude_initial_connection', 'physical_count_required', 'retain_blocked'],
            default: ['retain_blocked', 'physical_count_required'],
        },
        unit: ['select_unit', 'define_unit_conversion', 'retain_blocked'],
        category: ['select_category', 'propose_category_creation', 'retain_blocked'],
        warehouse: ['select_warehouse', 'retain_blocked'],
        customer: ['propose_finance_party_creation', 'select_party', 'retain_blocked'],
        supplier: ['propose_finance_party_creation', 'select_party', 'retain_blocked'],
        tax: ['select_tax', 'retain_blocked'],
        currency: ['retain_currency', 'exclude_currency', 'retain_blocked'],
        historical_event: ['retain_historical_exclusion'],
        cutoff_document: ['review_cutoff_document', 'retain_blocked'],
        account_role: ['select_account_role', 'retain_account_role_unresolved'],
    };
    const stateOrder = ['setup_available', 'draft_decisions', 'decisions_complete', 'snapshot_required', 'cutoff_review', 'preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready', 'connected'];
    const discovery = useApiQuery(['integration-wizard-discovery'], api.integrationWizardDiscovery, { fallback: null, enabled: true });
    const [runUuid, setRunUuid] = useState('');
    const [saving, setSaving] = useState(false);
    const [saveState, setSaveState] = useState('idle');
    const [pendingDecisions, setPendingDecisions] = useState(new Map());
    const [failedDecision, setFailedDecision] = useState(null);
    const [canonicalRun, setCanonicalRun] = useState(null);
    const canonicalRunRef = useRef(null);
    const saveQueueRef = useRef(createSerializedMutationQueue());
    const [confirmation, setConfirmation] = useState('');
    const [bulkSelection, setBulkSelection] = useState([]);
    const [cutoffAt, setCutoffAt] = useState('');
    const [assistantStep, setAssistantStep] = useState(initialAssistantStep);
    const [exceptionSearch, setExceptionSearch] = useState('');
    const run = useApiQuery(['integration-wizard-preview', runUuid], () => api.integrationWizardPreview(runUuid), {
        fallback: null,
        enabled: Boolean(runUuid),
    });
    const acceptCanonicalRun = (incoming) => {
        if (!mayAcceptCanonicalDraft(canonicalRunRef.current, incoming, runUuid)) return false;
        canonicalRunRef.current = incoming;
        setCanonicalRun(incoming);
        queryClient.setQueryData(['integration-wizard-preview', runUuid], incoming);
        return true;
    };
    useEffect(() => {
        canonicalRunRef.current = null;
        setCanonicalRun(null);
        setPendingDecisions(new Map());
        setFailedDecision(null);
        setSaveState('idle');
    }, [runUuid]);
    useEffect(() => {
        if (runUuid && run.data) acceptCanonicalRun(run.data);
    }, [runUuid, run.data]);
    const runView = canonicalRun || run.data;
    const runHandle = { ...run, data: runView };
    const view = runUuid ? runView : discovery.data;
    useEffect(() => {
        const resumable = discovery.data?.active_draft?.run_uuid;
        if (!runUuid && resumable) setRunUuid(resumable);
    }, [discovery.data?.active_draft?.run_uuid, runUuid]);

    async function start() {
        setSaving(true);
        try {
            const response = await api.startIntegrationWizard();
            setRunUuid(response.data.run_uuid);
            toast.push(tr('integration.wizard.started'), 'success');
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); }
        finally { setSaving(false); }
    }

    function decide(row, action, extraSafeDetails = {}) {
        setSaveState('saving');
        setPendingDecisions((current) => new Map(current).set(row.fingerprint, {
            candidate_fingerprint: row.fingerprint, action, persistence_state: 'saving',
        }));
        const selectedRunUuid = runUuid;
        const selectedIdentity = runView?.identity;
        return saveQueueRef.current.enqueue(async (mutationSequence) => {
          setSaving(true);
          try {
            const currentDraft = canonicalRunRef.current;
            if (!currentDraft || currentDraft.run_uuid !== selectedRunUuid) throw new Error('wizard_draft_context_changed');
            const existing = canonicalDecision(currentDraft, row.fingerprint);
            const requestedDetailsAlreadySaved = Object.entries(extraSafeDetails).every(([key, value]) =>
                String(existing?.safe_details?.[key] ?? '') === String(value ?? ''));
            if (existing?.action === action && requestedDetailsAlreadySaved) {
                setPendingDecisions((current) => { const next = new Map(current); next.delete(row.fingerprint); return next; });
                setSaveState('saved');
                return currentDraft;
            }
            const response = await api.saveIntegrationWizardDecision(selectedRunUuid, {
                candidate_fingerprint: row.fingerprint,
                action,
                solastock_record_ids: row.solastock_record_ids,
                solabooks_record_ids: row.solabooks_record_ids,
                safe_details: { reason: row.blocking_reason, decision_class: row.decision_class, ...extraSafeDetails },
                expected_lock_version: currentDraft.lock_version,
                expected_before_hash: row.candidate_before_hash,
            });
            const confirmed = unwrapCanonicalDraft(response);
            const decision = canonicalDecision(confirmed, row.fingerprint);
            if (confirmed.run_uuid !== selectedRunUuid
                || String(confirmed.identity?.central_organization_id ?? '') !== String(selectedIdentity?.central_organization_id ?? '')
                || !decision || decision.action !== action) {
                throw new Error('wizard_save_confirmation_mismatch');
            }
            if (!acceptCanonicalRun(confirmed)) throw new Error('wizard_stale_save_response');
            setPendingDecisions((current) => { const next = new Map(current); next.delete(row.fingerprint); return next; });
            setFailedDecision(null);
            setSaveState('saved');
            return confirmed;
          } catch (error) {
            const conflict = error?.status === 409 || error?.code === 'draft_optimistic_lock_conflict'
                || String(error.message || '').includes('draft_optimistic_lock_conflict');
            setPendingDecisions((current) => new Map(current).set(row.fingerprint, {
                candidate_fingerprint: row.fingerprint, action, persistence_state: conflict ? 'conflict' : 'failed',
            }));
            setFailedDecision({ row, action, extraSafeDetails, mutationSequence });
            setSaveState(conflict ? 'conflict' : 'failed');
            toast.push(error.message || tr('settings.common.errorFallback'), 'error');
            throw error;
          } finally { setSaving(false); }
        }).catch(() => null);
    }

    async function reconcileLatest({ discardUnsaved = false } = {}) {
        const result = await run.refetch({ cancelRefetch: true });
        const incoming = result?.data;
        if (incoming) acceptCanonicalRun(incoming);
        if (discardUnsaved) {
            setPendingDecisions(new Map());
            setFailedDecision(null);
            setSaveState('idle');
        }
    }

    async function undoDecision(decisionUuid) {
        if (!decisionUuid || !runUuid) return false;
        setSaving(true);
        try {
            const response = await api.reverseIntegrationWizardDecision(runUuid, decisionUuid);
            const confirmed = unwrapCanonicalDraft(response);
            if (!acceptCanonicalRun(confirmed)) throw new Error('wizard_stale_reverse_response');
            setSaveState('saved');
            return true;
        } catch (error) {
            setSaveState('failed');
            toast.push(error.message || tr('settings.common.errorFallback'), 'error');
            return false;
        } finally { setSaving(false); }
    }

    async function runAction(callback, successKey) {
        setSaving(true);
        try {
            await callback();
            await reconcileLatest();
            setConfirmation('');
            toast.push(tr(successKey), 'success');
            return true;
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); return false; }
        finally { setSaving(false); }
    }

    const decisions = new Map((runView?.decisions || []).filter((decision) => decision.valid_for_current_candidate !== false)
        .map((decision) => [decision.candidate_fingerprint, decision]));
    const displayDecisions = new Map(decisions);
    pendingDecisions.forEach((decision, fingerprint) => displayDecisions.set(fingerprint, decision));
    const allowedActions = (row) => {
        const configured = actionsByEntity[row.entity_type] || ['retain_blocked'];
        return Array.isArray(configured) ? configured : (configured[row.classification] || configured.default);
    };
    const canEdit = (row) => row.decision_class === 'accountant_decision' ? accountingGate.allowed : gate.allowed;
    const editableState = ['draft_decisions', 'decisions_complete', 'snapshot_required'].includes(runView?.state);
    const toggleBulk = (fingerprint) => setBulkSelection((current) => current.includes(fingerprint)
        ? current.filter((value) => value !== fingerprint) : [...current, fingerprint]);
    async function bulk(action) {
        setSaveState('saving');
        const saved = await runAction(() => api.bulkIntegrationWizardDecision(runUuid, {
            bulk_action: action,
            candidate_fingerprints: bulkSelection,
            confirmation: `CONFIRM ${bulkSelection.length} RECORDS`,
            expected_lock_version: runView.lock_version,
        }), 'integration.wizard.bulkSaved');
        setSaveState(saved ? 'saved' : 'failed');
        if (saved) setBulkSelection([]);
        return saved;
    }

    function exportComparison() {
        const rows = view?.comparison ?? [];
        const headings = ['entity_type', 'classification', 'solastock_id', 'solabooks_id', 'sku_stock', 'sku_books', 'quantity_stock', 'quantity_books', 'quantity_difference', 'value_stock', 'value_books', 'value_difference', 'blocking_reason'];
        const csv = [headings, ...rows.map((row) => [
            row.entity_type, row.classification, row.solastock?.id, row.solabooks?.id,
            row.solastock?.sku || row.solastock?.code, row.solabooks?.sku || row.solabooks?.code,
            row.solastock?.quantity, row.solabooks?.quantity, row.quantity_difference,
            row.solastock?.inventory_value, row.solabooks?.inventory_value, row.value_difference, row.blocking_reason,
        ])].map((line) => line.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url; link.download = `solabooks-solastock-comparison-${view?.snapshot_hash?.slice(0, 12) || 'preview'}.csv`;
        link.click(); URL.revokeObjectURL(url);
    }

    if (discovery.isLoading || (runUuid && run.isLoading)) return <Skeleton />;
    if (discovery.isError || (runUuid && run.isError)) return <EmptyState title={tr('integration.loadFailed')} hint={(run.error || discovery.error)?.message || tr('settings.common.errorFallback')} action={<button className="btn" onClick={() => (runUuid ? run.refetch() : discovery.refetch())}>{tr('integration.retry')}</button>} />;
    if (!view) return <EmptyState title={tr('integration.unavailable')} hint={tr('integration.noStatus')} />;

    const totals = view.totals || {};
    const accounting = view.accounting || {};
    const rows = view.comparison || [];
    if (view.guided_setup) {
        return <GuidedConnectionAssistant
            view={view} runUuid={runUuid} run={runHandle} gate={gate} accountingGate={accountingGate}
            status={status} saving={saving} tr={tr} decisions={displayDecisions} confirmedDecisions={decisions} pendingDecisions={pendingDecisions} rows={rows}
            totals={totals} accounting={accounting} assistantStep={assistantStep}
            setAssistantStep={setAssistantStep} exceptionSearch={exceptionSearch}
            setExceptionSearch={setExceptionSearch} allowedActions={allowedActions}
            canEdit={canEdit} editableState={editableState} decide={decide} undoDecision={undoDecision} bulkSelection={bulkSelection}
            toggleBulk={toggleBulk} bulk={bulk} exportComparison={exportComparison} start={start}
            runAction={runAction} cutoffAt={cutoffAt} setCutoffAt={setCutoffAt}
            confirmation={confirmation} setConfirmation={setConfirmation}
            saveState={saveState}
            retrySave={() => failedDecision && decide(failedDecision.row, failedDecision.action, failedDecision.extraSafeDetails)}
            reloadLatest={() => reconcileLatest()}
            organizationName={organizationName}
        />;
    }
    return <div className="wizard" aria-live="polite">
        <div className="panel wizard__intro">
            <h2>{tr('integration.wizard.title')}</h2>
            <div className="status-line" role="status"><span className="status-dot" />{tr(`integration.wizard.state.${view.state || view.connection_state || 'temporarily_unavailable'}`, {}, view.state || view.connection_state)}</div>
            <ConnectionPhaseBadges status={status} setupAllowed={gate.allowed} tr={tr} />
            <p className="wizard__safe-setup-message" role="status">{tr('integration.phase.setupWhilePaused')}</p>
            <p>{tr('integration.wizard.authority')}</p>
            <p className="muted">{tr('integration.wizard.noMerge')}</p>
            <div className="doc-actions">
                {!runUuid && <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={start}>{saving ? tr('integration.wizard.starting') : tr('integration.wizard.startDraft')}</button>}
                <button className="btn" onClick={exportComparison}>{tr('integration.wizard.export')}</button>
                {runUuid && editableState && gate.allowed && <button className="btn" disabled={saving} onClick={() => runAction(() => api.resetIntegrationWizardDraft(runUuid), 'integration.wizard.resetDone')}>{tr('integration.wizard.reset')}</button>}
                {runUuid && gate.allowed && <button className="btn btn--danger" disabled={saving} onClick={() => runAction(async () => { await api.discardIntegrationWizardDraft(runUuid); setRunUuid(''); await discovery.refetch(); }, 'integration.wizard.discarded')}>{tr('integration.wizard.discard')}</button>}
            </div>
        </div>

        {view.blockers?.length > 0 && <div className="panel" role="status">
            <h3>{tr('integration.wizard.setupBlockers')}</h3>
            <ul>{view.blockers.map((blocker) => <li key={blocker}>{tr(`integration.wizard.block.${blocker}`, {}, blocker)}</li>)}</ul>
            {!view.organization_mapping_uuid && <p className="muted">{tr('integration.wizard.readinessOnly')}</p>}
        </div>}

        {view.readiness && <div className="widget-grid" aria-label={tr('integration.wizard.masterReadiness')}>
            {Object.entries(view.readiness).map(([key, value]) => <div className="widget-card" key={key}><div className="widget-card-label">{tr(`integration.wizard.readiness.${key}`, {}, key)}</div><div className="widget-card-value">{value}</div></div>)}
        </div>}

        <ol className="wizard__steps" aria-label={tr('integration.wizard.progress')}>
            {stateOrder.map((step, index) => <li key={step} className={index <= Math.max(0, stateOrder.indexOf(view.state || view.connection_state)) ? 'is-current' : ''}>{tr(`integration.wizard.state.${step}`, {}, step)}</li>)}
        </ol>

        <div className="widget-grid">
            {['exact_matches', 'review_required', 'missing_in_solastock', 'missing_in_solabooks', 'ambiguous', 'blocked'].map((key) => <div className="widget-card" key={key}><div className="widget-card-label">{tr(`integration.wizard.total.${key}`)}</div><div className="widget-card-value">{totals[key] ?? 0}</div></div>)}
        </div>
        <div className="panel">
            <h3>{tr('integration.wizard.differences')}</h3>
            <dl className="kv">
                <dt>{tr('integration.wizard.quantityDifference')}</dt><dd>{totals.total_quantity_difference ?? '0.0000'}</dd>
                <dt>{tr('integration.wizard.valueDifference')}</dt><dd>{totals.total_valuation_difference ?? '0.00'} {accounting.base_currency || ''}</dd>
                <dt>{tr('integration.wizard.snapshot')}</dt><dd className="wizard__hash">{view.snapshot_frozen_at ? `${view.snapshot_id} · ${view.snapshot_hash}` : tr('integration.wizard.notFrozen')}</dd>
            </dl>
        </div>

        {runUuid && editableState && gate.allowed && <div className="panel" role="group" aria-label={tr('integration.wizard.bulkTitle')}>
            <h3>{tr('integration.wizard.bulkTitle')}</h3>
            <p className="muted">{tr('integration.wizard.bulkExplain', { count: bulkSelection.length })}</p>
            <div className="doc-actions">
                <button className="btn" disabled={saving || bulkSelection.length === 0} onClick={() => bulk('approve_exact_sku_candidates')}>{tr('integration.wizard.bulkExact')}</button>
                <button className="btn" disabled={saving || bulkSelection.length === 0} onClick={() => bulk('retain_historical_exclusions')}>{tr('integration.wizard.bulkHistorical')}</button>
                <button className="btn" disabled={saving || bulkSelection.length === 0} onClick={() => bulk('exclude_service_non_inventory_records')}>{tr('integration.wizard.bulkServices')}</button>
            </div>
        </div>}

        <div className="panel wizard__table-wrap">
            <h3>{tr('integration.wizard.comparison')}</h3>
            <table className="data-table wizard__comparison">
                <thead><tr><th>{tr('integration.wizard.select')}</th><th>{tr('integration.wizard.entity')}</th><th>{tr('integration.wizard.solastock')}</th><th>{tr('integration.wizard.solabooks')}</th><th>{tr('integration.wizard.quantity')}</th><th>{tr('integration.wizard.value')}</th><th>{tr('integration.wizard.evidence')}</th><th>{tr('integration.wizard.decision')}</th></tr></thead>
                <tbody>{rows.map((row) => <tr key={row.fingerprint} className={row.blocking_reason ? 'is-blocked' : ''}>
                    <td data-label={tr('integration.wizard.select')}><input type="checkbox" aria-label={tr('integration.wizard.selectRecord')} disabled={!runUuid || !editableState || !gate.allowed} checked={bulkSelection.includes(row.fingerprint)} onChange={() => toggleBulk(row.fingerprint)} /></td>
                    <td data-label={tr('integration.wizard.entity')}><strong>{tr(`integration.wizard.entity.${row.entity_type}`, {}, row.entity_type)}</strong><br /><span className="muted">{tr(`integration.wizard.classification.${row.classification}`, {}, row.classification)}</span></td>
                    <td data-label={tr('integration.wizard.solastock')}>{row.solastock ? <><strong>{row.solastock.name}</strong><br />{row.solastock.sku || row.solastock.code || `#${row.solastock.id}`}<br /><span className="muted">{row.solastock.barcode || '—'}</span></> : '—'}</td>
                    <td data-label={tr('integration.wizard.solabooks')}>{row.solabooks ? <><strong>{row.solabooks.name}</strong><br />{row.solabooks.sku || row.solabooks.code || `#${row.solabooks.id}`}<br /><span className="muted">{row.solabooks.barcode || '—'}</span></> : '—'}</td>
                    <td data-label={tr('integration.wizard.quantity')}>{row.solastock?.quantity ?? '—'} / {row.solabooks?.quantity ?? '—'}<br /><strong>{row.quantity_difference}</strong></td>
                    <td data-label={tr('integration.wizard.value')}>{row.solastock?.inventory_value ?? '—'} / {row.solabooks?.inventory_value ?? '—'}<br /><strong>{row.value_difference}</strong></td>
                    <td data-label={tr('integration.wizard.evidence')}>{tr(`integration.wizard.confidence.${row.mapping_confidence}`, {}, row.mapping_confidence)}<br /><span className="muted">{row.blocking_reason ? tr(`integration.wizard.block.${row.blocking_reason}`, {}, row.blocking_reason) : tr('integration.wizard.noBlock')}</span><br /><small>{tr(`integration.wizard.reviewer.${row.decision_class === 'accountant_decision' ? 'accountant' : 'owner'}`)}</small></td>
                    <td data-label={tr('integration.wizard.decision')}>{row.blocking_reason ? <label className="field"><span className="sr-only">{tr('integration.wizard.decision')}</span><select className="input" disabled={!runUuid || !editableState || !canEdit(row) || saving} value={decisions.get(row.fingerprint)?.action || ''} onChange={(event) => event.target.value && decide(row, event.target.value)}><option value="">{tr(runUuid ? 'integration.wizard.choose' : 'integration.wizard.pendingDecision')}</option>{allowedActions(row).map((action) => <option key={action} value={action}>{tr(`integration.wizard.action.${action}`, {}, action)}</option>)}</select>{decisions.has(row.fingerprint) && <small>{tr('integration.wizard.version', { version: decisions.get(row.fingerprint).decision_version })}</small>}</label> : tr('integration.wizard.noDecision')}</td>
                </tr>)}</tbody>
            </table>
        </div>

        <div className="panel">
            <h3>{tr('integration.wizard.accounting')}</h3>
            <p>{tr('integration.wizard.inheritance', { policy: accounting.inheritance_policy || '' })}</p>
            <div className="wizard__account-grid">{(accounting.accounts || []).map((account) => <div className={`widget-card ${account.valid ? '' : 'is-blocked'}`} key={account.role}><div className="widget-card-label">{tr(`integration.mapping.${account.role}`, {}, account.role)}</div><div>{account.account_code || '—'} {account.account_name || ''}</div><small>{account.account_type || '—'} · {account.active ? tr('integration.wizard.active') : tr('integration.wizard.inactive')} · {account.postable ? tr('integration.wizard.postable') : tr('integration.wizard.nonPostable')}</small><p className="muted">{tr(`integration.wizard.candidateReason.${account.candidate_reason}`, {}, account.candidate_reason || account.blocking_reason)}</p><small>{(account.affected_workflows || []).join(', ')}</small></div>)}</div>
            {totals.proposed_accounting_effect?.amount && <div className="panel" role="alert"><strong>{tr('integration.wizard.separateApproval')}</strong><p>{totals.proposed_accounting_effect.debit} / {totals.proposed_accounting_effect.credit}: {totals.proposed_accounting_effect.amount} {accounting.base_currency}</p></div>}
        </div>

        {view.master_data && <div className="panel">
            <h3>{tr('integration.wizard.masterReadiness')}</h3>
            <div className="widget-grid">{['units', 'categories', 'warehouses', 'inventory_items'].map((key) => <div className="widget-card" key={key}><div className="widget-card-label">{tr(`integration.wizard.readiness.${key}`, {}, key)}</div><div className="widget-card-value">{view.master_data[key] ?? 0}</div></div>)}</div>
            <p className="muted">{view.master_data.complete ? tr('integration.wizard.masterComplete') : tr('integration.wizard.masterBlocked')}</p>
        </div>}

        {runUuid && <div className="panel">
            <h3>{tr('integration.wizard.nextStage')}</h3>
            {run.data?.state === 'decisions_complete' && <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => runAction(() => api.requestIntegrationWizardSnapshot(runUuid, { expected_lock_version: run.data.lock_version }), 'integration.wizard.snapshotRequested')}>{tr('integration.wizard.requestSnapshot')}</button>}
            {run.data?.state === 'snapshot_required' && <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => runAction(() => api.freezeIntegrationWizardSnapshot(runUuid, { expected_lock_version: run.data.lock_version }), 'integration.wizard.snapshotFrozen')}>{tr('integration.wizard.freezeSnapshot')}</button>}
            {run.data?.state === 'cutoff_review' && <><label className="field"><span className="field-label">{tr('integration.wizard.cutoff')}</span><input className="input" type="datetime-local" value={cutoffAt} onChange={(event) => setCutoffAt(event.target.value)} /></label><button className="btn btn--primary" disabled={!gate.allowed || saving || !cutoffAt} onClick={() => runAction(() => api.reviewIntegrationWizardCutoff(runUuid, { cutoff_at: cutoffAt, physical_counts: [], unexplained_variance: run.data?.blocking?.length ? '1.00' : '0.00', expected_lock_version: run.data.lock_version }), 'integration.wizard.cutoffReviewed')}>{tr('integration.wizard.reviewCutoff')}</button></>}
            {['preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'].includes(run.data?.state) && <><p>{tr('integration.wizard.readOnlyAfter')}</p><p className="wizard__hash">{run.data.approval_payload_hash}</p><label className="field"><span className="field-label">{tr('integration.wizard.confirmation')}</span><input className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="off" /></label><div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving || Boolean(run.data.owner_approved_at)} onClick={() => runAction(() => api.approveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash, confirmation }), 'integration.wizard.ownerApproved')}>{tr('integration.wizard.ownerApprove')}</button><button className="btn btn--primary" disabled={!accountingGate.allowed || saving || Boolean(run.data.accountant_approved_at)} onClick={() => runAction(() => api.accountantApproveIntegrationWizard(runUuid, { approval_payload_hash: run.data.approval_payload_hash }), 'integration.wizard.accountantApproved')}>{tr('integration.wizard.accountantApprove')}</button></div></>}
            {run.data?.state === 'activation_ready' && <p role="status"><strong>{tr('integration.wizard.activationHeld')}</strong></p>}
            {!['decisions_complete', 'snapshot_required', 'cutoff_review', 'preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'].includes(run.data?.state) && <p role="status">{tr('integration.wizard.reviewRemaining', { count: run.data?.blocking?.length ?? 0 })}</p>}
        </div>}
    </div>;
}

function TaxMappings({ gate, toast, qc, tr }) {
    const { data, isLoading, isError, error } = useApiQuery(['integration-taxes'], api.integrationTaxMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);
    function set(i, key, value) { setRows((current) => current.map((row, index) => index === i ? { ...row, [key]: value } : row)); }
    async function save() {
        setSaving(true);
        try {
            await api.saveIntegrationTaxMappings(rows);
            toast.push(tr('integration.tax.saved'), 'success');
            qc.invalidateQueries({ queryKey: ['integration-taxes'] });
            qc.invalidateQueries({ queryKey: ['integration-status'] });
        } catch (error) { toast.push(error.message || tr('settings.common.errorFallback'), 'error'); } finally { setSaving(false); }
    }
    if (isLoading) return <Skeleton />;
    if (isError) return <EmptyState title={tr('integration.loadFailed')} hint={error?.message || tr('settings.common.errorFallback')} />;
    return <div className="panel">
        <p className="muted">{tr('integration.tax.description')}</p>
        <table className="data-table"><thead><tr><th>{tr('integration.tax.inventoryTax')}</th><th>{tr('integration.tax.treatment')}</th><th>{tr('integration.tax.financeTaxId')}</th><th>{tr('integration.tax.financeCode')}</th><th>{tr('integration.tax.inputVat')}</th><th>{tr('integration.tax.outputVat')}</th><th>{tr('integration.tax.status')}</th></tr></thead>
            <tbody>{rows.map((row, i) => <tr key={row.tax_code}>
                <td>{row.tax_code} — {row.tax_name}</td><td>{row.treatment}</td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_id ?? ''} onChange={(e) => set(i, 'solabooks_tax_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.solabooks_tax_code ?? ''} onChange={(e) => set(i, 'solabooks_tax_code', e.target.value)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.input_tax_account_id ?? ''} onChange={(e) => set(i, 'input_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><input className="input" disabled={!gate.allowed} value={row.output_tax_account_id ?? ''} onChange={(e) => set(i, 'output_tax_account_id', e.target.value ? Number(e.target.value) : null)} /></td>
                <td><select className="input" disabled={!gate.allowed} value={row.status} onChange={(e) => set(i, 'status', e.target.value)}><option value="unmapped">{tr('integration.tax.unmapped')}</option><option value="mapped">{tr('integration.tax.mapped')}</option><option value="inactive">{tr('integration.tax.inactive')}</option></select></td>
            </tr>)}</tbody></table>
        <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? tr('integration.connection.saving') : tr('integration.tax.save')}</button></div>
    </div>;
}

function AccountMappings({ gate, toast, qc, tr }) {
    const { data, isLoading, isError, error } = useApiQuery(['integration-accounts'], api.integrationAccountMappings, { fallback: null });
    const [rows, setRows] = useState([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (data?.mappings) setRows(data.mappings); }, [data]);

    function set(i, k, v) { setRows((r) => r.map((row, idx) => idx === i ? { ...row, [k]: v } : row)); }
    async function save() {
        setSaving(true);
        try { await api.saveIntegrationAccountMappings(rows); toast.push(tr('integration.account.saved'), 'success'); qc.invalidateQueries({ queryKey: ['integration-accounts'] }); qc.invalidateQueries({ queryKey: ['integration-status'] }); }
        catch (e) { toast.push(e.message || tr('settings.common.errorFallback'), 'error'); } finally { setSaving(false); }
    }

    if (isLoading) return <Skeleton />;
    if (isError) return <EmptyState title={tr('integration.loadFailed')} hint={error?.message || tr('settings.common.errorFallback')} />;
    return (
        <div className="panel">
            <p className="muted">{tr('integration.account.description')}</p>
            <table className="data-table">
                <thead><tr><th>{tr('integration.account.mapping')}</th><th>{tr('integration.account.accountId')}</th><th>{tr('integration.account.code')}</th><th>{tr('integration.account.name')}</th><th>{tr('integration.account.status')}</th></tr></thead>
                <tbody>{rows.map((m, i) => (
                    <tr key={m.mapping_type}>
                        <td>{tr(`integration.mapping.${m.mapping_type}`, {}, m.mapping_type)}</td>
                        <td><input className="input" disabled={!gate.allowed} value={m.solabooks_account_id ?? ''} onChange={(e) => set(i, 'solabooks_account_id', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_code ?? ''} onChange={(e) => set(i, 'account_code', e.target.value)} /></td>
                        <td><input className="input" disabled={!gate.allowed} value={m.account_name ?? ''} onChange={(e) => set(i, 'account_name', e.target.value)} /></td>
                        <td>{tr(`integration.status.${m.status}`, {}, m.status)}</td>
                    </tr>
                ))}</tbody>
            </table>
            <div className="doc-actions"><button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? tr('integration.connection.saving') : tr('integration.account.save')}</button></div>
        </div>
    );
}

function ItemMappings({ tr }) {
    const { data, isLoading, isError, error } = useApiQuery(['integration-items'], () => api.integrationItemMappings({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <Skeleton />;
    if (isError) return <EmptyState title={tr('integration.loadFailed')} hint={error?.message || tr('settings.common.errorFallback')} />;
    return (
        <div className="panel">
            <p className="muted">{tr('integration.item.description')}</p>
            {rows.length === 0 ? <EmptyState title={tr('integration.item.noItems')} /> : (
                <table className="data-table">
                    <thead><tr><th>{tr('integration.item.sku')}</th><th>{tr('integration.item.item')}</th><th>{tr('integration.item.solabooksItem')}</th><th>{tr('integration.item.syncStatus')}</th><th></th></tr></thead>
                    <tbody>{rows.map((r) => (
                        <tr key={r.id}><td>{r.sku}</td><td>{r.name}</td><td>{r.solabooks_item_id ?? '—'}</td><td>{r.sync_status ? tr(`integration.status.${r.sync_status}`, {}, r.sync_status) : tr('integration.item.notSynced')}</td>
                            <td><Link className="btn btn--sm" to={`/items/${r.id}`}>{tr('integration.item.open')}</Link></td></tr>
                    ))}</tbody>
                </table>
            )}
        </div>
    );
}
