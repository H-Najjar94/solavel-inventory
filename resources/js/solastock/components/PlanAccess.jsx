import React from 'react';
import {Link} from 'react-router-dom';
import {useMeta} from '../stores/meta.jsx';
import {useI18n} from '../i18n/context.jsx';
import {accessAreas,areaAccess,accessKind} from '../services/planAccess.mjs';
export function PlanName(){
 const meta=useMeta();const {t}=useI18n();const tier=meta.plan?.tier;
 return <bdi>{tier?t(`planAccess.tier.${tier}`,tier):t('planAccess.unknownPlan')}</bdi>;
}
export function PlanActions({kind='plan',overview=true,onNavigate}){
 const meta=useMeta();const {t}=useI18n();
 return <div className="plan-access-actions">
  {overview&&<Link className="btn" to="/plan-access" onClick={onNavigate}>{t('planAccess.title')}</Link>}
  {['plan','subscription'].includes(kind)&&meta.plan?.manage_url&&<a className="btn btn--primary" href={meta.plan.manage_url} onClick={onNavigate} target="_blank" rel="noopener noreferrer">{t(kind==='plan'?'planAccess.viewPlans':'planAccess.manageSubscription')}<span className="ss-visually-hidden"> {t('planAccess.newTab')}</span></a>}
 </div>;
}
export function AccessNotice({reason,viewOnly=false}){
 const meta=useMeta();const {t}=useI18n();const kind=accessKind(reason);
 return <aside className={`panel plan-access-notice plan-access-notice--${kind}`} aria-label={t(`planAccess.${kind}Title`)}>
  <div><h2>{t(`planAccess.${kind}Title`)}</h2><p>{t(`planAccess.${kind}Hint`)}</p>{viewOnly&&<p>{t('planAccess.historyHint')}</p>}{kind==='plan'&&<p className="muted">{t('planAccess.currentPlan')}: <PlanName/></p>}</div>
  <PlanActions kind={kind}/>
  {kind==='check'&&<button className="btn" type="button" onClick={()=>meta.refetch()}>{t('partners.common.retry')}</button>}
 </aside>;
}
export function ContextAccessNotice({path}){
 const meta=useMeta();if(!accessAreas.some(([,p])=>p===path))return null;
 const access=areaAccess(path,meta);if(!access?.read.allowed||access.create.allowed)return null;
 return <div className="plan-access-context"><AccessNotice reason={access.reason} viewOnly/></div>;
}
export function PlanSummary(){
 const meta=useMeta();const {t}=useI18n();if(meta.isPlaceholderData||!meta.ui_access?.routes)return null;
 return <aside className="panel plan-summary"><div><strong>{t('planAccess.currentPlan')}: <PlanName/></strong><p className="muted">{t('planAccess.summary')}</p></div><Link className="btn" to="/plan-access">{t('planAccess.title')}</Link></aside>;
}
