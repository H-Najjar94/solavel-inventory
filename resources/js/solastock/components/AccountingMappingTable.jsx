import React, { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../i18n/context.jsx';

const roles = {
    inventory_asset: ['Inventory', 'المخزون', 'Value of inventory held.', 'قيمة المخزون المتاح.'],
    cogs: ['Cost of goods sold', 'تكلفة البضاعة المباعة', 'Cost recognized when inventory is sold.', 'التكلفة عند بيع المخزون.'],
    grni: ['Goods received, not invoiced (GRNI)', 'بضاعة مستلمة غير مفوترة', 'Receipts awaiting a supplier bill.', 'استلامات بانتظار فاتورة المورد.'],
    opening_offset: ['Opening balance offset', 'مقابل الرصيد الافتتاحي', 'Offset for reviewed opening inventory.', 'مقابل المخزون الافتتاحي الذي تمت مراجعته.'],
    adjustment_gain: ['Inventory adjustment gain', 'زيادة تسوية المخزون', 'Value gained through an adjustment.', 'قيمة الزيادة الناتجة عن التسوية.'],
    adjustment_loss: ['Inventory adjustment loss', 'خسارة تسوية المخزون', 'Value lost through an adjustment.', 'قيمة النقص الناتج عن التسوية.'],
    landed_cost_clearing: ['Landed cost clearing', 'تسوية التكاليف الإضافية', 'Costs awaiting inventory allocation.', 'تكاليف بانتظار التوزيع على المخزون.'],
    transfer_clearing: ['Transfer clearing', 'تسوية التحويلات', 'Clearing for applicable transfers.', 'تسوية التحويلات التي تتطلب قيداً.'],
    accounts_receivable: ['Accounts receivable', 'الذمم المدينة', 'Amounts owed by customers.', 'المبالغ المستحقة على العملاء.'],
    accounts_payable: ['Accounts payable', 'الذمم الدائنة', 'Amounts owed to suppliers.', 'المبالغ المستحقة للموردين.'],
    input_tax: ['Input tax', 'ضريبة المدخلات', 'Recoverable purchase tax.', 'ضريبة المشتريات القابلة للاسترداد.'],
    output_tax: ['Output tax', 'ضريبة المخرجات', 'Tax collected on sales.', 'الضريبة المحصلة على المبيعات.'],
    rounding: ['Rounding and cutoff', 'التقريب وتاريخ البدء', 'Reviewed rounding or cutoff differences.', 'فروق التقريب أو تاريخ البدء التي تمت مراجعتها.'],
    sales_revenue: ['Sales revenue', 'إيراد المبيعات', 'Revenue from inventory sales.', 'الإيراد من مبيعات المخزون.'],
};
export function recordText(value, locale = 'en') {
    if (value == null) return '';
    if (typeof value === 'object') return String(value[locale] || value.en || value.ar || Object.values(value)[0] || '');
    try { const parsed = JSON.parse(value); if (parsed && typeof parsed === 'object') return recordText(parsed, locale); } catch { /* Plain record name. */ }
    return String(value);
}
export function savedAccount(row, decisions) {
    const detail = row.safe_details || {};
    const decision = decisions.get(row.fingerprint);
    const choices = detail.available_finance_accounts || [];
    if (decision?.action === 'select_account_role' && decision.valid_for_current_candidate !== false) {
        return choices.find(account => String(account.id) === String(decision.safe_details?.selected_record_id)) || null;
    }
    return detail.current_mapping_valid ? detail.current_mapping : null;
}
function AccountPicker({ accounts, selected, onSelect, disabled, label, locale }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [position, setPosition] = useState(null);
    const button = useRef(null), panel = useRef(null), search = useRef(null);
    const id = useId();
    const arabic = locale === 'ar';
    const title = account => `${account.code} · ${recordText(account.name, locale)} · ${account.type}`;
    const filtered = accounts.filter(account => title(account).toLocaleLowerCase().includes(query.toLocaleLowerCase()));
    const close = () => { setOpen(false); button.current?.focus(); };
    useEffect(() => { setActive(0); }, [query]);
    useEffect(() => {
        if (!open) return undefined;
        const place = () => {
            const rect = button.current.getBoundingClientRect();
            const width = Math.min(Math.max(rect.width, 320), window.innerWidth - 16);
            const below = window.innerHeight - rect.bottom - 8;
            const height = Math.min(320, Math.max(below, rect.top - 8));
            setPosition({ position: 'fixed', width, maxHeight: height,
                left: Math.max(8, Math.min(arabic ? rect.right - width : rect.left, window.innerWidth - width - 8)),
                ...(below >= Math.min(height, 240) ? { top: rect.bottom + 4 } : { bottom: window.innerHeight - rect.top + 4 }) });
        };
        const outside = event => { if (!panel.current?.contains(event.target) && !button.current?.contains(event.target)) close(); };
        place(); search.current?.focus();
        window.addEventListener('resize', place); window.addEventListener('scroll', place, true);
        document.addEventListener('pointerdown', outside);
        return () => { window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true); document.removeEventListener('pointerdown', outside); };
    }, [open, arabic]);
    useEffect(() => { if (open) document.getElementById(`${id}-${active}`)?.scrollIntoView({ block: 'nearest' }); }, [active, open]);
    return <><button ref={button} type="button" className="wizard-account-select" disabled={disabled}
        aria-label={label} aria-haspopup="listbox" aria-expanded={open} aria-controls={open ? id : undefined}
        onClick={() => { setQuery(''); setOpen(value => !value); }}>
        <span>{selected ? title(selected) : arabic ? 'اختر حساباً' : 'Select account'}</span><span aria-hidden="true">⌄</span>
    </button>{open && createPortal(<div ref={panel} className="wizard-account-popover" dir={arabic ? 'rtl' : 'ltr'} style={position} onKeyDown={event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
        if (event.key === 'Tab') close();
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); setActive(value => Math.max(0, Math.min(filtered.length - 1, value + (event.key === 'ArrowDown' ? 1 : -1)))); }
        if (event.key === 'Enter' && filtered[active]) { event.preventDefault(); onSelect(filtered[active]); close(); }
    }}>
        <input ref={search} role="combobox" aria-label={arabic ? 'البحث برمز الحساب أو اسمه' : 'Search account code or name'} aria-expanded="true" aria-controls={id} aria-activedescendant={filtered[active] ? `${id}-${active}` : undefined} autoComplete="off" value={query} onChange={event => setQuery(event.target.value)} placeholder={arabic ? 'ابحث بالرمز أو الاسم' : 'Search code or name'} />
        <div id={id} role="listbox" aria-label={label}>{filtered.map((account, index) => <button type="button" role="option" tabIndex={-1} id={`${id}-${index}`} key={account.id} aria-selected={String(account.id) === String(selected?.id)} className={active === index ? 'is-highlighted' : ''} onPointerMove={() => setActive(index)} onClick={() => { onSelect(account); close(); }}><span><bdi>{account.code}</bdi> · {recordText(account.name, locale)}<small>{account.type}</small></span><span aria-hidden="true">{String(account.id) === String(selected?.id) ? '✓' : ''}</span></button>)}</div>
        {!filtered.length && <p role="status">{arabic ? 'لا توجد حسابات مطابقة' : 'No matching accounts'}</p>}
    </div>, document.body)}</>;
}
export default function AccountingMappingTable({ rows, decisions, choose, canEdit, saving }) {
    const { locale } = useI18n();
    const ar = locale === 'ar';
    const [proposals, setProposals] = useState({});
    const [failed, setFailed] = useState(null);
    const [pending, setPending] = useState(null);
    return <div className="wizard-account-table"><p className="wizard-account-note">{ar ? 'الاقتراحات ليست اختيارات محفوظة. راجع الحساب واحفظه؛ تبقى الموافقة المحاسبية النهائية خطوة منفصلة.' : 'Proposals are not saved selections. Review and save each account; final accounting approval remains a separate step.'}</p>
        {rows.map(row => {
            const detail = row.safe_details || {}, role = detail.role || detail.accounting_role;
            const copy = roles[role];
            const label = copy?.[ar ? 1 : 0] || role;
            const saved = savedAccount(row, decisions);
            const proposed = proposals[row.fingerprint] || saved || detail.recommended_account;
            const choices = detail.available_finance_accounts || [];
            const invalid = detail.current_mapping_invalid || (decisions.has(row.fingerprint) && !saved);
            const state = detail.required === false && !saved ? (ar ? 'اختياري — غير مطلوب للعمليات الحالية' : 'Optional — not required for current operations') : saved ? (ar ? 'اختيار محفوظ' : 'Saved selection') : invalid ? (ar ? 'غير صالح — يلزم المراجعة' : 'Invalid — review required') : !choices.length ? (ar ? 'حساب مطلوب غير متاح' : 'Missing account') : proposed ? (ar ? 'اقتراح للمراجعة' : 'Proposed for review') : (ar ? 'خيارات متعددة — اختر حساباً' : 'Ambiguous — select an account');
            return <section className="wizard-account-row" key={row.fingerprint} aria-label={label}>
                <div><strong>{label}</strong><small>{detail.required === false ? (ar ? 'اختياري للعمليات الحالية' : 'Optional for current operations') : (ar ? 'مطلوب للعمليات المفعلة' : 'Required for enabled operations')}</small><small>{copy?.[ar ? 3 : 2]}</small>
{detail.current_mapping && <small>{ar ? 'الربط الحالي: ' : 'Current mapping: '}<bdi>{detail.current_mapping.code}</bdi> · {recordText(detail.current_mapping.name, locale)}</small>}</div>
                <div><AccountPicker accounts={choices} selected={proposed} disabled={!canEdit || saving || !choices.length} label={label} locale={locale} onSelect={account => { setProposals(value => ({ ...value, [row.fingerprint]: account })); setFailed(null); }} />
                    {!saved && detail.recommendation_source === 'verified_finance_default' && <small>{ar ? 'مقترح من إعدادات الحسابات الافتراضية الموثقة في SolaCount.' : 'Proposed from the verified SolaCount default-account mapping.'}</small>}
                    {detail.account_proposal && <small role="note"><strong>{ar ? 'اقتراح حساب جديد — لم يتم إنشاؤه أو اعتماده' : 'New account proposal — not created or approved'}</strong><br /><bdi>{detail.account_proposal.code}</bdi> · {recordText(detail.account_proposal.name, locale)} · {detail.account_proposal.type}{detail.account_proposal.kind === 'code_conflict_requires_review' && <span>{ar ? ' — الرمز مستخدم؛ يلزم المراجعة' : ' — code already used; review required'}</span>}</small>}
                    {detail.finance_chart_ready === false && <small>{ar ? 'أكمل إعداد دليل الحسابات في SolaCount أولاً.' : 'Complete the SolaCount chart of accounts setup first.'}</small>}
                    {!choices.length && <small>{ar ? 'اطلب من مسؤول الحسابات مراجعة دليل الحسابات في SolaCount. لن يتم إنشاء حساب تلقائياً.' : 'An accounting administrator must review the SolaCount chart of accounts. No account will be created automatically.'}</small>}
                </div><div><span className={`wizard-account-state ${saved ? 'is-saved' : 'is-pending'}`} role="status">{state}</span>
                    {canEdit && proposed && String(proposed.id) !== String(saved?.id) && <button type="button" className="btn btn--primary" disabled={saving} onClick={async () => {
                        setPending(row.fingerprint);
                        try {
                            const result = await choose(row, 'select_account_role', { selected_record_id: String(proposed.id) });
                            setFailed(result ? null : row.fingerprint);
                        } finally { setPending(null); }
                    }}>{pending === row.fingerprint ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ الاختيار' : 'Save selection')}</button>}
                    {failed === row.fingerprint && <small role="alert">{ar ? 'لم يتم الحفظ. راجع رسالة الخطأ وأعد المحاولة.' : 'Selection was not saved. Review the error and retry.'}</small>}
                </div>
            </section>;
        })}
    </div>;
}
