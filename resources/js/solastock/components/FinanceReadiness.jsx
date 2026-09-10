import React, {useEffect, useRef, useState} from 'react';
import {Link} from 'react-router-dom';
import {api} from '../services/api.js';
const labels={
 FINANCE_PROVISIONED_SETUP_INCOMPLETE:['Finance setup incomplete','إعداد النظام المالي غير مكتمل'],
 ACCESS_REQUIRED:['Finance and Stock access required','يلزم الوصول إلى المالية والمخزون'],
 PROVISIONING_PENDING:['Finance provisioning pending','جارٍ تجهيز النظام المالي'],
 CONNECTION_SETUP_INCOMPLETE:['Connection setup incomplete','إعداد الربط غير مكتمل'],
 ACTIVATION_REQUIRED:['Ready for activation review','جاهز لمراجعة التفعيل'],
 CONNECTED_READY:['Connected','متصل'], MAINTENANCE_HOLD:['Maintenance safety hold','تعليق أمان للصيانة'],
 CONNECTION_BLOCKED:['Connection needs attention','الربط يحتاج إلى مراجعة'],
 READINESS_UNAVAILABLE:['Unable to verify connection status','تعذّر التحقق من حالة الربط'],
};
const blockers={sync_worker_unavailable:['Sync is awaiting a healthy delivery worker. An administrator can review transport status.','المزامنة بانتظار عامل إرسال سليم. يمكن للمسؤول مراجعة حالة النقل.'],currency_contract_maintenance:['Financial delivery is paused for currency-contract maintenance. An authorized administrator must review the hold.','المزامنة المالية متوقفة لصيانة عقد العملات. يلزم مراجعة مسؤول مخوّل.'],reconciliation_hold:['Connection reconciliation and activation review are still required.','لا تزال مراجعة تسوية الربط وتفعيله مطلوبة.'],sync_errors:['Some documents could not sync. Review connection details.','تعذّرت مزامنة بعض المستندات. راجع تفاصيل الربط.']};
export default function FinanceReadiness({status,details=false,onContinue,onRetry}) {
 const ar=document.documentElement.lang.startsWith('ar'),i=ar?1:0,s=status?.readiness;
 const busy=useRef(false),[pending,setPending]=useState(false),[error,setError]=useState('');
 useEffect(()=>{const restore=()=>{busy.current=false;setPending(false);onRetry?.();};window.addEventListener('pageshow',restore);return()=>window.removeEventListener('pageshow',restore);},[onRetry]);
 async function setup(){if(busy.current)return;busy.current=true;setPending(true);setError('');try{
  const response=await api.integrationStatus();const next=(response?.data??response)?.readiness;
  if(next?.finance_setup_complete){busy.current=false;setPending(false);onRetry?.();return;}
  if(!next?.setup_url||next.setup_url!==s.setup_url){onRetry?.();throw Error();}
  window.location.assign(next.setup_url);
 }catch{busy.current=false;setPending(false);setError(ar?'تعذّر فتح الإعداد. أعد المحاولة.':'Could not open setup. Please retry.');}}
 const incomplete=s?.state==='FINANCE_PROVISIONED_SETUP_INCOMPLETE';
 return <section className="panel" dir={ar?'rtl':'ltr'} style={{display:'grid',gap:12}}>
  <div style={{display:'flex',alignItems:'center',gap:12,flexWrap:'wrap'}}><strong>SolaCount</strong><span className={`badge ${s?.state==='CONNECTED_READY'?'badge--live':'badge--warn'}`}>{(labels[s?.state]??labels.READINESS_UNAVAILABLE)[i]}</span></div>
  <p style={{margin:0}}>{incomplete?(ar?'أكمل الإعداد المالي قبل متابعة إعداد ربط المخزون وتسويته. سيظل تقدمك المحفوظ كما هو.':'Complete Finance setup before continuing the Stock connection and reconciliation review. Your saved progress is preserved.'):(ar?'إعداد المالية ومراجعة ربط المخزون وتفعيل المزامنة خطوات منفصلة.':'Finance setup, Stock connection review, and sync activation are separate steps.')}</p>
  {(s?.blockers??[]).filter(k=>blockers[k]).map(k=><p role="status" key={k} style={{margin:0}}>{blockers[k][i]}</p>)}
  {incomplete&&!s?.setup_url&&<p>{ar?'اطلب من مسؤول المؤسسة المخوّل إكمال الإعداد المالي.':'Ask an authorized organization administrator to complete Finance setup.'}</p>}
  <div style={{display:'flex',gap:12,flexWrap:'wrap'}}>
   {s?.state==='ACCESS_REQUIRED'&&<a className="btn btn--primary" href={s.manage_access_url}>{ar?'إدارة التطبيقات والخطط':'Manage apps and plans'}</a>}
   {incomplete&&s?.setup_url&&<button className="btn btn--primary" disabled={pending} aria-busy={pending} onClick={setup}>{pending&&<i className="fa-solid fa-spinner fa-spin" aria-hidden="true"/>} {pending?(ar?'جارٍ فتح الإعداد المالي…':'Opening Finance setup…'):(ar?'إكمال الإعداد المالي':'Complete Finance setup')}</button>}
   {details&&s?.finance_setup_complete&&s?.can_manage&&s?.state!=='CONNECTED_READY'&&<button className="btn btn--primary" onClick={onContinue}>{ar?'متابعة إعداد الربط':'Continue connection setup'}</button>}
   {(!s||s.state==='READINESS_UNAVAILABLE')&&<button className="btn" onClick={onRetry}>{ar?'إعادة المحاولة':'Retry'}</button>}
   {!details&&<Link to="/integrations/solabooks">{ar?'تفاصيل التكامل':'Integration details'}</Link>}
  </div>
  {error&&<p role="alert">{error}</p>}
  {details&&s?.checked_at&&<small>{ar?'آخر تحقق':'Last checked'}: {new Date(s.checked_at).toLocaleString(ar?'ar':'en')}</small>}
  <style>{'@media(prefers-reduced-motion:reduce){.fa-spin{animation:none!important}}'}</style>
 </section>;
}
