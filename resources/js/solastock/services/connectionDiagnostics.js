/** Read-only presentation: never changes readiness or permissions. */
export function diagnosticReason(status) {
    if (status?.readiness?.state === 'CONNECTED_READY') return 'ready';
    const reasons = [...(status?.readiness?.blockers || []), status?.summary?.reason];
    for (const reason of reasons) {
        if (reason === 'sync_worker_unavailable') return 'worker';
        if (reason === 'sync_errors') return 'delivery';
        if (reason === 'finance_setup_incomplete' || reason === 'connection_setup_incomplete') return 'setup';
        if (reason === 'access_required') return 'access';
    }
    return 'review';
}
