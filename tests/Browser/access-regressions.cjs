const {chromium}=require('/root/solavel-access-20260920/central/node_modules/@playwright/test');
const fs=require('fs'),assert=require('assert/strict'),{execFileSync}=require('child_process');
const evidence='/root/solavel-access-20260920/evidence/regressions',base='http://127.0.0.1:8137';
function fixture(mode){return execFileSync('python3',['-c',`import sqlite3
c=sqlite3.connect('/tmp/solavel_test_regression_browser.sqlite')
u=c.execute("select id from users where email='fresh@access.test'").fetchone()[0]
p=c.execute("select id from projects where slug='inventory'").fetchone()[0]
if '${mode}'=='grant':
 c.execute('delete from user_projects where user_id=? and project_id=?',(u,p));c.execute('insert into user_projects(user_id,organization_id,project_id,is_active) values (?,1,?,1)',(u,p));c.commit()
if '${mode}'=='revoke':c.execute('update user_projects set is_active=0 where user_id=? and project_id=?',(u,p));c.commit()
print(u)
`],{encoding:'utf8'}).trim();}
(async()=>{
 const browser=await chromium.launch({executablePath:'/root/.cache/ms-playwright/chromium-1187/chrome-linux/chrome',args:['--no-sandbox']});const checks=[];
 const context=await browser.newContext(),page=await context.newPage();const uid=fixture('read');
 await page.goto(base+'/__fixture/login/'+uid);
 let response=await page.goto(base+'/dashboard');assert.equal(response.status(),403);assert.equal(await page.locator('#solastock-root').count(),0);assert.match(await page.locator('body').innerText(),/has not been granted/);
 await page.screenshot({path:evidence+'/stock-direct-denied-en.png',fullPage:true});checks.push('Existing Stock session: protected dashboard returns 403 with no SPA root');
 for(const url of ['/api/v1/tenant/status','/api/v1/meta','/api/v1/items']){let r=await page.request.get(base+url);assert.equal(r.status(),403);assert.equal((await r.json()).data,undefined);}
 checks.push('Stock bootstrap and item APIs return no tenant data');
 response=await page.goto(base+'/dashboard?locale=ar');assert.equal(response.status(),403);assert.equal(await page.locator('html').getAttribute('dir'),'rtl');await page.screenshot({path:evidence+'/stock-direct-denied-ar.png',fullPage:true});checks.push('Arabic direct denial renders before any protected UI');
 fixture('grant');
 let release;const hold=new Promise(r=>release=r);let arrived;const waiting=new Promise(r=>arrived=r);
 await page.route('**/inventory/api/v1/tenant/status',async route=>{arrived();await hold;await route.continue({url:route.request().url().replace('/inventory/api/','/api/')});});
 response=await page.goto(base+'/dashboard?locale=en',{waitUntil:'domcontentloaded'});assert.equal(response.status(),200);
 await waiting;assert.match(await page.locator('body').innerText(),/Checking application access/);assert.equal(await page.locator('.app-shell').count(),0);
 await page.screenshot({path:evidence+'/stock-neutral-before-authority.png',fullPage:true});fixture('revoke');release();
 await page.getByRole('heading',{name:'SolaStock access',exact:true}).waitFor();assert.match(await page.locator('body').innerText(),/has not been granted/);assert.equal(await page.locator('.app-shell').count(),0);
 await page.screenshot({path:evidence+'/stock-revoked-before-mount.png',fullPage:true});checks.push('Revocation between HTML and bootstrap: neutral loading changes to denial, dashboard never mounts');
 response=await page.goto(base+'/items');assert.equal(response.status(),403);checks.push('Next direct navigation in the same session is denied immediately');
 fs.writeFileSync(evidence+'/stock-browser-results.json',JSON.stringify({checks},null,2));console.log(JSON.stringify({checks},null,2));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
