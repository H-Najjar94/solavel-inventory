import { feedback, type Tone } from './store';
import { text, serverMessage } from './messages';
const seen = new WeakSet<HTMLElement>();
/** Explicit server-rendered flash markers also cover sanitized Blade-in-React pages. */
export function presentFlash(root: ParentNode = document) {
    root.querySelectorAll<HTMLElement>('[data-feedback-flash]').forEach(node => {
        if (seen.has(node)) return;
        seen.add(node);
        const raw = node.dataset.feedbackKey || node.textContent?.trim() || '';
        const tone = raw === 'email-change-conflict' ? 'error' : node.dataset.feedbackFlash as Tone;
        const message = serverMessage(raw);
        if (!message || message === 'verification-link-sent') return;
        if (tone === 'error' || tone === 'warning') void feedback.error({ title: text(tone), message, tone });
        else feedback.notify(message, tone);
        const container = node.closest<HTMLElement>('.alert,[class*="-flash"],.v2-alert,.p-alert') || node;
        container.hidden = true; container.style.display = 'none';
    });
}
