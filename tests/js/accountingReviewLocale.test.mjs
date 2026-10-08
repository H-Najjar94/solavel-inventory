import test from 'node:test';
import assert from 'node:assert/strict';
import { ar, en } from '../../resources/js/solastock/i18n/settingsPages.js';
test('Arabic accounting review overrides English fallbacks', () => {
 for (const key of Object.keys(en).filter(key => key.startsWith('integration.review.'))) {
  assert.match(ar[key], /[\u0600-\u06ff]/, key);
  assert.notEqual(ar[key], en[key], key);
 }
});
