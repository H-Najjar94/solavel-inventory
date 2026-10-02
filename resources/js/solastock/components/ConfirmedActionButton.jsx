import React,{useState} from 'react';
import {WorkflowConfirmation} from '../../shared/feedback/WorkflowConfirmation';
/** Presentation wrapper: callers retain permissions, payloads and outcome handling. */
export function ConfirmedActionButton({title,triggerTitle,message,action,onConfirm,children,...buttonProps}) {
    const [open,setOpen]=useState(false),[busy,setBusy]=useState(false);
    return <><button title={triggerTitle} aria-label={typeof title==='string'?title:undefined} {...buttonProps} type="button" onClick={()=>setOpen(true)}>{children}</button>{open&&<WorkflowConfirmation title={title} action={action} busy={busy} onClose={()=>setOpen(false)} onConfirm={async()=>{
        setBusy(true);try{const result=await onConfirm();if(result!==false)setOpen(false);}finally{setBusy(false);}
    }}>{message}</WorkflowConfirmation>}</>;
}
