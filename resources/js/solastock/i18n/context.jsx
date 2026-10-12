import React, { createContext, useContext, useMemo } from 'react';
import { dictionaries, getLocale, t } from './index.js';

const I18nContext = createContext(null);

export function I18nProvider({ children }) {
    const locale = getLocale();
    const value = useMemo(() => ({ locale, direction: ['ar', 'ur'].includes(locale) ? 'rtl' : 'ltr', t }), [locale]);
    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
    return useContext(I18nContext) ?? { locale: getLocale(), direction: ['ar', 'ur'].includes(getLocale()) ? 'rtl' : 'ltr', t };
}

export { dictionaries };
