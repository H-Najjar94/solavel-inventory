import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const locales = ['en','ar','fr','ru','ur','de','es','zh','pt','tr','it'];
if (process.env.STOCK_NOTIFICATION_TEST_LOCALE) {
 const locale=process.env.STOCK_NOTIFICATION_TEST_LOCALE;
 const catalog=locale==='ar'?{}:JSON.parse(fs.readFileSync(new URL(`../../resources/js/solastock/i18n/locales/${locale}.json`,import.meta.url)));
 globalThis.window={SOLASTOCK_LOCALE:{locale},SOLASTOCK_TRANSLATIONS:catalog};
 const {localizePurchasingNotification:localize,warehouseNotificationTitles:titles,warehouseNotificationBodies:bodies,warehouseNotificationDirection:direction}=await import('../../resources/js/solastock/components/purchasingNotificationLocale.mjs');
 const {sourceText}=await import('../../resources/js/solastock/i18n/locales.js');
 const fill=(s,p)=>s.replaceAll(':ref','41').replaceAll(':request',`${p}-41`);
 assert.equal(direction(locale),['ar','ur'].includes(locale)?'rtl':'ltr');
 for(const [kinds,en,ar] of bodies) for(const prefix of kinds.split('|')) {
  const title=prefix==='RR'?titles[0]:titles[6];
  const header=`Customer <literal> — عميل · INV-900 · ${prefix}-41`;
  const english=header+'\n'+fill(en,prefix), arabic=header+'\n'+fill(ar,prefix);
  const expected={title:sourceText(...title,locale),body:header+'\n'+fill(sourceText(en,ar,locale),prefix)};
  const current={title:title[0],body:english,localized:{en:{title:title[0],body:english},ar:{title:title[1],body:arabic}}};
  assert.deepEqual(localize(current,locale),expected);
  assert.deepEqual(localize({title:title.join(' — '),body:english+'\n'+fill(ar,prefix)},locale),expected);
  assert.equal(expected.body.split('\n')[0],header);
  if(!['en','ar'].includes(locale)){assert.notEqual(expected.title,title[0]);assert.notEqual(expected.body,english);}
  const custom={title:title[0],body:header+'\nCustomer wrote: '+en};
  assert.equal(localize(custom,locale).body,custom.body);
  const malformed={title:title.join(' — '),body:english+'\nCustom customer note'};
  assert.equal(localize(malformed,locale).body,malformed.body);
 }
 for(const [en,ar] of titles) assert.equal(localize({title:en,body:'Customer note'},locale).title,sourceText(en,ar,locale));
 const unknown={title:'Customer <name>',body:'Free text — no translation'};
 assert.deepEqual(localize(unknown,locale),unknown);
 const native={title:'Original',body:'Original body',localized:{[locale]:{title:'Customer title',body:'Customer body'}}};
 assert.deepEqual(localize(native,locale),{title:'Customer title',body:'Customer body'});
} else {
 for(const locale of locales) test(`warehouse current/legacy purchase, sales, cash and free text: ${locale}`,()=>execFileSync(process.execPath,[import.meta.filename],{env:{...process.env,STOCK_NOTIFICATION_TEST_LOCALE:locale},stdio:'pipe'}));
}
