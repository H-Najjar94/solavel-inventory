export function unwrapCanonicalDraft(response) {
    const draft = response?.data ?? response;
    if (!draft || typeof draft !== 'object' || !draft.run_uuid || !Number.isInteger(Number(draft.lock_version))) {
        throw new Error('wizard_invalid_canonical_response');
    }
    return draft;
}

function decisionSignatures(draft) {
    return new Map((draft?.decisions || []).map((decision) => [
        decision.candidate_fingerprint,
        `${decision.action}:${Number(decision.decision_version || 0)}`,
    ]));
}

/**
 * A GET is allowed to replace local canonical state only when it belongs to
 * the same immutable draft/organization and cannot remove data at the same or
 * a newer lock version. This makes status/focus polling harmless after a save.
 */
export function mayAcceptCanonicalDraft(current, incoming, expectedRunUuid) {
    if (!incoming || incoming.run_uuid !== expectedRunUuid) return false;
    if (!current) return true;
    if (current.run_uuid !== incoming.run_uuid) return false;
    const currentIdentity = current.identity || {};
    const incomingIdentity = incoming.identity || {};
    for (const key of ['client_id', 'central_organization_id', 'finance_organization_id', 'solastock_organization_id']) {
        if (String(currentIdentity[key] ?? '') !== String(incomingIdentity[key] ?? '')) return false;
    }
    const currentVersion = Number(current.lock_version || 0);
    const incomingVersion = Number(incoming.lock_version || 0);
    if (incomingVersion < currentVersion) return false;
    if (incomingVersion > currentVersion) return true;
    const incomingDecisions = decisionSignatures(incoming);
    return [...decisionSignatures(current)].every(([fingerprint, signature]) => incomingDecisions.get(fingerprint) === signature);
}

export function createSerializedMutationQueue() {
    let tail = Promise.resolve();
    let sequence = 0;
    return {
        enqueue(task) {
            const mutationSequence = ++sequence;
            const result = tail.catch(() => undefined).then(() => task(mutationSequence));
            tail = result;
            return result;
        },
        latestSequence() { return sequence; },
    };
}

export function canonicalDecision(draft, fingerprint) {
    return (draft?.decisions || []).find((decision) => decision.candidate_fingerprint === fingerprint) || null;
}
