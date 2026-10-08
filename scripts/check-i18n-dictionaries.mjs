import { dictionaries } from '../resources/js/solastock/i18n/index.js';
import fs from 'node:fs';
import path from 'node:path';

const enKeys = Object.keys(dictionaries.en).sort();
const arKeys = Object.keys(dictionaries.ar).sort();
const missingArabic = enKeys.filter((key) => !(key in dictionaries.ar));
const missingEnglish = arKeys.filter((key) => !(key in dictionaries.en));
const invalid = [...new Set([...enKeys, ...arKeys])].filter((key) => {
    const en = dictionaries.en[key];
    const ar = dictionaries.ar[key];
    return typeof en !== 'string' || typeof ar !== 'string' || en.trim() === '' || ar.trim() === '';
});
const sourceRoot = new URL('../resources/js/solastock/', import.meta.url).pathname;
const sourceFiles = [];
function collect(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const target = path.join(directory, entry.name);
        if (entry.isDirectory()) collect(target);
        else if (/\.(?:js|jsx)$/.test(entry.name)) sourceFiles.push(target);
    }
}
collect(sourceRoot);
const referenced = new Set();
for (const file of sourceFiles) {
    const source = fs.readFileSync(file, 'utf8');
    for (const match of source.matchAll(/\b(?:t|tr|text)\(\s*['"]([^'"]+)['"]/g)) {
        referenced.add(match[1]);
    }
}
const missingReferenced = [...referenced].filter((key) => !(key in dictionaries.en) || !(key in dictionaries.ar)).sort();

if (missingArabic.length || missingEnglish.length || invalid.length || missingReferenced.length) {
    console.error(JSON.stringify({ missingArabic, missingEnglish, invalid, missingReferenced }, null, 2));
    process.exit(1);
}

console.log(JSON.stringify({
    result: 'passed',
    englishKeys: enKeys.length,
    arabicKeys: arKeys.length,
    missingArabic: 0,
    missingEnglish: 0,
    invalid: 0,
    referencedKeys: referenced.size,
    missingReferenced: 0,
}));
