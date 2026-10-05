import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { diagnosticReason } from '../../resources/js/solastock/services/connectionDiagnostics.js';
import { en, ar } from '../../resources/js/solastock/i18n/settingsPages.js';
test('blocked established connection explains worker failure without replacing readiness', () => {
 const status={readiness:{state:'CONNECTION_BLOCKED',blockers:['sync_worker_unavailable']},summary:{reason:'sync_worker_unavailable'}};
 assert.equal(diagnosticReason(status),'worker');
 assert.equal(status.readiness.state,'CONNECTION_BLOCKED');
 assert.equal(diagnosticReason({readiness:{state:'CONNECTED_READY',blockers:[]}}),'ready');
 assert.equal(diagnosticReason({summary:{reason:'sync_errors'}}),'delivery');
 assert.equal(diagnosticReason({readiness:{blockers:['access_required']}}),'access');
 assert.equal(diagnosticReason({summary:{reason:'internal_unknown_reason'}}),'review');
});
test('all diagnostic text has actual English and Arabic translations',()=>{
 for(const key of Object.keys(en).filter(k=>k.startsWith('integration.diagnostics.'))){assert.match(ar[key],/[\u0600-\u06ff]/,key);assert.notEqual(en[key],ar[key]);}
});
test('review panel has its own visibility and focus while action gates retain native readiness',()=>{
 const source=readFileSync(new URL('../../resources/js/solastock/pages/IntegrationSettingsPage.jsx',import.meta.url),'utf8');
 assert.match(source,/const connectionActivated = s\?\.readiness\?\.state === 'CONNECTED_READY'/);
 assert.match(source,/showDiagnostics && s && <section ref=\{diagnosticRef\} tabIndex=\{-1\}/);
 assert.match(source,/setTab\('status'\); setShowDiagnostics\(true\)/);
 assert.match(source,/diagnosticRef\.current\?\.focus/);
 const panel=source.split('showDiagnostics && s && <section')[1].split('</section>')[0];
 assert.doesNotMatch(panel,/connectIntegration|configureIntegration|rotateIntegration|openSetup/);
 assert.match(panel,/status\.refetch\(\)/);
});
