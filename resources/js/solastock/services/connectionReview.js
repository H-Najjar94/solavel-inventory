import { mayAcceptCanonicalDraft, unwrapCanonicalDraft } from './wizardDraftSync.js';

export function cutoffInputValue(value) {
    return String(value || '').replace(' ', 'T').slice(0, 16);
}
export function accountSelectionDirty(proposal, saved) {
    return Boolean(proposal && String(proposal.id) !== String(saved?.id));
}
/** Prepare only review metadata; approvals and activation remain explicit actions. */
export async function prepareConnectionReview(api, initial, cutoffAt, errorMessage) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(cutoffAt)) throw new Error(errorMessage);
    let draft = initial;
    const accept = response => {
        let next;
        try { next = unwrapCanonicalDraft(response); } catch { throw new Error(errorMessage); }
        if (!mayAcceptCanonicalDraft(draft, next, initial.run_uuid)) throw new Error(errorMessage);
        draft = next;
    };
    if (draft.state === 'decisions_complete') accept(await api.requestIntegrationWizardSnapshot(draft.run_uuid, { expected_lock_version: draft.lock_version }));
    if (draft.state === 'snapshot_required') accept(await api.freezeIntegrationWizardSnapshot(draft.run_uuid, { expected_lock_version: draft.lock_version }));
    const reviewStates = ['cutoff_review', 'preview_ready', 'owner_approved', 'accountant_approved', 'activation_ready'];
    if (!reviewStates.includes(draft.state)) throw new Error(errorMessage);
    if (draft.state === 'cutoff_review' || cutoffInputValue(draft.cutoff_at) !== cutoffAt) {
        accept(await api.reviewIntegrationWizardCutoff(draft.run_uuid, { cutoff_at: cutoffAt, physical_counts: [], unexplained_variance: '0.00', expected_lock_version: draft.lock_version }));
    }
    return draft;
}
