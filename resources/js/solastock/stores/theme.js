// Light/dark theme, persisted. Mirrors the demo's data-theme approach so the
// ported design-system CSS works unchanged. Primary color #e09921.

const KEY = 'solastock_theme';

export function getTheme() {
    const saved = localStorage.getItem(KEY);
    if (saved) return saved;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
}

export function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(KEY, theme);
}

export function toggleTheme() {
    const next = getTheme() === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    return next;
}
