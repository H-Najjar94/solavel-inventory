// Q12.6 UI (G6 r2) — static source/dictionary checks (no DOM renderer installed in this repo's node test lane).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { dictionaries } from '../../resources/js/solastock/i18n/index.js';

const page = fs.readFileSync(new URL('../../resources/js/solastock/pages/AdjustmentDetailPage.jsx', import.meta.url), 'utf8');
const api = fs.readFileSync(new URL('../../resources/js/solastock/services/api.js', import.meta.url), 'utf8');

test('permission label exists in en and ar', () => {
    for (const locale of ['en', 'ar']) {
        const label = dictionaries[locale]['settings.permission.inventory.adjustments.write_off_inactive'];
        assert.equal(typeof label, 'string');
        assert.ok(label.trim().length > 0);
    }
    assert.notEqual(dictionaries.en['settings.permission.inventory.adjustments.write_off_inactive'], dictionaries.ar['settings.permission.inventory.adjustments.write_off_inactive']);
});

test('draft view shows the write-off warnings and an approve button gated by the server can_approve flag', () => {
    assert.match(page, /data\?\.inactive_write_off/);
    assert.match(page, /writeOff\.warnings/);
    assert.match(page, /writeOff\.can_approve\s*&&\s*<button/);
    assert.match(page, /api\.approveInactiveWriteOff/);
    assert.match(api, /approveInactiveWriteOff:\s*\(id\)\s*=>\s*request\(`\/adjustments\/\$\{id\}\/approve-inactive-write-off`,\s*\{\s*method:\s*'POST'/);
    for (const key of ['title', 'pending', 'approved', 'approve', 'approvedToast']) {
        assert.ok(dictionaries.en[`adjustment.inactiveWriteOff.${key}`] && dictionaries.ar[`adjustment.inactiveWriteOff.${key}`], key);
    }
});
