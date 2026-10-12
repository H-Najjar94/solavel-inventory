import fs from 'node:fs';
import assert from 'node:assert/strict';
import { dictionaries } from '../resources/js/solastock/i18n/index.js';
const locales = ['fr','ru','ur','de','es','zh','pt','tr','it'];
const tokens = value => [...value.matchAll(/(?<!\w):[A-Za-z_][A-Za-z_0-9]*|\{[A-Za-z_][\w.]*\}|%(?:\d+\$)?[sdif]/g)].map(m=>m[0]).sort();
const keys=Object.keys(dictionaries.en).sort();
const brands = value => [...value.matchAll(/SolaBooks|SolaProjects|SolaCount|SolaStock|SolaHR|SolaVel|Solavel/g)].map(m=>m[0]).sort();
let errors=[];
for(const locale of locales){
 const catalog=JSON.parse(fs.readFileSync(new URL(`../resources/js/solastock/i18n/locales/${locale}.json`,import.meta.url)));
 assert.deepEqual(Object.keys(catalog).sort(), keys, `${locale}: every UI key must exist`);
 for(const key of keys){
  if(typeof catalog[key] !=='string'||!catalog[key].trim()) errors.push({locale,key,error:'empty'});
  if(JSON.stringify(brands(dictionaries.en[key])) !== JSON.stringify(brands(catalog[key]))) errors.push({locale,key,source:dictionaries.en[key],target:catalog[key],error:'brands'});
  if(JSON.stringify(tokens(dictionaries.en[key]))!==JSON.stringify(tokens(catalog[key]))) errors.push({locale,key,source:dictionaries.en[key],target:catalog[key],error:'variables'});
 }
 console.log(`${locale}: ${keys.length} UI keys`);
}
if(errors.length){console.error(JSON.stringify(errors,null,2));process.exitCode=1;}else console.log('All locales cover the complete UI and preserve variables.');
