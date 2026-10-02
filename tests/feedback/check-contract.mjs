import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
const contract = JSON.parse(readFileSync(new URL('./shared-contract.json', import.meta.url)));
for (const [file, expected] of Object.entries(contract)) {
 const source = readFileSync(new URL('../../resources/js/shared/feedback/' + file, import.meta.url));
 assert.equal(createHash('sha256').update(source).digest('hex'), expected, file + ' differs from shared contract');
}
const source = readFileSync(new URL('../../resources/js/shared/feedback/messages.ts', import.meta.url), 'utf8');
const english = source.split('"en": {')[1].split('"ar": {')[0];
const arabic = source.split('"ar": {')[1].split('} as const')[0];
const keys = value => [...value.matchAll(/^\s*"([^"\n]+)":/gm)].map(match => match[1]).sort();
assert.deepEqual(keys(english), keys(arabic));
assert(keys(english).length > 60);
console.log('PASS: shared source hashes and English/Arabic key parity');
