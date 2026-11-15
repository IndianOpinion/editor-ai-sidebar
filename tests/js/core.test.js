'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { core, makeEditor, action } = require('./helpers.js');

test('canonical JSON and SHA-256 match the shared vectors and node:crypto', () => {
	const vectors = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'hash-vectors.json'), 'utf8'));
	assert.ok(vectors.length > 10);
	for (const vector of vectors) {
		assert.equal(core.canonical(vector.input), vector.canonical);
		assert.equal(core.sha256Hex(vector.canonical), vector.sha256);
		assert.equal(crypto.createHash('sha256').update(vector.canonical, 'utf8').digest('hex'), vector.sha256);
	}
	for (const length of [0, 1, 55, 56, 63, 64, 65, 1000]) {
		const text = 'ಕ'.repeat(length);
		assert.equal(core.sha256Hex(text), crypto.createHash('sha256').update(text, 'utf8').digest('hex'));
	}
});

test('T15 groups in the fixed order and hides empty groups', () => {
	const list = [
		action({ id: 'p/other', group: 'other', label: 'O' }),
		action({ id: 'p/h2', group: 'headlines', label: 'B', order: 5 }),
		action({ id: 'p/h1', group: 'headlines', label: 'A', order: 5 }),
		action({ id: 'p/s', group: 'summary', label: 'S', order: 99 })
	];
	const groups = core.groupActions(list);
	assert.deepEqual(groups.map((g) => g.group), ['summary', 'headlines', 'other']);
	assert.deepEqual(groups[1].actions.map((a) => a.id), ['p/h1', 'p/h2']);
});

test('T34 poll schedule doubles from the suggested delay to 15 s and stops at max_wait', () => {
	assert.deepEqual(core.pollSchedule(3, 60), [3, 6, 12, 15, 15]);
	assert.deepEqual(core.pollSchedule(1, 20), [2, 4, 8]);
	assert.deepEqual(core.pollSchedule(30, 100), [15, 15, 15, 15, 15, 15]);
	assert.equal(core.pollSchedule(2, 600).reduce((a, b) => a + b, 0) <= 600, true);
	assert.deepEqual(core.pollSchedule(5, 4), []);
});

test('T24 context carries the unsaved state and only declared needs', () => {
	const ed = makeEditor({ title: 'Typed but not saved', excerpt: 'E', featured: 7 });
	const context = core.buildContext(action({ needs: ['title', 'featured_media', 'language'] }), ed);
	assert.deepEqual(context, { title: 'Typed but not saved', featured_media: 7, language: 'en-GB' });
	const text = core.buildContext(action({ needs: ['text'] }), ed);
	assert.deepEqual(text, { text: 'Hello world' });
});

test('T21 selection context holds only the listed attributes', () => {
	const ed = makeEditor({
		blocks: [{ clientId: 'img-1', name: 'core/image', attributes: { id: 5, url: 'u', alt: '', caption: 'secret', style: { a: 1 } } }],
		selected: ['img-1']
	});
	const alt = action({ needs: ['selection'], selection: ['core/image'], selection_attributes: ['id', 'alt', 'style'] });
	assert.deepEqual(core.buildContext(alt, ed), {
		selection: { name: 'core/image', client_id: 'img-1', attributes: { id: 5, alt: '' } }
	});
});

test('T20 selection state needs exactly one block of a listed type', () => {
	const ed = makeEditor({
		blocks: [
			{ clientId: 'p1', name: 'core/paragraph', attributes: {} },
			{ clientId: 'i1', name: 'core/image', attributes: {} },
			{ clientId: 'i2', name: 'core/image', attributes: {} }
		]
	});
	const alt = action({ selection: ['core/image'] });
	assert.equal(core.selectionState(alt, ed).ok, false);
	ed.state.selected = ['p1'];
	assert.equal(core.selectionState(alt, ed).ok, false);
	ed.state.selected = ['i1', 'i2'];
	assert.equal(core.selectionState(alt, ed).ok, false);
	ed.state.selected = ['i2'];
	assert.equal(core.selectionState(alt, ed).ok, true);
	assert.deepEqual(core.blockLabel(ed, ed.getBlock('i2')), { title: 'Image', index: 2, total: 2 });
});

test('T44 grapheme counting counts user-perceived characters', () => {
	assert.equal(core.graphemeCount('नमस्ते'), 3);
	assert.equal(core.graphemeCount('👩‍💻'), 1);
	assert.equal(core.graphemeCount('abc'), 3);
	assert.ok('नमस्ते'.length > core.graphemeCount('नमस्ते'));
});

test('escapeHtml escapes provider text before it enters block content', () => {
	assert.equal(core.escapeHtml('<img src=x onerror="alert(1)"> & co'), '&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; co');
});

test('error messages tell the editor what to do next', () => {
	const { t } = require('./helpers.js');
	assert.equal(core.errorMessage({ code: 'rest_cookie_invalid_nonce', data: { status: 403 } }, t), t.session);
	assert.equal(core.errorMessage({ code: 'x', data: { status: 401 } }, t), t.session);
	assert.equal(core.errorMessage({ code: 'job_gone', data: { status: 404 } }, t), t.gone);
	assert.equal(core.errorMessage({ code: 'rate_limited', data: { retry_after: 42 } }, t), 'Too many runs in a short time. Try again in 42 seconds.');
	assert.equal(core.errorMessage({ code: 'quota', message: 'Provider says no.' }, t), 'Provider says no.');
	assert.equal(core.errorMessage({ code: 'fetch_error' }, t), t.network);
	assert.equal(core.errorMessage(null, t), t.generic);
});
