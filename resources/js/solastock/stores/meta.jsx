import React, { createContext, useContext } from 'react';
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
    const tenant = useTenant();
    const organizationId = tenant.organization_id ?? 'no-organization';
    // Non-blocking: render the shell immediately, but expose no permissions
    // until /meta resolves. Direct routes must fail closed for restricted users.
    const { data, isMock, isPlaceholderData } = useApiQuery(['meta', organizationId], api.meta, {
        fallback: FALLBACK_META,
        placeholderData: FALLBACK_META,
        enabled: tenant.resolved,
    });

    return (
        <MetaContext.Provider value={{ ...(data ?? FALLBACK_META), isMock, isPlaceholderData }}>
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
