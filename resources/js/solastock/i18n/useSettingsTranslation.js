import { useI18n } from './context.jsx';
import { en, ar } from './settingsPages.js';

function interpolate(value, params) {
    return String(value).replace(/:([A-Za-z0-9_]+)/g, (_, name) => params[name] ?? `:${name}`);
}

export function useSettingsTranslation() {
    const { locale } = useI18n();

    return (key, params = {}, fallback = key) => interpolate(
        (locale === 'ar' ? ar : en)[key] ?? en[key] ?? fallback,
        params,
    );
}
