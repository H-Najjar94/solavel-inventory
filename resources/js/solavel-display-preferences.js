// Seed an account default once per user/value; retain later device toggles.
window.solavelApplyDisplayPreferences = function (preferences, userId, storageKey) {
    var preferred = preferences && preferences.theme;
    var valid = preferred === 'dark' || preferred === 'light';
    var theme = valid ? preferred : null;
    try {
        var markerKey = storageKey + ':account-default';
        var marker = JSON.stringify([userId, preferred || null]);
        if (valid && localStorage.getItem(markerKey) !== marker) {
            localStorage.setItem(storageKey, preferred);
            localStorage.setItem(markerKey, marker);
        }
        var saved = localStorage.getItem(storageKey);
        if (saved === 'light' || saved === 'dark') theme = saved;
    } catch (_) {}
    return theme;
};
