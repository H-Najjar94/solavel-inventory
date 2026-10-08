import { text } from './messages';
export type Tone = 'success' | 'info' | 'warning' | 'error';
export type DialogOptions = { title: string; message: string; action?: string; reference?: string; tone?: Tone; checkStatus?: boolean; statusHref?: string; input?: { label: string; minLength: number; choices?: {value:string;label:string}[]; onValue: (value: string) => void } };
export type Dialog = DialogOptions & { id: number; resolve: (confirmed: boolean) => void; trigger: HTMLElement | null };
export type Notice = { id: number; message: string; tone: Tone; title?: string };
let sequence = 0;
let state: { dialogs: Dialog[]; notices: Notice[]; pending: string[] } = { dialogs: [], notices: [], pending: [] };
const listeners = new Set<() => void>();
const locks = new Map<string, Promise<unknown>>();
const publish = () => listeners.forEach(listener => listener());
export const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
export const snapshot = () => state;
export function dismiss(id: number) { state = { ...state, notices: state.notices.filter(item => item.id !== id) }; publish(); }
export function finish(id: number, confirmed: boolean) {
    const dialog = state.dialogs.find(item => item.id === id);
    state = { ...state, dialogs: state.dialogs.filter(item => item.id !== id) }; publish();
    dialog?.resolve(confirmed);
}
function dialog(options: DialogOptions) {
    // Repeated activation of the same control must not queue another decision.
    if (state.dialogs.some(item => JSON.stringify({ title: item.title, message: item.message, action: item.action, reference: item.reference, tone: item.tone, checkStatus: item.checkStatus, statusHref:item.statusHref }) === JSON.stringify({ title: options.title, message: options.message, action: options.action, reference: options.reference, tone: options.tone, checkStatus: options.checkStatus, statusHref:options.statusHref }))) return Promise.resolve(false);
    return new Promise<boolean>(resolve => {
        state = { ...state, dialogs: [...state.dialogs, { ...options, id: ++sequence, resolve, trigger: document.activeElement as HTMLElement }] };
        publish();
    });
}
export const feedback = {
    async input(options: DialogOptions & { action: string; label: string; minLength: number }): Promise<string | null> {
        let value = '';
        const accepted = await dialog({ ...options, input: { label: options.label, minLength: options.minLength, onValue: next => { value = next; } } });
        return accepted ? value : null;
    },
    async choose(options: DialogOptions & { action: string; label: string; choices: {value:string;label:string}[] }): Promise<string | null> {
        if (!options.choices.length) return null;
        let value = options.choices[0].value;
        const accepted = await dialog({...options, input:{label:options.label,minLength:0,choices:options.choices,onValue:next=>{value=next;}}});
        return accepted ? value : null;
    },
    confirm: (options: DialogOptions & { action: string }) => dialog(options),
    error: (options: DialogOptions) => dialog({ tone: 'error', ...options }),
    notify(message: string, tone: Tone = 'success', title?: string) {
        if (!message || state.notices.some(item => item.message === message && item.tone === tone)) return;
        state = { ...state, notices: [...state.notices, { id: ++sequence, message, tone, title }] }; publish();
    },
    notifyAfterReload(message: string, options: {path?: string; tone?: Tone} = {}) {
        try { sessionStorage.setItem('solavel.feedback.reload', JSON.stringify({ message, path: options.path ? new URL(options.path, location.href).pathname : location.pathname, tone: options.tone || 'success', time: Date.now() })); }
        catch { feedback.notify(message, options.tone); }
    },
    restoreAfterReload() {
        try {
            const raw = sessionStorage.getItem('solavel.feedback.reload');
            sessionStorage.removeItem('solavel.feedback.reload');
            if (!raw) return;
            const item = JSON.parse(raw);
            if (item.path === location.pathname && Date.now() - item.time < 30000 && typeof item.message === 'string') feedback.notify(item.message, ['success','info','warning','error'].includes(item.tone) ? item.tone : 'success');
        } catch { /* Storage can be unavailable; the successful operation is unaffected. */ }
    },
    clearNotices() { state = { ...state, notices: [] }; publish(); },
    navigate(options: {preserveNotices?: boolean} = {}) {
        const obsolete = state.dialogs;
        state = { ...state, dialogs: [], notices: options.preserveNotices ? state.notices : [] }; publish();
        obsolete.forEach(item => item.resolve(false));
    },
    /** Same action shares a promise; unrelated requests run independently. */
    run<T>(key: string, operation: () => Promise<T>): Promise<T> {
        if (locks.has(key)) return locks.get(key) as Promise<T>;
        const result = Promise.resolve().then(operation).finally(() => {
            locks.delete(key); state = { ...state, pending: state.pending.filter(item => item !== key) }; publish();
        });
        locks.set(key, result); state = { ...state, pending: [...state.pending, key] }; publish();
        return result;
    },
    failure(status?: number, mutation = true, statusHref?: string) {
        // Reads cannot change saved data: keep the current page and focus available.
        if (!mutation) {
            feedback.notify(text(status === 403 ? 'forbidden' : status === 401 || status === 419 ? 'expired' : status === 409 ? 'updateAvailable' : 'failed'), 'warning');
            return Promise.resolve(false);
        }
        const unknown = mutation && (status === undefined || status >= 500);
        return dialog({ title: text(unknown ? 'unknownTitle' : 'error'), message: text(status === 403 ? 'forbidden' : status === 419 || status === 401 ? 'expired' : unknown ? 'unknown' : 'failed'), tone: unknown ? 'warning' : 'error', checkStatus: status !== 403, statusHref });
    },
};
export function focusInvalid(errors: Record<string, unknown>) {
    const fields = Object.keys(errors);
    requestAnimationFrame(() => {
        const target = fields.map(name => document.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name.replace(/\.(\w+)/g, '[$1]'))}"]`)).find(node => node && node.getClientRects().length)
            ?? document.querySelector<HTMLElement>('[aria-invalid="true"], [data-feedback-validation]');
        target?.setAttribute('aria-invalid', 'true');
        target?.focus();
    });
}
