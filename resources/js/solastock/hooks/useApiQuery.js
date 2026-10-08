import { useQuery } from '@tanstack/react-query';

/**
 * Global data-mode gate, set by the tenant store from /tenant/status. Sample
 * fallback is ONLY permitted in explicit sample-preview mode (no real org, not
 * logged into a live tenant). For live / setup-required / no-access /
 * no-organization, queries NEVER fall back to mock data — the AppShell renders
 * the appropriate real state screen, and live pages show real data or a real
 * empty state.
 *
 *   'real'    → live tenant or operator demo: real data, no fallback
 *   'setup'   → tenant needs provisioning: AppShell shows the setup wizard
 *   'sample'  → explicit sample preview: fallback allowed
 *   'unknown' → status not loaded yet: treat like real (no premature fallback)
 */
let CURRENT_DATA_MODE = 'unknown';

export function setDataMode(mode) {
    CURRENT_DATA_MODE = mode || 'unknown';
}

export function sampleAllowed() {
    return CURRENT_DATA_MODE === 'sample';
}

export function useApiQuery(key, fetcher, { fallback = null, select, ...opts } = {}) {
    const query = useQuery({
        queryKey: key,
        queryFn: async () => {
            const res = await fetcher();
            return select ? select(res) : res?.data ?? res;
        },
        // Don't retry auth/tenant/permission errors — these are decisive states
        // the AppShell handles (setup/no-access/no-org); retrying only stalls.
        retry: (count, err) => {
            if (err?.status && [400, 401, 403, 404, 409, 422].includes(err.status)) return false;
            return count < 1;
        },
        staleTime: 30_000,
        ...opts,
    });

    // Sample fallback is allowed ONLY in explicit sample-preview mode. For a
    // logged-in / live / setup user, an errored query stays an error (the page
    // shows its real empty/error state; the shell shows setup/no-access/no-org).
    if (query.isError && fallback !== null && sampleAllowed()) {
        return { ...query, data: fallback, isMock: true, isError: false };
    }

    return { ...query, isMock: false };
}
