import { activeWorkflow, visibleWorkflow } from './dialogDom';
import { presentFlash } from './flashDom';
import React, { useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { feedback, dismiss, finish, snapshot, subscribe, type Dialog, type Notice } from './store';
import { text } from './messages';
import './feedback.css';
function FeedbackDialog({ item, locale }: { item: Dialog; locale: string }) {
    const ref = useRef<HTMLDialogElement>(null);
    const [inputValue, setInputValue] = useState(item.input?.choices?.[0]?.value || '');
    const inline = useRef<HTMLElement>(null);
    const [container, setContainer] = useState<HTMLElement | null>(activeWorkflow);
    useEffect(() => {
        if (container) {
            inline.current?.querySelector<HTMLButtonElement>('button')?.focus();
            const observer = new MutationObserver(() => {
                if (!visibleWorkflow(container)) setContainer(null);
            });
            observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open','hidden','aria-hidden','style','class'] });
            return () => observer.disconnect();
        }
        const node = ref.current;
        node?.showModal();
        return () => node?.close();
    }, [item.id, container]);
    useEffect(() => () => { if (item.trigger?.isConnected) item.trigger.focus(); }, [item.id]);
    const trap = (event: React.KeyboardEvent<HTMLElement>) => {
        if (event.key === 'Escape' && container) { event.preventDefault(); event.stopPropagation(); finish(item.id, false); return; }
        if (event.key !== 'Tab') return;
        const buttons = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),select:not(:disabled)'));
        const first = buttons[0], last = buttons[buttons.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    const content = <>
        <div className="sf-symbol" aria-hidden="true">{item.tone === 'error' ? '!' : '?'}</div>
        <h2 id={`sf-title-${item.id}`}>{item.title}</h2>
        <p id={`sf-body-${item.id}`}>{item.message}</p>
        {item.reference && <bdi className="sf-reference">{item.reference}</bdi>}
        {item.input && <label className="sf-input">{item.input.label}{item.input.choices ? <select value={inputValue} onChange={event=>{setInputValue(event.target.value);item.input?.onValue(event.target.value);}}>{item.input.choices.map(choice=><option key={choice.value} value={choice.value}>{choice.label}</option>)}</select> : <textarea rows={3} value={inputValue} minLength={item.input.minLength} onChange={event => { setInputValue(event.target.value); item.input?.onValue(event.target.value); }} />}</label>}
        <div className="sf-actions">
            <button type="button" autoFocus onClick={() => finish(item.id, false)}>{text(item.action ? 'cancel' : 'acknowledge', locale)}</button>
            {item.action && <button type="button" className="sf-primary" disabled={!!item.input && inputValue.trim().length < item.input.minLength} onClick={() => finish(item.id, true)}>{item.action}</button>}
            {item.checkStatus && <button type="button" className="sf-primary" onClick={() => { finish(item.id, false); window.location.reload(); }}>{text('reload', locale)}</button>}
        </div>
    </>;
    // If a workflow already owns a modal, present feedback within that modal.
    // No second modal and no feedback waiting invisibly behind the first one.
    if (container) return createPortal(<section ref={inline} className={`sf-inline sf-${item.tone || 'warning'}`} dir={locale.startsWith('ar') ? 'rtl' : 'ltr'} role="alert" aria-labelledby={`sf-title-${item.id}`} onKeyDown={trap}>{content}</section>, container);
    return <dialog onKeyDown={trap} ref={ref} className={`sf-dialog sf-${item.tone || 'warning'}`} dir={locale.startsWith('ar') ? 'rtl' : 'ltr'} role="alertdialog" aria-labelledby={`sf-title-${item.id}`} aria-describedby={`sf-body-${item.id}`} onCancel={event => { event.preventDefault(); finish(item.id, false); }}>{content}</dialog>;
}
function Toast({ item, locale }: { item: Notice; locale: string }) {
    const remaining = useRef(6000), start = useRef(0), timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const pause = () => { clearTimeout(timer.current); if (start.current) remaining.current -= Date.now() - start.current; start.current = 0; };
    const resume = () => { if (start.current || item.tone === 'error' || item.tone === 'warning') return; start.current = Date.now(); timer.current = setTimeout(() => dismiss(item.id), Math.max(remaining.current, 1000)); };
    useEffect(() => { resume(); return () => clearTimeout(timer.current); }, []);
    return <div className={`sf-toast sf-${item.tone}`} onMouseEnter={pause} onMouseLeave={resume} onFocus={pause} onBlur={resume}>
        <span className="sf-symbol" aria-hidden="true">{item.tone === 'success' ? '✓' : item.tone === 'info' ? 'i' : '!'}</span>
        <div><strong>{item.title || text(item.tone, locale)}</strong><p>{item.message}</p></div>
        <button type="button" aria-label={text('close', locale)} onClick={() => dismiss(item.id)}>×</button>
    </div>;
}
export function FeedbackProvider({ children, locale: selectedLocale }: { children?: ReactNode; locale?: string }) {
    const [documentLocale, setDocumentLocale] = useState(document.documentElement.lang || 'en');
    useEffect(() => {
        const observer = new MutationObserver(() => setDocumentLocale(document.documentElement.lang || 'en'));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        feedback.restoreAfterReload();
        presentFlash();
        const observer = new MutationObserver(() => presentFlash());
        observer.observe(document.body, {childList: true, subtree: true});
        return () => observer.disconnect();
    }, []);
    const locale = selectedLocale || documentLocale;
    const state = useSyncExternalStore(subscribe, snapshot, snapshot);
    return <>{children}{createPortal(<div className="sf-root" dir={locale.startsWith('ar') ? 'rtl' : 'ltr'}>
        <div className="sf-notices" aria-live="polite" aria-relevant="additions text">{state.notices.map(item => <Toast key={item.id} item={item} locale={locale} />)}</div>
        {state.dialogs[0] && <FeedbackDialog key={state.dialogs[0].id} item={state.dialogs[0]} locale={locale} />}
        <span className="sf-sr" role="status">{state.pending.length ? text('pending', locale) : ''}</span>
    </div>, document.body)}</>;
}
