import { activeWorkflow } from './dialogDom';
import React, {useEffect, useRef, useState, useId} from 'react';
import {createPortal} from 'react-dom';
import {text} from './messages';
import './feedback.css';
/** Existing workflow content and typed safeguards remain owned by the caller. */
export function WorkflowConfirmation({title,children,onConfirm,onClose,action,typed,busy=false,disabledConfirm=false}:{title:React.ReactNode;children?:React.ReactNode;onConfirm:()=>void;onClose:()=>void;action:string;typed?:string;busy?:boolean;disabledConfirm?:boolean}) {
 const ref=useRef<HTMLDialogElement>(null), inline=useRef<HTMLElement>(null), submitted=useRef(false), trigger=useRef(document.activeElement as HTMLElement|null);
 const [container]=useState<HTMLElement|null>(activeWorkflow);
 const [value,setValue]=useState('');const id=useId();
 useEffect(()=>{const node=ref.current;if(container)inline.current?.querySelector<HTMLButtonElement>('button')?.focus();else node?.showModal();return()=>{node?.close();trigger.current?.isConnected&&trigger.current.focus();};},[container]);
 useEffect(()=>{if(!busy)submitted.current=false;},[busy]);
 const close=()=>{if(!busy)onClose();};
 const confirm=()=>{if(busy||disabledConfirm||submitted.current||(typed&&value!==typed))return;submitted.current=true;onConfirm();};
 const keyboard=(e:React.KeyboardEvent<HTMLElement>)=>{
  if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close();return;}
  if(e.key!=='Tab')return;const nodes=Array.from(e.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),a[href],select:not(:disabled),textarea:not(:disabled)'));const first=nodes[0],last=nodes[nodes.length-1];
  if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
 };
 const content=<><h2 id={id}>{title}</h2><div id={`${id}-body`}>{children}</div>
 {typed&&<label className="sf-input">{text('typeToConfirm')} <bdi>{typed}</bdi><input value={value} onChange={e=>setValue(e.target.value)} disabled={busy}/></label>}
 <div className="sf-actions"><button autoFocus type="button" onClick={close} disabled={busy}>{text('cancel')}</button><button type="button" className="sf-primary" onClick={confirm} disabled={busy||disabledConfirm||!!typed&&value!==typed}>{busy?text('pending'):action}</button></div></>;
 if(container)return createPortal(<section ref={inline} className="sf-inline" role="alert" aria-labelledby={id} aria-describedby={`${id}-body`} dir={document.documentElement.dir||'ltr'} onKeyDown={keyboard}>{content}</section>,container);
 return <dialog ref={ref} className="sf-dialog" role="alertdialog" aria-labelledby={id} aria-describedby={`${id}-body`} dir={document.documentElement.dir||'ltr'} onCancel={e=>{e.preventDefault();close();}} onKeyDown={keyboard}>{content}</dialog>;
}
