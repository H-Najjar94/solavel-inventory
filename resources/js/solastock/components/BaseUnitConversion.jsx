import React, {useState,useRef} from 'react';
import {useQueryClient} from '@tanstack/react-query';
import {api} from '../services/api.js';
import {Drawer,Field} from './ui.jsx';
import {useToast} from '../stores/toast.jsx';
import {useMeta} from '../stores/meta.jsx';
import {t,getLocale} from '../i18n/index.js';

const METADATA_LABELS={purchase_price:'baseUnit.meta.purchase_price',sales_price:'baseUnit.meta.sales_price',weight:'baseUnit.meta.weight',reorder_point:'baseUnit.meta.reorder_point',reorder_qty:'baseUnit.meta.reorder_qty',min_stock:'baseUnit.meta.min_stock',max_stock:'baseUnit.meta.max_stock',safety_stock:'baseUnit.meta.safety_stock'};

export default function BaseUnitConversion({item,open,onClose,pending=false}) {
    const units=useMeta().options?.units ?? [];
    const qc=useQueryClient();const toast=useToast();const fileRef=useRef(null);
    const [form,setForm]=useState({to_unit_id:'',factor_numerator:'1',factor_denominator:'',effective_date:new Date().toISOString().slice(0,10),reason:''});
    const [preview,setPreview]=useState(null),[evidence,setEvidence]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[result,setResult]=useState(null);
    const [requestId,setRequestId]=useState(()=>crypto.randomUUID());
    const n=v=>Number(v??0).toLocaleString(getLocale(),{maximumFractionDigits:6});
    function change(key,value){setForm({...form,[key]:value});setPreview(null);setResult(null);setRequestId(crypto.randomUUID());setError('');}
    async function upload(file){if(!file)return;setBusy(true);setError('');try{const digest=await crypto.subtle.digest('SHA-256',await file.arrayBuffer());const sha256=Array.from(new Uint8Array(digest),v=>v.toString(16).padStart(2,'0')).join('');const res=await api.uploadItemAttachment(item.id,file);const attachment=res?.data??res;setEvidence({attachment_id:attachment.id,reference:`item-attachment:${attachment.id}`,sha256,name:file.name});}catch(e){setError(e.feedbackHandled?'':e.message);}finally{setBusy(false);}}
    async function review(){setBusy(true);setError('');try{const r=await api.previewBaseUnitConversion(item.id,form);setPreview(r?.data??r);}catch(e){setError(e.feedbackHandled?'':e.message);}finally{setBusy(false);}}
    async function apply(){if(!preview?.supported||!evidence)return;setBusy(true);setError('');try{const r=await api.applyBaseUnitConversion(item.id,{...form,revision_sha256:preview.revision_sha256,request_uuid:requestId,evidence});setResult(r?.data??r);await qc.invalidateQueries({queryKey:['item',String(item.id)]});await qc.invalidateQueries({queryKey:['item-valuation',String(item.id)]});await qc.invalidateQueries({queryKey:['item-movements',String(item.id)]});await qc.invalidateQueries({queryKey:['meta']});}catch(e){setError(e.feedbackHandled?'':e.message);}finally{setBusy(false);}}
    async function refresh(){setBusy(true);setError('');try{const r=await api.item(item.id);if(!(r?.data??r)?.base_unit_projection_pending){toast.push(t('baseUnit.completed'),'success');onClose();}await qc.invalidateQueries({queryKey:['item',String(item.id)]});}catch(e){setError(e.feedbackHandled?'':e.message);}finally{setBusy(false);}}
    async function retry(){setBusy(true);setError('');try{await api.retryBaseUnitConversion(item.id);setResult({state:'finance_pending'});}catch(e){setError(e.feedbackHandled?'':e.message);}finally{setBusy(false);}}
    return <Drawer open={open} onClose={busy?()=>{}:onClose} title={t('baseUnit.convert')} width="min(640px,100vw)">
        <p className="muted">{t('baseUnit.convertHint')}</p>
        {error&&<p className="field-error" role="alert">{error}</p>}
        {(pending||result?.state==='finance_pending')?<div className="card"><div className="card-body"><p role="status">{t('baseUnit.pending')}</p><button className="btn btn--primary" disabled={busy} onClick={retry}>{t('baseUnit.retry')}</button><button className="btn" disabled={busy} onClick={refresh}>{t('baseUnit.refresh')}</button></div></div>:result?<div className="card"><div className="card-body"><p role="status">{t('baseUnit.completed')}</p><button className="btn btn--primary" onClick={onClose}>{t('common.close')}</button></div></div>:<>
        <Field label={t('baseUnit.current')}><input className="input" value={item.base_unit?.name??item.base_unit?.code??''} readOnly /></Field>
        <Field label={t('baseUnit.target')} required><select className="input" value={form.to_unit_id} onChange={e=>change('to_unit_id',e.target.value)}><option value="">{t('select')}</option>{units.filter(u=>Number(u.id)!==Number(item.base_unit_id)).map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
        <div className="form-grid"><Field label={t('baseUnit.newCount')} required><input className="input" type="number" min="1" step="1" value={form.factor_numerator} onChange={e=>change('factor_numerator',e.target.value)} /></Field><Field label={t('baseUnit.oldCount')} required><input className="input" type="number" min="1" step="1" value={form.factor_denominator} onChange={e=>change('factor_denominator',e.target.value)} /></Field></div>
        <p className="muted">{t('baseUnit.factorHint')}</p>
        <Field label={t('baseUnit.effectiveDate')} required><input className="input" type="date" value={form.effective_date} onChange={e=>change('effective_date',e.target.value)} /></Field>
        <Field label={t('baseUnit.reason')} required><textarea className="input" value={form.reason} onChange={e=>change('reason',e.target.value)} /></Field>
        <Field label={t('baseUnit.evidence')} required><input ref={fileRef} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" hidden disabled={busy} onChange={e=>upload(e.target.files?.[0])} /><button type="button" className="btn" disabled={busy} onClick={()=>fileRef.current?.click()}>{busy?t('media.uploading'):t('media.uploadAttachment')}</button>{evidence&&<span>{evidence.name}</span>}</Field>
        {preview&&<div className="card"><div className="card-body">{!preview.supported?<ul role="alert">{(preview.blocker_messages??preview.blockers).map((b,i)=><li key={i}>{b}</li>)}</ul>:<><h4>{t('baseUnit.review')}</h4><table className="table"><thead><tr><th>{t('warehouse')}</th><th>{t('baseUnit.before')}</th><th>{t('baseUnit.after')}</th><th>{t('baseUnit.value')}</th></tr></thead><tbody>{preview.balances.map(b=><tr key={b.balance_id}><td>{b.warehouse_name??b.warehouse_id}</td><td>{n(b.old_quantity)}</td><td>{n(b.new_quantity)}</td><td>{n(b.value)}</td></tr>)}</tbody></table><h4>{t('baseUnit.metadata')}</h4><table className="table"><tbody>{Object.entries(preview.metadata??{}).map(([key,value])=><tr key={key}><th>{t(METADATA_LABELS[key]??'baseUnit.title')}</th><td>{n(preview.snapshot?.item?.[key])}</td><td>{n(value)}</td></tr>)}</tbody></table><p className="muted">{t('baseUnit.preserve')}</p></>}</div></div>}
        <div className="form-actions"><button type="button" className="btn" disabled={busy||!form.to_unit_id||!form.factor_denominator} onClick={review}>{busy?t('baseUnit.working'):t('baseUnit.preview')}</button>{preview?.supported&&<button type="button" className="btn btn--primary" disabled={busy||!form.reason.trim()||!evidence} onClick={apply}>{t('baseUnit.confirm')}</button>}</div>
        </>}
    </Drawer>;
}
