import React,{useEffect,useId,useRef}from'react';
import{createPortal}from'react-dom';
import{Link}from'react-router-dom';
import{useI18n}from'../i18n/context.jsx';
import{PlanActions,PlanName}from'./PlanAccess.jsx';
export default function LockedFeatureDialog({item,label,onClose}){
 const ref=useRef(null);const title=useId();const{t}=useI18n();
 useEffect(()=>{const dialog=ref.current;const trigger=document.activeElement;dialog.showModal();return()=>{if(dialog.open)dialog.close();if(trigger?.isConnected)trigger.focus();};},[]);
 return createPortal(<dialog ref={ref} className="locked-feature-dialog" aria-labelledby={title} onClose={onClose} onCancel={e=>{e.preventDefault();ref.current.close();}} onKeyDown={e=>e.stopPropagation()} onClick={e=>{if(e.target===ref.current){const rect=ref.current.getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)ref.current.close();}}}>
  <div className="locked-feature-dialog-head"><h2 id={title}>{label} <span aria-hidden="true">🔒</span></h2><button type="button" className="btn" aria-label={t('planAccess.close')} onClick={()=>ref.current.close()}>×</button></div>
  <h3>{t('planAccess.planTitle')}</h3><p>{t('planAccess.planHint')}</p><p className="muted">{t('planAccess.currentPlan')}: <PlanName/></p>
  {item.canViewHistory&&<><p>{t('planAccess.historyHint')}</p><Link className="btn" to={item.path} onClick={()=>ref.current.close()}>{t('planAccess.viewRecords')}</Link></>}
  <PlanActions onNavigate={()=>ref.current.close()}/><p className="muted">{t('planAccess.ownerHint')}</p>
 </dialog>,document.body);
}
