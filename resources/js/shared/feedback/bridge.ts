import { feedback } from './store';
import { text } from './messages';
const handledError = (message: string) => Object.assign(new Error(message), { feedbackHandled: true });
const pending = new Set<string>(), uncertain = new Set<string>();
/** Explicit API for maintained Blade/Alpine callbacks. Never replaces browser globals. */
export const legacyFeedback = {
    ...feedback, text,
    unconfirmed(url: string, method = 'POST') {
        uncertain.add(`${method.toUpperCase()}:${new URL(url, location.href).href}`);
        return feedback.failure(undefined, true);
    },
    report(error: unknown, mutation = false) {
        if (!(error as { feedbackHandled?: boolean })?.feedbackHandled) void feedback.failure(undefined, mutation);
    },
    warning(message: string) { return feedback.error({ title: text('warning'), message, tone: 'warning' }); },
    field(field: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null, message: string) {
        if (!field) return;
        field.setAttribute('aria-invalid', 'true');
        let note = field.nextElementSibling as HTMLElement | null;
        if (!note?.hasAttribute('data-feedback-field')) {
            note = document.createElement('small'); note.dataset.feedbackField = ''; note.className = 'sf-validation'; note.setAttribute('role', 'alert');
            note.id = `sf-field-${crypto.randomUUID()}`; field.after(note);
        }
        const describedBy = field.getAttribute('aria-describedby');
        note.textContent = message; field.setAttribute('aria-describedby', [describedBy, note.id].filter(Boolean).join(' ')); field.focus();
        field.addEventListener('input', () => { field.removeAttribute('aria-invalid'); if (describedBy) field.setAttribute('aria-describedby', describedBy); else field.removeAttribute('aria-describedby'); note?.remove(); }, { once: true });
    },
    /** Existing methods/body/idempotency headers pass through unchanged. No automatic retry. */
    async request(url: string, options: RequestInit = {}, confirmedFailure?: (response: Response) => Promise<boolean>) {
        const method = (options.method || 'GET').toUpperCase(), mutation = !['GET', 'HEAD'].includes(method);
        const key = `${method}:${new URL(url, location.href).href}`;
        if (mutation && uncertain.has(key)) { void feedback.failure(undefined, true); throw handledError(text('unknown')); }
        if (mutation && pending.has(key)) throw handledError(text('pending'));
        if (mutation) pending.add(key);
        const timer = mutation ? setTimeout(() => { uncertain.add(key); void feedback.failure(undefined, true); }, 45000) : undefined;
        try {
            const response = await fetch(url, options);
            if (mutation && response.status >= 500 && !(confirmedFailure && await confirmedFailure(response.clone()))) { uncertain.add(key); void feedback.failure(response.status, true); throw handledError(text('unknown')); }
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                void feedback.failure(response.status, mutation); throw handledError(text(response.status === 403 ? 'forbidden' : 'expired'));
            }
            return response;
        } catch (error) {
            if (!mutation && error instanceof DOMException && error.name === 'AbortError') throw error;
            if (error instanceof TypeError || (error instanceof DOMException && ['AbortError','TimeoutError'].includes(error.name))) {
                if (mutation) uncertain.add(key);
                void feedback.failure(undefined, mutation);
                throw handledError(text(mutation ? 'unknown' : 'failed'));
            }
            throw error;
        } finally { clearTimeout(timer); pending.delete(key); }
    },
};
declare global { interface Window { solavelFeedback: typeof legacyFeedback } }
window.solavelFeedback = legacyFeedback;
