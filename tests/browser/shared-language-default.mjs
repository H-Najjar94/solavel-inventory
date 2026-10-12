// Browser contract test with HTTP fixtures; server persistence has separate feature tests.
import {chromium} from '@playwright/test';
import fs from 'node:fs';
const script=fs.readFileSync(new URL('../../resources/js/solavel-language-default.js', import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.CHROMIUM_EXECUTABLE_PATH} : {}),args:['--no-sandbox']});
const context=await browser.newContext();let account={remember_language:null,locale:'ar'},writes=[];
await context.route('https://**/*',async route=>{
 const request=route.request(),url=new URL(request.url());
 if(url.pathname.endsWith('/preferences/sync'))return route.fulfill({json:account});
 if(url.pathname.endsWith('/preferences/theme')){const b=request.postDataJSON();if(typeof b.remember_language!=='boolean')throw Error('Invalid payload');account.remember_language=b.remember_language;if(b.locale)account.locale=b.locale;writes.push({host:url.host,...b});return route.fulfill({json:{saved:true}});}
 return route.fulfill({contentType:'text/html',body:`<html lang="ar"><head><meta name="csrf-token" content="test"></head><body><button class="ss-pref-switch" role="switch" aria-checked="false" onclick="var v=localStorage.getItem('solavel_save_default_language')!=='1';localStorage.setItem('solavel_save_default_language',v?'1':'0');this.setAttribute('aria-checked',v)">Default language</button><script>document.querySelector('button').setAttribute('aria-checked',localStorage.getItem('solavel_save_default_language')==='1');window.addEventListener('storage',()=>document.querySelector('button').setAttribute('aria-checked',localStorage.getItem('solavel_save_default_language')==='1'));</script><script>${script}</script></body></html>`});
});
const urls=['https://solacount.com/dashboard','https://solavel.com/hr/dashboard','https://solavel.com/inventory/dashboard'];const pages=[];
for(const u of urls){const p=await context.newPage();await p.goto(u);pages.push(p);}
let assertions=0;
// The pre-existing checked preference on SolaCount must seed the shared account.
await pages[0].evaluate(()=>localStorage.setItem('solavel_save_default_language','1'));await pages[0].reload();
await pages[0].waitForFunction(()=>document.querySelector('button').getAttribute('aria-checked')==='true');
for(let i=1;i<3;i++){await pages[i].reload();await pages[i].waitForFunction(()=>document.querySelector('button').getAttribute('aria-checked')==='true');assertions++;}
for(let source=0;source<3;source++){
 for(const expected of [false,true]){
  await pages[source].bringToFront();await pages[source].reload();await pages[source].waitForFunction(v=>document.querySelector('button').getAttribute('aria-checked')===String(v),!expected);
  const saved=pages[source].waitForResponse(r=>r.url().endsWith('/preferences/theme')&&r.request().method()==='POST');
  await pages[source].click('button');await saved;
  if(account.remember_language!==expected)throw Error('Account value mismatch');
  for(let target=0;target<3;target++)if(target!==source){await pages[target].bringToFront();await pages[target].reload();await pages[target].waitForFunction(v=>localStorage.getItem('solavel_save_default_language')===(v?'1':'0'),expected);assertions++;}
 }
}
console.log(JSON.stringify({crossDomainAssertions:assertions,result:'passed',writes}));await browser.close();
