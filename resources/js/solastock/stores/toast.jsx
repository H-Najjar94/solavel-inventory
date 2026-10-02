import React, {createContext,useCallback,useContext} from 'react';
import {FeedbackProvider} from '../../shared/feedback/FeedbackProvider';
import {feedback,focusInvalid} from '../../shared/feedback/store';
import {text as feedbackText} from '../../shared/feedback/messages';
const ToastContext=createContext(null);
export function ToastProvider({children}){
 const push=useCallback((message,type='info')=>{
  if(type==='error'||type==='warning')void feedback.error({title:feedbackText(type),message,tone:type});
  else feedback.notify(message,type);
 },[]);
 const failure=useCallback((error,fallback)=>{
  if(error?.feedbackHandled)return;
  if(error?.status===422&&error?.payload?.errors){
   requestAnimationFrame(()=>{
    if(document.querySelector('.field[data-feedback-invalid]'))focusInvalid(error.payload.errors);
    else if(document.querySelector('[data-feedback-validation]'))document.querySelector('[data-feedback-validation]').focus();
    else void feedback.error({title:feedbackText('invalid'),message:Object.values(error.payload.errors).flat().join(' ')});
   });
   return;
  }
  push(error?.message||fallback||feedbackText('failed'),'error');
 },[push]);
 return <ToastContext.Provider value={{push,failure}}><FeedbackProvider>{children}</FeedbackProvider></ToastContext.Provider>;
}
export function useToast(){return useContext(ToastContext)??{push:()=>{},failure:()=>{}};}
