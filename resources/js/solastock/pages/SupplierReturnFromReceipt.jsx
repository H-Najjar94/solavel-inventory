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
            toast.push(ar ? 'تم ترحيل مرتجع المورد. تتم مزامنة المستند المالي بشكل منفصل.' : 'Supplier return posted. Its financial document synchronizes separately.', 'success'); onPosted(); onClose();
        } catch (error) { toast.failure(error, error.message); } finally { setBusy(false); }
    }
    return <dialog ref={dialog} className="panel" aria-labelledby="supplier-return-title" onCancel={e => { if (busy) e.preventDefault(); else onClose(); }} style={{ maxWidth: 'min(720px, 95vw)', maxHeight: '90vh', overflow: 'auto' }}>
        <form onSubmit={submit}><header className="page-head"><h2 id="supplier-return-title">{ar ? 'إرجاع بضاعة إلى المورد' : 'Return goods to supplier'}</h2><button type="button" className="btn" disabled={busy} onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'}>×</button></header>
        <p>{ar ? 'يؤكد هذا الإجراء خروج البضاعة من مستودع الاستلام الأصلي. لا يعكس فاتورة المورد أو الدفعة.' : 'This confirms goods leaving the original receipt warehouse. It does not reverse the supplier bill or payment.'}</p>
        {!options ? <p role="status">{ar ? 'جارٍ التحميل…' : 'Loading…'}</p> : <>
            {(options.returns ?? []).length > 0 && <div className="panel"><strong>{ar ? 'المرتجعات السابقة' : 'Existing returns'}</strong>{options.returns.map(r => <p key={r.id}>{r.return_number} · {ar ? ({ draft: 'مسودة', posted: 'مرحّل', reversed: 'معكوس' }[r.status] ?? r.status) : r.status}{r.status === 'draft' && <button type="button" className="btn" disabled={busy} onClick={() => setDraft(r)}>{ar ? 'متابعة ترحيل هذه المسودة' : 'Resume posting this draft'}</button>}</p>)}</div>}
            {draft ? <p role="status">{ar ? 'المسودة المحفوظة' : 'Saved draft'}: {draft.return_number}. {ar ? 'إعادة المحاولة تستخدم نفس المستند.' : 'Retry uses this same document.'}</p> : <>
                <label>{ar ? 'تاريخ الإرجاع' : 'Return date'}<input type="date" required value={date} onChange={e => setDate(e.target.value)} /></label>
                <label>{ar ? 'سبب الإرجاع' : 'Reason'}<textarea required minLength={3} maxLength={2000} value={reason} onChange={e => setReason(e.target.value)} /></label>
                <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>{ar ? 'الصنف / دفعة / تسلسل' : 'Item / lot / serial'}</th><th>{ar ? 'المستودع' : 'Warehouse'}</th><th>{ar ? 'المتبقي للإرجاع' : 'Remaining returnable'}</th><th>{ar ? 'الكمية الفعلية' : 'Actual quantity'}</th></tr></thead><tbody>{options.lines.map(l => <tr key={l.source_stock_ledger_id}><td>{l.item_name} {l.lot_id && `· Lot #${l.lot_id}`} {l.serial_id && `· Serial #${l.serial_id}`}</td><td>#{l.warehouse_id}</td><td>{l.remaining_entered_qty} {l.unit}</td><td><input aria-label={`${ar ? 'كمية الإرجاع' : 'Return quantity'} ${l.item_name} ${l.source_stock_ledger_id}`} type="number" min="0" max={l.remaining_entered_qty} step="0.0001" value={quantities[l.source_stock_ledger_id] ?? ''} onChange={e => setQuantities(q => ({ ...q, [l.source_stock_ledger_id]: e.target.value }))} disabled={busy || Number(l.remaining_entered_qty) <= 0} /></td></tr>)}</tbody></table></div>
            </>}
            <label><input type="checkbox" checked={arrived} onChange={e => setArrived(e.target.checked)} disabled={busy} required />{ar ? 'أؤكد أن الكميات المحددة تُعاد فعلياً إلى المورد.' : 'I confirm these quantities are physically being returned to the supplier.'}</label>
            <button className="btn btn--primary" disabled={busy || !arrived || (!draft && !Object.values(quantities).some(v => Number(v) > 0))}>{busy ? (ar ? 'جارٍ التنفيذ…' : 'Processing…') : (ar ? 'ترحيل مرتجع المورد' : 'Post supplier return')}</button>
        </>}
        </form>
    </dialog>;
}
