import {useEffect} from 'react';
import {useLocation} from 'react-router-dom';
import {feedback} from '../../shared/feedback/store';
/** Programmatic save redirects retain their success notice; shared link/back handling clears old notices. */
export function FeedbackNavigation(){const {pathname,search}=useLocation();useEffect(()=>{feedback.navigate({preserveNotices:true});},[pathname,search]);return null;}
