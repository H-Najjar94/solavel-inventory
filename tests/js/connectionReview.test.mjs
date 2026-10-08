import test from 'node:test';
import assert from 'node:assert/strict';
import { prepareConnectionReview, cutoffInputValue, accountSelectionDirty, proposedCutoffValue, validCutoffValue } from '../../resources/js/solastock/services/connectionReview.js';
const draft = (state, lock_version = 1, extra = {}) => ({run_uuid:'test',state,lock_version,identity:{client_id:87,central_organization_id:165},...extra});
const date = '2026-10-06T13:08';
test('Continue sequences only metadata with returned optimistic locks', async () => {
 const calls=[]; const api={};
 for(const [method,state] of [['requestIntegrationWizardSnapshot','snapshot_required'],['freezeIntegrationWizardSnapshot','cutoff_review'],['reviewIntegrationWizardCutoff','preview_ready']]) api[method]=async(uuid,payload)=>{calls.push([method,payload]);return {data:draft(state,payload.expected_lock_version+1,{cutoff_at:date})};};
 const result=await prepareConnectionReview(api,draft('decisions_complete'),date,'Choose date');
 assert.equal(result.state,'preview_ready'); assert.deepEqual(calls.map(c=>c[1].expected_lock_version),[1,2,3]); assert.equal(calls[2][1].unexplained_variance,'0.00');
});
test('saved reviewed date continues without any writes or resetting approvals',async()=>{
 const initial=draft('owner_approved',9,{cutoff_at:'2026-10-06 13:08:00',owner_approved_at:'saved'});
 assert.equal(await prepareConnectionReview({},initial,date,'Date'),initial);
});
test('changed saved date is reviewed with current lock',async()=>{
 const calls=[]; const api={reviewIntegrationWizardCutoff:async(uuid,payload)=>{calls.push(payload);return draft('preview_ready',10,{cutoff_at:payload.cutoff_at});}};
 await prepareConnectionReview(api,draft('owner_approved',9,{cutoff_at:'2026-10-05 13:08:00'}),date,'Date');assert.equal(calls.length,1);assert.equal(calls[0].expected_lock_version,9);
});
test('partial preparation retry resumes canonical state without repeating request',async()=>{
 let requests=0;const api={requestIntegrationWizardSnapshot:async()=>{requests++;return draft('snapshot_required',2);},freezeIntegrationWizardSnapshot:async()=>{throw Error('temporarily unavailable');}};
 await assert.rejects(prepareConnectionReview(api,draft('decisions_complete'),date,'Date'),/temporarily/);
 api.freezeIntegrationWizardSnapshot=async()=>draft('cutoff_review',3); api.reviewIntegrationWizardCutoff=async()=>draft('preview_ready',4);
 await prepareConnectionReview(api,draft('snapshot_required',2),date,'Date');assert.equal(requests,1);
});
test('wrong organization and stale responses stop preparation',async()=>{
 for(const next of [draft('snapshot_required',0),draft('snapshot_required',2,{identity:{client_id:87,central_organization_id:177}})]) {
 await assert.rejects(prepareConnectionReview({requestIntegrationWizardSnapshot:async()=>next},draft('decisions_complete'),date,'Refresh review'),/Refresh review/);
 }
});
test('blank date and connected runs cannot silently advance or write',async()=>{
 await assert.rejects(prepareConnectionReview({},draft('decisions_complete'),'','Choose date'),/Choose date/);
 await assert.rejects(prepareConnectionReview({},draft('connected'),date,'Review locked'),/Review locked/);
});
test('server date retains organization wall time and only changed selections are dirty',()=>{
 assert.equal(cutoffInputValue('2026-10-06 13:08:00'),date);assert.equal(accountSelectionDirty({id:2},{id:'2'}),false);assert.equal(accountSelectionDirty({id:3},{id:2}),true);assert.equal(accountSelectionDirty(undefined,{id:2}),false);
});

test('fresh default uses device-local minute without persisting and saved canonical wins',()=>{
 const localDate = new Date(2026, 9, 6, 16, 42, 55);
 assert.equal(proposedCutoffValue(null,localDate),'2026-10-06T16:42');
 assert.equal(proposedCutoffValue('2025-01-02 09:03:00',localDate),'2025-01-02T09:03');
 assert.equal(validCutoffValue(''),false);assert.equal(validCutoffValue('2026-10-06T16:42'),true);
});
