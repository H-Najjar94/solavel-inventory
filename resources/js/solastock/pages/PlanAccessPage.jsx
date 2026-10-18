import React from 'react';
import {Link} from 'react-router-dom';
import {useMeta} from '../stores/meta.jsx';
import {useI18n} from '../i18n/context.jsx';
import {PlanName,PlanActions} from '../components/PlanAccess.jsx';
import {accessAreas,areaAccess,accessKind} from '../services/planAccess.mjs';
import {Breadcrumbs} from '../components/ui.jsx';
export default function PlanAccessPage(){
 const meta=useMeta();const {t}=useI18n();
 const areas=accessAreas.map(([key,path])=>({key,path,access:areaAccess(path,meta)})).filter(a=>a.access);
 return <section className="page plan-access-page"><Breadcrumbs items={[{label:t('planAccess.title')}]}/><header className="page-head"><h1>{t('planAccess.title')}</h1></header>
  <div className="panel plan-summary"><div><h2>{t('planAccess.currentPlan')}: <PlanName/></h2><p>{t('planAccess.summary')}</p></div><PlanActions overview={false}/></div>
  <p className="muted">{t('planAccess.explanation')}</p>
  <div className="plan-access-grid">{areas.map(({key,path,access})=>{const kind=access.allowed?'available':accessKind(access.reason);return <article className="panel plan-access-card" key={key} data-access-kind={kind}>
   <div className="plan-access-card-head"><h2>{t(`planAccess.area.${key}`)}</h2><span className={`badge ${access.allowed?'badge--live':'badge--warn'}`}>{t(`planAccess.status.${kind}`)}</span></div>
   <p>{t(access.allowed?'planAccess.availableHint':`planAccess.${kind}Hint`)}</p>
   {access.read.allowed&&!access.create.allowed&&<p className="muted">{t('planAccess.historyHint')}</p>}
   {access.read.allowed&&<Link className="btn" to={path}>{t(access.create.allowed?'planAccess.open':'planAccess.viewRecords')}</Link>}
  </article>;})}</div>
  {!areas.length&&<div className="panel">{t('planAccess.noAreas')}</div>}
  <p className="muted">{t('planAccess.ownerHint')}</p>
 </section>;
}
