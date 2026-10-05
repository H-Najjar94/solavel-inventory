import React, { createContext, useContext, useEffect, useState, useRef } from 'react';
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
    const opened = useRef(null);
    const [accessDenied, setAccessDenied] = useState(null);
    useEffect(() => {
        const deny = (event) => {setAccessDenied(event.detail); qc.cancelQueries();};
        window.addEventListener('solastock-access-denied', deny);
        return () => window.removeEventListener('solastock-access-denied', deny);
    }, [qc]);
    const { data, isLoading, isError, error, refetch } = useQuery({
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
    const received = data ?? FALLBACK;
    const receivedReady = ['real', 'demo'].includes(received.data_state);
    if (!accessDenied && !isError && receivedReady && received.can_access) opened.current = received;
    const accessPaused = !!(accessDenied || isError || !receivedReady || !received.can_access);
    // Cache only an already-authorized presentation for this organization.
    // API authorization still rejects every disallowed request. No new access is granted.
    const status = accessPaused && opened.current ? opened.current : received;
    const ds = status.data_state ?? 'sample';
    // Real data only when a tenant is actually ready (live_ready or demo_preview).
    const ready = ds === 'real' || ds === 'demo';

    // Drive the global sample-fallback gate. Until /tenant/status resolves we keep
    // it 'unknown' (no premature sample fallback); once known, ONLY 'sample' mode
    // permits mock fallback — live/setup/no-access/no-org never do.
    useEffect(() => {
        setDataMode(data === undefined && !opened.current ? 'unknown' : ds);
    }, [data, ds]);

    const value = {
        ...status,
        accessPaused,
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
            opened.current = null;
            await qc.invalidateQueries();
            return res?.data ?? null;
        },
        async clear() {
            await api.clearTenant();
            opened.current = null;
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
            opened.current = null;
            // Cancel responses started under the previous organization, discard
            // its inactive pages, then reset/refetch every mounted query against
            // the newly selected session. This prevents both stale overwrites and
            // old category/unit rows remaining visible during an org switch.
            await qc.cancelQueries();
            qc.removeQueries({ type: 'inactive' });
            await qc.resetQueries();
            return res?.data ?? null;
        },
    };

    const ar = document.documentElement.lang.startsWith('ar');
    if (!accessDenied && !resolved && !isError) return <div role="status" style={{padding: 40}}>{ar ? 'جارٍ التحقق من الوصول…' : 'Checking application access…'}</div>;
    if (!opened.current && (accessDenied || isError || !ready || !status.can_access)) return <main dir={ar ? 'rtl' : 'ltr'} style={{padding: 40}}>
        <h1>{ar ? 'الوصول إلى SolaStock' : 'SolaStock access'}</h1>
        <p>{accessDenied || error?.message || status.state_message || (ar ? 'تعذر فتح التطبيق لهذا الحساب والمؤسسة.' : 'This application cannot be opened for this account and organization.')}</p>
        <a href="/portal">{ar ? 'العودة إلى البوابة' : 'Back to portal'}</a>
        {(isError || accessDenied) && <button onClick={() => {setAccessDenied(null); refetch();}}>{ar ? 'إعادة المحاولة' : 'Retry'}</button>}
    </main>;
    return <TenantContext.Provider value={value}>
        {accessPaused && <section role="status" aria-live="polite" style={{padding: '12px 20px'}}>
            <p>{ar ? 'تعذر تأكيد الوصول حالياً. تبقى الصفحة والمدخلات غير المحفوظة مفتوحة. أعد التحقق لاستئناف العمل.' : 'Access could not be confirmed. Your page and unsaved entries remain open. Check again to resume working.'}</p>
            <button type="button" onClick={async () => { const result = await refetch(); if (result.data?.can_access && ['real', 'demo'].includes(result.data?.data_state)) setAccessDenied(null); }}>{ar ? 'إعادة التحقق' : 'Check again'}</button>
        </section>}
        <fieldset key="workspace" disabled={accessPaused} style={{display: 'contents', border: 0, margin: 0, padding: 0, minWidth: 0}}>{children}</fieldset>
    </TenantContext.Provider>;
}

export function useTenant() {
    return useContext(TenantContext) ?? { ...FALLBACK, loading: true, resolved: false, hasTenant: false, ready: false, dataState: 'sample', isLive: false, isDemo: false, isSetup: false, isNoOrg: false, isNoAccess: false, selectDemo() {}, clear() {}, provision() {} };
}
