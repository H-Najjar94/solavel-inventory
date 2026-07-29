import { dictionaries } from '../resources/js/solastock/i18n/index.js';

const enKeys = Object.keys(dictionaries.en).sort();
const arKeys = Object.keys(dictionaries.ar).sort();
const missingArabic = enKeys.filter((key) => !(key in dictionaries.ar));
const missingEnglish = arKeys.filter((key) => !(key in dictionaries.en));
const invalid = [...new Set([...enKeys, ...arKeys])].filter((key) => {
    const en = dictionaries.en[key];
    const ar = dictionaries.ar[key];
    return typeof en !== 'string' || typeof ar !== 'string' || en.trim() === '' || ar.trim() === '';
});

if (missingArabic.length || missingEnglish.length || invalid.length) {
    console.error(JSON.stringify({ missingArabic, missingEnglish, invalid }, null, 2));
    process.exit(1);
}

console.log(JSON.stringify({
    result: 'passed',
    englishKeys: enKeys.length,
    arabicKeys: arKeys.length,
    missingArabic: 0,
    missingEnglish: 0,
    invalid: 0,
}));
