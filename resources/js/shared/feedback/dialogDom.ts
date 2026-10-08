/** Hidden workflow shells must never receive otherwise invisible feedback. */
export function visibleWorkflow(node: HTMLElement) {
    return node.isConnected && node.getClientRects().length > 0 && !node.closest('[hidden],[aria-hidden="true"]') && getComputedStyle(node).visibility !== 'hidden' && (!(node instanceof HTMLDialogElement) || node.open);
}
export function activeWorkflow() {
    return Array.from(document.querySelectorAll<HTMLElement>('dialog[open],[aria-modal="true"]')).find(node => !node.closest('.sf-root') && visibleWorkflow(node)) || null;
}
