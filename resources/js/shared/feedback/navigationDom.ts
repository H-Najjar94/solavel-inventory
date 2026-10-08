import {feedback} from './store';
/** User navigation clears stale outcomes; programmatic save redirects can retain their success notice. */
export function bindFeedbackNavigation(){
 const click=(event:MouseEvent)=>{
  if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
  const link=event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null;
  if(!link||link.target==='_blank'||link.hasAttribute('download')||link.closest('[data-feedback-confirm],[data-confirm]'))return;
  const target=new URL(link.href,location.href);
  if(!['http:','https:'].includes(target.protocol))return;
  if(target.origin!==location.origin||target.pathname!==location.pathname||target.search!==location.search)feedback.navigate();
 };
 const back=()=>feedback.navigate();
 document.addEventListener('click',click,true);window.addEventListener('popstate',back);
 return()=>{document.removeEventListener('click',click,true);window.removeEventListener('popstate',back);};
}
