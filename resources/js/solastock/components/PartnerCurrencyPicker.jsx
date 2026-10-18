import React from 'react';
import {useMeta} from '../stores/meta.jsx';
import {useI18n} from '../i18n/context.jsx';
import {Field} from './ui.jsx';
export default function PartnerCurrencyPicker({value,onChange,error}) {
    const meta=useMeta();const {t,locale}=useI18n();
    const options=meta.partner_currencies??[];
    const saved=value&&!options.some(option=>option.code===value);
    let names;try{names=new Intl.DisplayNames([locale],{type:'currency'});}catch{}
    return <Field label={t('partners.common.currency')} error={error}>
        <select className="input" name="currency" dir="auto" value={value??''} onChange={e=>onChange(e.target.value)} disabled={meta.isPlaceholderData}>
            <option value="">{t('partners.currency.notSpecified')}</option>
            {saved&&<option value={value}>{value} — {t('partners.currency.saved')}</option>}
            {options.map(option=><option key={option.code} value={option.code}>{option.code} — {names?.of(option.code)||option.name||option.code}{option.is_base?` (${t('partners.currency.base')})`:''}</option>)}
        </select>
        <span className="field-hint">{t(options.length?'partners.currency.sharedHint':'partners.currency.unconfigured')}</span>
    </Field>;
}
