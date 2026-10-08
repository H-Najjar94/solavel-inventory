// Physical coordinates deliberately avoid RTL logical-edge anchoring outside the viewport.
export function notificationPanelPosition(anchor, viewport, rtl = false) {
 const gutter = 14;
 const width = Math.min(340, Math.max(0, viewport.width - gutter * 2));
 const preferred = rtl ? anchor.left : anchor.right - width;
 const left = Math.max(gutter, Math.min(preferred, viewport.width - width - gutter));
 const top = Math.min(anchor.bottom + 8, Math.max(gutter, viewport.height - gutter - 40));
 return {left, top, width, maxHeight: Math.max(0, Math.min(viewport.height * 0.7, viewport.height - top - gutter))};
}
