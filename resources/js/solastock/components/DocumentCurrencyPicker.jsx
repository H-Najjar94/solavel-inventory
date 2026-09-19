import React, {useEffect} from 'react';
import {useApiQuery} from '../hooks/useApiQuery.js';
import {api} from '../services/api.js';
import {useI18n} from '../i18n/context.jsx';
import {Field} from './ui.jsx';

export function DocumentCurrencyPicker({value, onChange, error}) {
    const {t} = useI18n();
    const {data, isLoading} = useApiQuery(['meta'], api.meta, {fallback: {}});
    const currency = data?.document_currency;
    const options = currency?.enabled ?? [];
    useEffect(() => {
        if (!value && currency?.base && options.includes(currency.base)) onChange(currency.base);
    }, [value, currency?.base, options.join(','), onChange]);
    return <Field label={t('documents.transactionCurrency', 'Transaction currency')} error={error} required>
        {isLoading || options.length > 0 ? <select className="input" value={value ?? ''} onChange={e => onChange(e.target.value)} disabled={isLoading}>
            <option value="">—</option>{options.map(code => <option key={code} value={code}>{code}</option>)}
        </select> : <input className="input" dir="ltr" maxLength={3} value={value ?? ''} onChange={e => onChange(e.target.value.toUpperCase())} />}
    </Field>;
}
