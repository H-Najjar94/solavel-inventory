import React, { createContext, useContext, useEffect } from 'react';
import {useQueryClient} from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useTenant } from './tenant.jsx';

// Loads the SPA bootstrap payload (/meta): permissions, settings, lookups.
// Falls back to a permissive set in dev so the UI is navigable without a tenant.
const MetaContext = createContext(null);

const FALLBACK_META = {
    permissions: [],
    settings: null,
    lookups: { categories: [], brands: [], units: [] },
    primary_color: '#e09921',
};

export function MetaProvider({ children }) {
    const tenant = useTenant();const qc=useQueryClient();
    const organizationId = tenant.organization_id ?? 'no-organization';
    // Non-blocking: render the shell immediately, but expose no permissions
    // until /meta resolves. Direct routes must fail closed for restricted users.
    const { data, isMock, isPlaceholderData, isError, refetch } = useApiQuery(['meta', organizationId], api.meta, {
        fallback: FALLBACK_META,
        placeholderData: FALLBACK_META,
        enabled: tenant.resolved,
    });

    useEffect(()=>{const refresh=()=>qc.invalidateQueries({queryKey:['meta',organizationId]});window.addEventListener('solastock-access-refresh',refresh);return()=>window.removeEventListener('solastock-access-refresh',refresh);},[qc,organizationId]);
    return (
        <MetaContext.Provider value={{ ...(data ?? FALLBACK_META), isMock, isPlaceholderData, isError, refetch }}>
            {children}
        </MetaContext.Provider>
    );
}

export function useMeta() {
    return useContext(MetaContext) ?? FALLBACK_META;
}

export function useCan() {
    const meta = useMeta();
    const set = new Set(meta.permissions ?? []);
    return (perm) => !perm || set.has(perm);
}
