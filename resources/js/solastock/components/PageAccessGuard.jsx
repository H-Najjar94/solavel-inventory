import React from 'react';
import {Link,useLocation} from 'react-router-dom';
import {useMeta} from '../stores/meta.jsx';
import {routeAccess} from '../services/uiAccess.mjs';
import {useI18n} from '../i18n/context.jsx';
import {EmptyState,Skeleton} from './ui.jsx';
export default function PageAccessGuard({children}){
 const meta=useMeta();const location=useLocation();const {t}=useI18n();
 if(meta.isPlaceholderData)return <section className="page"><Skeleton rows={4}/></section>;
 if(meta.isError)return <section className="page"><EmptyState title={t('uiAccess.checkFailed')} action={<button type="button" className="btn" onClick={()=>meta.refetch()}>{t('partners.common.retry')}</button>}/></section>;
 const access=routeAccess(location.pathname,meta);
 if(!access.allowed)return <section className="page index-page access-unavailable"><header className="page-head"><h1>{t('uiAccess.title')}</h1></header><EmptyState title={t(access.reason==='permission_denied'?'uiAccess.roleTitle':'uiAccess.planTitle')} hint={t(access.reason==='permission_denied'?'uiAccess.roleHint':'uiAccess.planHint')} action={<Link className="btn" to="/dashboard">{t('uiAccess.dashboard')}</Link>}/></section>;
 return children;
}
