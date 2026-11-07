import {test} from 'node:test';
import assert from 'node:assert/strict';
import {nextSort, sortRows} from '../../resources/js/solastock/utils/tableSort.js';

test('sort cycles ascending, descending, then back to default', () => {
    let s = {key: null, dir: 'asc'};
    s = nextSort(s, 'date'); assert.deepEqual(s, {key: 'date', dir: 'asc'});
    s = nextSort(s, 'date'); assert.deepEqual(s, {key: 'date', dir: 'desc'});
    s = nextSort(s, 'date'); assert.deepEqual(s, {key: null, dir: 'asc'});
    s = nextSort({key: 'date', dir: 'desc'}, 'name'); assert.deepEqual(s, {key: 'name', dir: 'asc'});
});

test('client sort compares numbers numerically and puts empty values last', () => {
    const rows = [{n: '10'}, {n: '9'}, {n: null}, {n: '100'}];
    assert.deepEqual(sortRows(rows, {key: 'n', dir: 'asc'}).map((r) => r.n), ['9', '10', '100', null]);
    assert.deepEqual(sortRows(rows, {key: 'n', dir: 'desc'}).map((r) => r.n), ['100', '10', '9', null]);
});

test('client sort orders text with locale compare and is stable', () => {
    const rows = [{a: 'b', i: 1}, {a: 'A', i: 2}, {a: 'a', i: 3}];
    assert.deepEqual(sortRows(rows, {key: 'a', dir: 'asc'}, {}, 'en').map((r) => r.i), [2, 3, 1]);
    assert.equal(sortRows(rows, {key: null, dir: 'asc'}), rows);
});
