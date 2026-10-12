// Account display preferences (language + theme), saved to the user's Solavel
// account through this app's own server (which calls Central). Best effort only:
// failures never block the UI and are never shown to the user.

const REMEMBER_KEY = 'solastock_remember_language';

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
    try { return localStorage.getItem(REMEMBER_KEY) === '1'; } catch (_) { return false; }
}

export function setRememberLanguage(on) {
    try { localStorage.setItem(REMEMBER_KEY, on ? '1' : '0'); } catch (_) { /* ignore */ }
}
