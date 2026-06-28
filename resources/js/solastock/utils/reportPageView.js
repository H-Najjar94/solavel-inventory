// Client-side SPA navigation reporter.
//
// SolaStock is a React single-page app, so react-router navigations never reach
// the server. This posts one page_view per in-app navigation to the
// api.v1.spa-view endpoint, which forwards it to the central event log
// (source_app=inventory) so every "move" is visible in the admin event log.
//
// Fire-and-forget: never throws, never navigates. keepalive lets the ping
// survive a mid-navigation unload. Consecutive identical paths are de-duped.

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

let lastPath = '';

export function reportPageView(path, name) {
    try {
        const p = String(path || window.location.pathname || '/').slice(0, 300);
        if (p === lastPath) return;
        lastPath = p;

        const body = JSON.stringify({ name: name ? String(name).slice(0, 120) : null, path: p });

        void fetch('/inventory/api/v1/spa-view', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => { /* best-effort; never surface a tracking failure */ });
    } catch {
        // Tracking must never throw into the caller.
    }
}
