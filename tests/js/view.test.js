'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { t, makeEditor, makeApi, makeController, action, envelope, renderTree, texts, find, buttons, settle, walk } = require('./helpers.js');

const LIST = 'GET /editor-ai-sidebar/v1/actions';
const RUN = 'POST /editor-ai-sidebar/v1/actions/test/headline/run';

async function shown(list, result, options = {}) {
	const api = makeApi({ [LIST]: () => list, [RUN]: () => result });
	const s = makeController(Object.assign({ api }, options));
	await s.ctrl.open();
	await settle();
	if (result) {
		await s.ctrl.run(list[0].id);
		await settle();
	}
	return s;
}

test('T16 empty state: settings link only for admins', async () => {
	for (const canManage of [false, true]) {
		const s = makeController({ config: { hasActions: false, canManage } });
		await s.ctrl.open();
		const tree = renderTree(s);
		assert.ok(texts(tree).includes(t.noneInstalled));
		const links = find(tree, (n) => n.type === 'a' && n.props.href === 'https://example.test/help');
		assert.equal(links.length, canManage ? 1 : 0);
	}
});

test('T17 actions exist but none for this kind of content', async () => {
	const s = await shown([], null);
	assert.ok(texts(renderTree(s)).includes(t.noneForType));
});

test('T15 groups render in the fixed order; empty groups hidden', async () => {
	const s = await shown([
		action({ id: 'p/o', group: 'other', label: 'Other one' }),
		action({ id: 'p/s', group: 'summary', label: 'Summary one' })
	], null);
	const panels = find(renderTree(s), (n) => n.type === 'PanelBody');
	assert.deepEqual(panels.map((p) => p.props.title), ['Summary', 'Other']);
});

test('T18 an unavailable action is a disabled button with its reason', async () => {
	const s = await shown([action({ available: false, reason: 'Set an API key first.' })], null);
	const tree = renderTree(s);
	const run = buttons(tree, 'Suggest a headline')[0];
	assert.equal(run.props.disabled, true);
	assert.ok(texts(tree).includes('Set an API key first.'));
});

test('T20 a selection-bound action is disabled until an image is selected', async () => {
	const ed = makeEditor({ blocks: [{ clientId: 'i1', name: 'core/image', attributes: {} }, { clientId: 'p1', name: 'core/paragraph', attributes: {} }] });
	const alt = action({ id: 'test/alt', group: 'alt_text', label: 'Alt', selection: ['core/image'], needs: ['selection'] });
	const s = await shown([alt], null, { ed });
	let tree = renderTree(s);
	assert.equal(buttons(tree, 'Alt')[0].props.disabled, true);
	assert.ok(texts(tree).includes('Select an image block'));
	ed.state.selected = ['p1'];
	assert.equal(buttons(renderTree(s), 'Alt')[0].props.disabled, true);
	ed.state.selected = ['i1'];
	tree = renderTree(s);
	assert.equal(buttons(tree, 'Alt')[0].props.disabled, false);
	assert.ok(!texts(tree).includes('Select an image block'));
});

test('T42 provider text renders as literal text, never as HTML', async () => {
	const evil = '<img src=x onerror="alert(1)">';
	const s = await shown([action({ label: evil, description: evil })], envelope({
		items: [{ id: 'a', type: 'text', value: evil, notes: [{ level: 'warning', text: evil }], score: 1, score_label: evil, lang: null, dir: null }],
		notes: [{ level: 'info', text: evil }]
	}));
	const tree = renderTree(s);
	assert.ok(texts(tree).filter((x) => x.includes(evil)).length >= 4);
	walk(tree, (n) => {
		if (n && typeof n === 'object' && n.props) {
			assert.equal(n.props.dangerouslySetInnerHTML, undefined);
		}
	});
});

test('T43 every item type and both layouts render, with score, notes, lang and dir', async () => {
	const fieldsAction = action({ result: { layout: 'single', item_type: 'fields', fields: { title: { label: 'Meta title', limit: 60 }, description: { label: 'Meta description', limit: 160 } } } });
	let s = await shown([fieldsAction], envelope({ items: [{ id: 'f', type: 'fields', value: { title: 'T', description: 'D' }, notes: [] }] }));
	let tree = renderTree(s);
	assert.deepEqual(find(tree, (n) => n.type === 'dt').map((n) => texts(n).join('')), ['Meta title', 'Meta description']);
	assert.deepEqual(find(tree, (n) => n.type === 'dd').map((n) => texts(n).join('')), ['T', 'D']);

	s = await shown([action({ result: { layout: 'single', item_type: 'list', fields: {} } })], envelope({ items: [{ id: 'l', type: 'list', value: ['One', 'Two'], notes: [] }] }));
	tree = renderTree(s);
	const list = find(tree, (n) => n.type === 'ol' && n.props.className === 'eas-value eas-list')[0];
	assert.deepEqual(find(list, (n) => n.type === 'li').map((n) => texts(n).join('')), ['One', 'Two']);

	s = await shown([action({ result: { layout: 'choices', item_type: 'text', fields: {} } })], envelope({
		layout: 'choices',
		items: [
			{ id: 'a', type: 'text', value: 'First', notes: [{ level: 'info', text: 'Fine' }], score: 0.8, score_label: 'Clarity', lang: 'hi', dir: 'ltr' },
			{ id: 'b', type: 'text', value: 'دوم', notes: [], score: null, score_label: null, lang: 'ur', dir: 'rtl' }
		]
	}));
	tree = renderTree(s);
	const items = find(tree, (n) => n.props && n.props['data-item']);
	assert.equal(items.length, 2);
	assert.equal(items[0].type, 'li', 'choices render as a list');
	assert.ok(texts(items[0]).includes('Clarity: 0.8'));
	assert.ok(texts(items[0]).includes('Fine'));
	// T72 lang on the element; T71 dir rtl in an ltr interface.
	assert.equal(items[0].props.lang, 'hi');
	assert.equal(items[1].props.lang, 'ur');
	assert.equal(items[1].props.dir, 'rtl');
	assert.equal(buttons(tree, t.approve).length, 2);
});

test('T44 Edit only on editable actions; the counter counts graphemes', async () => {
	let s = await shown([action({ editable: false })], envelope());
	assert.equal(buttons(renderTree(s), t.edit).length, 0);

	const fields = action({ editable: true, result: { layout: 'single', item_type: 'fields', fields: { title: { label: 'Meta title', limit: 5 } } } });
	s = await shown([fields], envelope({ items: [{ id: 'f', type: 'fields', value: { title: 'नमस्ते' }, notes: [] }] }));
	assert.equal(buttons(renderTree(s), t.edit).length, 1);
	s.ctrl.startEdit('test/headline', 'f');
	const tree = renderTree(s);
	assert.equal(find(tree, (n) => n.type === 'TextareaControl').length, 1);
	assert.ok(texts(tree).includes('3 of 5 characters'));
});

test('T45 warnings show and do not disable Approve', async () => {
	const s = await shown([action()], envelope({ items: [{ id: 'a', type: 'text', value: 'x', notes: [{ level: 'warning', text: 'Check the facts.' }] }] }));
	const tree = renderTree(s);
	assert.ok(texts(tree).includes('Warning: Check the facts.'));
	assert.equal(buttons(tree, t.approve)[0].props.disabled, false);
});

test('T59 view-only: Approve disabled, Copy shown, with the explanation', async () => {
	const s = await shown([action({ can_apply: false })], envelope());
	const tree = renderTree(s);
	assert.equal(buttons(tree, t.approve)[0].props.disabled, true);
	assert.equal(buttons(tree, t.copy).length, 1);
	assert.ok(texts(tree).includes(t.viewOnly));
});

test('T73 long items are collapsed with Show all', async () => {
	const long = 'अ'.repeat(2001);
	const s = await shown([action()], envelope({ items: [{ id: 'a', type: 'text', value: long, notes: [] }] }));
	let tree = renderTree(s);
	assert.equal(find(tree, (n) => n.props && n.props.className === 'eas-value is-collapsed').length, 1);
	assert.ok(texts(tree).includes(long), 'the full value is in the tree');
	buttons(tree, t.showAll)[0].props.onClick();
	tree = renderTree(s);
	assert.equal(find(tree, (n) => n.props && n.props.className === 'eas-value is-collapsed').length, 0);
});

test('T22 the result header names the block it was made for', async () => {
	const ed = makeEditor({
		blocks: [
			{ clientId: 'i1', name: 'core/image', attributes: {} },
			{ clientId: 'i2', name: 'core/image', attributes: {} },
			{ clientId: 'i3', name: 'core/image', attributes: {} }
		],
		selected: ['i3']
	});
	const alt = action({ id: 'test/headline', selection: ['core/image'], needs: ['selection'] });
	const s = await shown([alt], envelope(), { ed });
	ed.state.selected = ['i1'];
	assert.ok(texts(renderTree(s)).includes('Image 3 of 3'));
});

test('T74 the expiry line counts down from expires_in', async () => {
	const s = await shown([action()], envelope({ expires_in: 1800 }));
	assert.ok(texts(renderTree(s)).includes('Expires in 30 min'));
	s.advance(29.5 * 60 * 1000);
	assert.ok(texts(renderTree(s)).includes('Expires in less than a minute'));
});

test('busy actions show Cancel; a live region announces results', async () => {
	const api = makeApi({ [LIST]: () => [action()], [RUN]: () => new Promise(() => {}) });
	const s = makeController({ api });
	await s.ctrl.open();
	await settle();
	s.ctrl.run('test/headline');
	const tree = renderTree(s);
	assert.equal(buttons(tree, t.cancel).length, 1);
	assert.equal(buttons(tree, 'Suggest a headline')[0].props.disabled, true);
	const live = find(tree, (n) => n.props && n.props['aria-live'] === 'polite');
	assert.equal(live.length, 1);
});
