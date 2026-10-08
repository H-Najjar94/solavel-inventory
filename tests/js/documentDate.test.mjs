import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {localDocumentDate} from '../../resources/js/solastock/services/documentDate.js';
const moduleUrl=new URL('../../resources/js/solastock/services/documentDate.js',import.meta.url).href;
for (const [timezone,instant,expected] of [
    ['Asia/Amman','2026-10-07T21:30:00Z','2026-10-08'],
    ['Pacific/Honolulu','2026-10-08T08:30:00Z','2026-10-07'],
]) test(`document default keeps calendar day in ${timezone}`,()=>{
    const result=execFileSync(process.execPath,['--input-type=module','-e',`import {localDocumentDate} from ${JSON.stringify(moduleUrl)};process.stdout.write(localDocumentDate(new Date(${JSON.stringify(instant)})));`],{env:{...process.env,TZ:timezone},encoding:'utf8'});
    assert.equal(result,expected);
});
test('invalid calendar input is not silently normalized',()=>assert.throws(()=>localDocumentDate(new Date('invalid')),RangeError));
