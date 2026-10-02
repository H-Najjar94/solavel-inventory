import React, {createContext,useCallback,useContext} from 'react';
import {FeedbackProvider} from '../../shared/feedback/FeedbackProvider';
import {feedback} from '../../shared/feedback/store';
import {text} from '../../shared/feedback/messages';
const ToastContext=createContext(null);
export function ToastProvider({children}){
 const push=useCallback((message,type='info')=>{
  if(type==='error'||type==='warning')void feedback.error({title:text(type),message,tone:type});
  else feedback.notify(message,type);
 },[]);
 return <ToastContext.Provider value={{push}}><FeedbackProvider>{children}</FeedbackProvider></ToastContext.Provider>;
}
export function useToast(){return useContext(ToastContext)??{push:()=>{}};}
