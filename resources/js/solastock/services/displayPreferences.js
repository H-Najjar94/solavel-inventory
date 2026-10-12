// Account display preferences (language + theme), saved to the user's Solavel
// account through this app's own server (which calls Central). Best effort only:
// failures never block the UI and are never shown to the user.

const REMEMBER_KEY = 'solavel_save_default_language';

function basePath() {
    return window.SOLASTOCK_BASE_PATH ?? '/inventory';
}

/** Fire-and-forget: save the theme the user just chose as their account default. */
export function saveThemePreference(theme) {
    if (theme !== 'light' && theme !== 'dark') return;
    try {
        fetch(`${basePath()}/preferences/theme`, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
            body: JSON.stringify({ theme }),
        }).catch(() => {});
    } catch (_) { /* never surface */ }
}

/** "Save as my default language" switch state, remembered on this device. */
export function getRememberLanguage() {
    try {
        if (localStorage.getItem(REMEMBER_KEY) === null) {
            const enabled = ['hr.language.remember', 'solastock_remember_language'].some(key => localStorage.getItem(key) === '1');
            localStorage.setItem(REMEMBER_KEY, enabled ? '1' : '0');
        }
        return localStorage.getItem(REMEMBER_KEY) === '1';
    } catch (_) { return false; }
}

export function setRememberLanguage(on) {
    try { localStorage.setItem(REMEMBER_KEY, on ? '1' : '0'); } catch (_) { /* ignore */ }
}

const SYNC_INTERVAL_MS = 60000;

// A ?locale= left in the URL by the language picker would override the account
// language again on reload, so drop it (and ?remember=) first.
function reloadWithoutLocaleParam() {
    const url = new URL(window.location.href);
    if (url.searchParams.has('locale') || url.searchParams.has('remember')) {
        url.searchParams.delete('locale');
        url.searchParams.delete('remember');
        window.location.replace(url.toString());
    } else {
        window.location.reload();
    }
}

/**
 * Follow the Solavel ACCOUNT language/theme while this page is open: every 60s
 * while visible and on window focus, ask this app's server (which asks Central,
 * throttled) whether the account preferences changed. A different language
 * reloads the page (the server already switched the session); a theme-only
 * change is applied in place through onTheme. Never throws, never toasts.
 * Returns a cleanup function.
 */
export function startAccountPreferenceSync({ currentLocale, onTheme }) {
    let inFlight = false;
    let stopped = false;

    const check = () => {
        if (stopped || inFlight || document.visibilityState === 'hidden') return;
        inFlight = true;
        try {
            fetch(`${basePath()}/preferences/sync${getRememberLanguage() ? '?follow_locale=1' : ''}`, {
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => {
                    if (stopped || !data || typeof data !== 'object') return;
                    const locale = currentLocale();
                    if (data.locale && locale && data.locale !== locale) {
                        reloadWithoutLocaleParam();
                        return;
                    }
                    if (data.changed && (data.theme === 'light' || data.theme === 'dark')) onTheme(data.theme);
                })
                .catch(() => {})
                .finally(() => { inFlight = false; });
        } catch (_) { inFlight = false; }
    };

    const timer = window.setInterval(check, SYNC_INTERVAL_MS);
    window.addEventListener('focus', check);
    document.addEventListener('visibilitychange', check);
    window.addEventListener('storage', check);
    check();
    return () => {
        stopped = true;
        window.clearInterval(timer);
        window.removeEventListener('focus', check);
        document.removeEventListener('visibilitychange', check);
        window.removeEventListener('storage', check);
    };
}
