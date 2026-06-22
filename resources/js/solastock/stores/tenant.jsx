import React, { createContext, useContext, useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { setDataMode } from '../hooks/useApiQuery.js';

// Tracks the SolaStock tenant state the Solavel way. The LIVE tenant comes from
// the central SSO session and takes precedence over the demo tenant. Drives the
// header badges (Live / Demo / Setup required / Sample preview), the per-page
// data indicator, and whether create/post actions are enabled.
const TenantContext = createContext(null);

const FALLBACK = {
    state: 'sample_preview', mode: 'none', data_state: 'sample',
    badge: 'Sample preview', demo_available: false, needs_setup: false,
    can_provision: false, authenticated: false,
};

export function TenantProvider({ children }) {
    const qc = useQueryClient();
    const { data, isLoading, isError } = useQuery({
        queryKey: ['tenant-status'],
        queryFn: async () => (await api.tenantStatus()).data,
        retry: false,
        staleTime: 30_000,
    });

    // The user's accessible organizations (for the org switcher). Loaded once
    // the tenant status resolves to a real authenticated context.
    const { data: orgData } = useQuery({
        queryKey: ['organizations'],
        queryFn: async () => (await api.listOrganizations()).data,
        retry: false,
        staleTime: 60_000,
        enabled: data !== undefined && data?.authenticated === true,
    });

    // Until /tenant/status resolves we are in an UNKNOWN state — the UI must show
    // a quiet loading screen, NOT the sample/setup fallback (that caused a flash
    // of dashboard cards before snapping to "Setup required").
    const resolved = data !== undefined;
    const status = data ?? FALLBACK;
    const ds = status.data_state ?? 'sample';
    // Real data only when a tenant is actually ready (live_ready or demo_preview).
    const ready = ds === 'real' || ds === 'demo';

    // Drive the global sample-fallback gate. Until /tenant/status resolves we keep
    // it 'unknown' (no premature sample fallback); once known, ONLY 'sample' mode
    // permits mock fallback — live/setup/no-access/no-org never do.
    useEffect(() => {
        setDataMode(data === undefined ? 'unknown' : ds);
    }, [data, ds]);

    const value = {
        ...status,
        // True until the first /tenant/status response — the shell shows a loader.
        loading: ! resolved && isLoading,
        resolved,
        hasTenant: status.mode === 'live' || status.mode === 'demo',
        ready,
        dataState: ds, // real | demo | sample | setup
        isLive: status.state === 'live_ready',
        isDemo: status.state === 'demo_preview',
        isSetup: ds === 'setup',
        isNoOrg: status.state === 'no_organization',
        isNoAccess: status.state === 'no_access',
        async selectDemo() {
            const res = await api.selectDemoTenant();
            await qc.invalidateQueries();
            return res?.data ?? null;
        },
        async clear() {
            await api.clearTenant();
            await qc.invalidateQueries();
        },
        async provision() {
            const res = await api.provisionTenant();
            await qc.invalidateQueries();
            return res?.data ?? null;
        },
        // Org switcher
        organizations: orgData?.organizations ?? [],
        async selectOrg(organizationId) {
            const res = await api.selectOrganization(organizationId);
            // The active org/client changed → refetch EVERYTHING so the whole app
            // reloads in the new org's context (status, data, layouts, …).
            await qc.invalidateQueries();
            return res?.data ?? null;
        },
    };

    return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

export function useTenant() {
    return useContext(TenantContext) ?? { ...FALLBACK, loading: true, resolved: false, hasTenant: false, ready: false, dataState: 'sample', isLive: false, isDemo: false, isSetup: false, isNoOrg: false, isNoAccess: false, selectDemo() {}, clear() {}, provision() {} };
}
