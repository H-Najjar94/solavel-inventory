// The account owns this switch; localStorage is only the current browser's cache.
(function () {
    if (window.solavelLanguageDefaultStarted) return;
    window.solavelLanguageDefaultStarted = true;
    var key = 'solavel_save_default_language', pending = false, revision = 0;
    var base = window.location.pathname.startsWith('/hr/') ? '/hr' : window.location.pathname.startsWith('/inventory/') ? '/inventory' : '';
    var readUrl = window.solavelPreferenceSyncUrl || base + '/preferences/sync';
    var saveUrl = window.solavelThemePreferenceUrl || base + '/preferences/theme';
    function apply(on) {
        try { localStorage.setItem(key, on ? '1' : '0'); } catch (_) {}
        window.dispatchEvent(new StorageEvent('storage', {key: key, newValue: on ? '1' : '0'}));
        window.solavelAccountPreferences?.sync();
    }
    async function save(on, locale) {
        var saveRevision = ++revision;
        pending = true;
        try {
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            var body = {remember_language: on};
            if (on && locale) body.locale = locale;
            var response = await fetch(saveUrl, {method: 'POST', credentials: 'same-origin', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(body)});
            if (!response.ok) return false;
            return (await response.json()).saved === true;
        } catch (_) {return false;} finally {if (revision === saveRevision) pending = false;}
    }
    async function check() {
        if (pending || document.visibilityState === 'hidden') return;
        var version = revision;
        try {
            var url = new URL(readUrl, window.location.origin);url.searchParams.set('language_default','1');
            var response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok || version !== revision || pending) return;
            var data = await response.json();
            if (version !== revision || pending) return;
            if (typeof data.remember_language === 'boolean') apply(data.remember_language);
            if (window.solavelIsCentral && data.remember_language && data.locale && data.locale !== document.documentElement.lang) {
                window.location.reload();
                return;
            }
            // Migrate an existing checked switch without changing the saved language.
            else if (localStorage.getItem(key) === '1') await save(true);
        } catch (_) {}
    }
    document.addEventListener('click', function (event) {
        var control = event.target.closest?.('.central-pref-switch,.hr-pref-switch,.ss-pref-switch,[data-save-language-switch],[data-testid="save-default-language"]');
        if (!control) return;
        revision++;
        pending = true;
        // Read the state after the owning React/Blade handler completes.
        setTimeout(function () {save(localStorage.getItem(key) === '1', document.documentElement.lang);},0);
    });
    window.addEventListener('focus',check);
    document.addEventListener('visibilitychange',check);
    setInterval(check,60000);
    check();
})();
