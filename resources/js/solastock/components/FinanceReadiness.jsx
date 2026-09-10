import React, {useEffect, useRef, useState} from 'react';
import {Link} from 'react-router-dom';
import './FinanceReadiness.css';
import financeIcon from './solacount-logo.svg';
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
const descriptions={
 FINANCE_PROVISIONED_SETUP_INCOMPLETE:['Complete your Finance setup to continue connecting SolaStock.','أكمل إعداد النظام المالي لمتابعة ربط SolaStock.'],
 ACCESS_REQUIRED:['Review the required product access to connect SolaStock with SolaCount.','راجع الوصول المطلوب للتطبيقات لربط SolaStock مع SolaCount.'],
 PROVISIONING_PENDING:['Your Finance workspace is being prepared. Check again shortly.','جارٍ تجهيز مساحة العمل المالية. تحقق مجدداً بعد قليل.'],
 CONNECTION_SETUP_INCOMPLETE:['Finance is ready. Continue the connection review before activating sync.','النظام المالي جاهز. تابع مراجعة الربط قبل تفعيل المزامنة.'],
 ACTIVATION_REQUIRED:['Review the remaining approvals before activating the connection.','راجع الموافقات المتبقية قبل تفعيل الربط.'],
 CONNECTED_READY:['Your connection is active and ready to sync financial documents.','الربط مفعّل وجاهز لمزامنة المستندات المالية.'],
 MAINTENANCE_HOLD:['Financial sync is paused by a safety hold. Review the reason below.','المزامنة المالية معلّقة لإجراء أمان. راجع السبب أدناه.'],
 CONNECTION_BLOCKED:['Financial sync needs attention. Review the connection details.','المزامنة المالية تحتاج إلى مراجعة. راجع تفاصيل الربط.'],
 READINESS_UNAVAILABLE:['Connection status is temporarily unavailable. Please try again.','حالة الربط غير متاحة مؤقتاً. أعد المحاولة.'],
};
export function FinanceReadinessSkeleton(){return <section className="finance-readiness-shell" aria-busy="true" aria-label={document.documentElement.lang.startsWith('ar')?'جارٍ التحقق من حالة الربط':'Checking connection status'}><div className="finance-readiness__skeleton" aria-hidden="true"><span className="finance-readiness__skeleton-icon"/><div className="finance-readiness__skeleton-lines"><span/><span/><span/></div><span className="finance-readiness__skeleton-action"/></div></section>;}
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
 return <section className="finance-readiness-shell" dir={ar?'rtl':'ltr'}>
 <div className="finance-readiness" dir={ar?'rtl':'ltr'}>
  <div className="finance-readiness__body"><img className="finance-readiness__icon" src={financeIcon} alt=""/><div className="finance-readiness__copy">
  <div className="finance-readiness__heading"><strong>SolaCount</strong><span className="finance-readiness__badge" data-tone={s?.state==='CONNECTED_READY'?'success':(!s||['READINESS_UNAVAILABLE','PROVISIONING_PENDING'].includes(s.state))?'neutral':'warning'}>{(labels[s?.state]??labels.READINESS_UNAVAILABLE)[i]}</span></div>
  <p>{(descriptions[s?.state]??descriptions.READINESS_UNAVAILABLE)[i]}</p>
  {status?.draft_status==='in_progress'&&status?.connection_wizard?.run_uuid&&<p className="finance-readiness__support">{ar?'تقدم إعداد الربط محفوظ':'Your connection setup progress is saved'}</p>}
  {s?.state==='CONNECTED_READY'&&status?.last_sync_at&&<p className="finance-readiness__support">{ar?'آخر مزامنة':'Last sync'}: {new Date(status.last_sync_at).toLocaleString(ar?'ar':'en')}</p>}
  {(s?.blockers??[]).filter(k=>blockers[k]).map(k=><p className="finance-readiness__blocker" role="status" key={k}>{blockers[k][i]}</p>)}
  {incomplete&&!s?.setup_url&&<p>{ar?'اطلب من مسؤول المؤسسة المخوّل إكمال الإعداد المالي.':'Ask an authorized organization administrator to complete Finance setup.'}</p>}
  </div></div><div className="finance-readiness__actions">
   {s?.state==='ACCESS_REQUIRED'&&<a className="btn btn--primary" href={s.manage_access_url}>{ar?'إدارة التطبيقات والخطط':'Manage apps and plans'}</a>}
   {incomplete&&s?.setup_url&&<button className="btn btn--primary" disabled={pending} aria-busy={pending} onClick={setup}>{pending&&<i className="fa-solid fa-spinner fa-spin" aria-hidden="true"/>} {pending?(ar?'جارٍ فتح الإعداد المالي…':'Opening Finance setup…'):(ar?'إكمال الإعداد المالي':'Complete Finance setup')}</button>}
   {details&&s?.finance_setup_complete&&s?.can_manage&&s?.state!=='CONNECTED_READY'&&<button className="btn btn--primary" onClick={onContinue}>{ar?'متابعة إعداد الربط':'Continue connection setup'}</button>}
   {(!s||s.state==='READINESS_UNAVAILABLE')&&<button className="btn" onClick={onRetry}>{ar?'إعادة المحاولة':'Retry'}</button>}
   {!details&&<Link className="btn ghost" to="/integrations/solabooks">{ar?'عرض التفاصيل':'View details'}<svg className="finance-readiness__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></Link>}
  </div>
 </div>
  {error&&<p className="finance-readiness__footer" role="alert">{error}</p>}
  {details&&s?.checked_at&&<small>{ar?'آخر تحقق':'Last checked'}: {new Date(s.checked_at).toLocaleString(ar?'ar':'en')}</small>}
  <style>{'@media(prefers-reduced-motion:reduce){.fa-spin{animation:none!important}}'}</style>
 </section>;
}
