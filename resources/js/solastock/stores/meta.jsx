import React, { createContext, useContext } from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';

// Loads the SPA bootstrap payload (/meta): permissions, settings, lookups.
// Falls back to a permissive set in dev so the UI is navigable without a tenant.
const MetaContext = createContext(null);

const FALLBACK_META = {
    permissions: [
        'inventory.view_dashboard', 'inventory.view_items', 'inventory.manage_items',
        'inventory.view_warehouses', 'inventory.manage_warehouses', 'inventory.view_stock',
        'inventory.manage_opening_stock', 'inventory.manage_adjustments',
        'inventory.view_ledger', 'inventory.view_reports', 'inventory.manage_settings',
    ],
    settings: null,
    lookups: { categories: [], brands: [], units: [] },
    primary_color: '#e09921',
};

export function MetaProvider({ children }) {
    // Non-blocking: render the shell immediately using the permissive fallback,
    // then hydrate real permissions/lookups when /meta resolves. Avoids a
    // full-screen loading gate on first paint.
    const { data, isMock } = useApiQuery(['meta'], api.meta, {
        fallback: FALLBACK_META,
        placeholderData: FALLBACK_META,
    });

    return (
        <MetaContext.Provider value={{ ...(data ?? FALLBACK_META), isMock }}>
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
