import { sourceText } from '../i18n/locales.js';
import React, { useEffect, useRef, useState } from 'react';
import { api } from '../services/api.js';
import { useI18n } from '../i18n/context.jsx';
import { useToast } from '../stores/toast.jsx';

/** Native return OUT, explicitly separate from correcting/reversing the receipt. */
export default function SupplierReturnFromReceipt({ receiptId, onClose, onPosted }) {
    const { locale } = useI18n(); const ar = locale === 'ar'; const toast = useToast();
    const [options, setOptions] = useState(null); const [quantities, setQuantities] = useState({});
    const [reason, setReason] = useState(''); const [date, setDate] = useState(new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10));
    const [draft, setDraft] = useState(null); const [busy, setBusy] = useState(false); const [arrived, setArrived] = useState(false);
    const uuid = useRef(crypto.randomUUID()); const dialog = useRef(null);
    useEffect(() => { dialog.current?.showModal(); let active = true;
        api.supplierReturnOptions(receiptId).then(r => { if (active) setOptions(r?.data ?? r); }).catch(e => toast.failure(e, e.message));
        return () => { active = false; };
    }, [receiptId]);
    async function submit(e) {
        e.preventDefault(); if (busy || !arrived) return; setBusy(true);
        try {
            let document = draft;
            if (!document) {
                const lines = options.lines.filter(l => Number(quantities[l.source_stock_ledger_id]) > 0).map(l => ({ goods_receipt_line_id: l.goods_receipt_line_id, source_stock_ledger_id: l.source_stock_ledger_id, entered_qty: quantities[l.source_stock_ledger_id] }));
                const response = await api.createSupplierReturn(receiptId, { request_uuid: uuid.current, return_date: date, reason, lines }); document = response?.data ?? response; setDraft(document);
            }
            await api.postSupplierReturn(document.id);
            toast.push(sourceText("Supplier return posted. Its financial document synchronizes separately.", "تم ترحيل مرتجع المورد. تتم مزامنة المستند المالي بشكل منفصل."), 'success'); onPosted(); onClose();
        } catch (error) { toast.failure(error, error.message); } finally { setBusy(false); }
    }
    return <dialog ref={dialog} className="panel" aria-labelledby="supplier-return-title" onCancel={e => { if (busy) e.preventDefault(); else onClose(); }} style={{ maxWidth: 'min(720px, 95vw)', maxHeight: '90vh', overflow: 'auto' }}>
        <form onSubmit={submit}><header className="page-head"><h2 id="supplier-return-title">{sourceText("Return goods to supplier", "إرجاع بضاعة إلى المورد")}</h2><button type="button" className="btn" disabled={busy} onClick={onClose} aria-label={sourceText("Close", "إغلاق")}>×</button></header>
        <p>{sourceText("This confirms goods leaving the original receipt warehouse. It does not reverse the supplier bill or payment.", "يؤكد هذا الإجراء خروج البضاعة من مستودع الاستلام الأصلي. لا يعكس فاتورة المورد أو الدفعة.")}</p>
        {!options ? <p role="status">{sourceText("Loading…", "جارٍ التحميل…")}</p> : <>
            {(options.returns ?? []).length > 0 && <div className="panel"><strong>{sourceText("Existing returns", "المرتجعات السابقة")}</strong>{options.returns.map(r => <p key={r.id}>{r.return_number} · {sourceText(({draft:'Draft',posted:'Posted',reversed:'Reversed'}[r.status] ?? r.status), ({draft:'مسودة',posted:'مرحّل',reversed:'معكوس'}[r.status] ?? r.status))}{r.status === 'draft' && <button type="button" className="btn" disabled={busy} onClick={() => setDraft(r)}>{sourceText("Resume posting this draft", "متابعة ترحيل هذه المسودة")}</button>}</p>)}</div>}
            {draft ? <p role="status">{sourceText("Saved draft", "المسودة المحفوظة")}: {draft.return_number}. {sourceText("Retry uses this same document.", "إعادة المحاولة تستخدم نفس المستند.")}</p> : <>
                <label>{sourceText("Return date", "تاريخ الإرجاع")}<input type="date" required value={date} onChange={e => setDate(e.target.value)} /></label>
                <label>{sourceText("Reason", "سبب الإرجاع")}<textarea required minLength={3} maxLength={2000} value={reason} onChange={e => setReason(e.target.value)} /></label>
                <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>{sourceText("Item / lot / serial", "الصنف / دفعة / تسلسل")}</th><th>{sourceText("Warehouse", "المستودع")}</th><th>{sourceText("Remaining returnable", "المتبقي للإرجاع")}</th><th>{sourceText("Actual quantity", "الكمية الفعلية")}</th></tr></thead><tbody>{options.lines.map(l => <tr key={l.source_stock_ledger_id}><td>{l.item_name} {l.lot_id && `· Lot #${l.lot_id}`} {l.serial_id && `· Serial #${l.serial_id}`}</td><td>#{l.warehouse_id}</td><td style={{ whiteSpace: 'nowrap' }}>{String(l.remaining_entered_qty).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1')} {l.unit}</td><td><input aria-label={`${sourceText("Return quantity", "كمية الإرجاع")} ${l.item_name} ${l.source_stock_ledger_id}`} style={{ minWidth: '7rem' }} type="number" min="0" max={l.remaining_entered_qty} step="0.0001" value={quantities[l.source_stock_ledger_id] ?? ''} onChange={e => setQuantities(q => ({ ...q, [l.source_stock_ledger_id]: e.target.value }))} disabled={busy || Number(l.remaining_entered_qty) <= 0} /></td></tr>)}</tbody></table></div>
            </>}
            <label><input type="checkbox" checked={arrived} onChange={e => setArrived(e.target.checked)} disabled={busy} required />{sourceText("I confirm these quantities are physically being returned to the supplier.", "أؤكد أن الكميات المحددة تُعاد فعلياً إلى المورد.")}</label>
            <button className="btn btn--primary" disabled={busy || !arrived || (!draft && !Object.values(quantities).some(v => Number(v) > 0))}>{busy ? (sourceText("Processing…", "جارٍ التنفيذ…")) : (sourceText("Post supplier return", "ترحيل مرتجع المورد"))}</button>
        </>}
        </form>
    </dialog>;
}
