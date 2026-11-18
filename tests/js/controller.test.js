'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { core, t, makeEditor, makeApi, makeController, action, envelope, settle } = require('./helpers.js');

const LIST = 'GET /editor-ai-sidebar/v1/actions';
const RUN = 'POST /editor-ai-sidebar/v1/actions/test/headline/run';
const APPLY = 'POST /editor-ai-sidebar/v1/results/' + 'a'.repeat(32) + '/apply';

function setup(list, routes = {}, options = {}) {
	const api = makeApi(Object.assign({ [LIST]: () => list }, routes));
	return makeController(Object.assign({ api }, options));
}

async function opened(list, routes, options) {
	const s = setup(list, routes, options);
	await s.ctrl.open();
	await settle();
	return s;
}

test('T13 constructing makes no request; opening makes exactly one', async () => {
	const s = setup([action()]);
	assert.equal(s.api.requests.length, 0);
	await s.ctrl.open();
	assert.equal(s.api.requests.length, 1);
	assert.equal(s.api.requests[0].path, '/editor-ai-sidebar/v1/actions?post_id=10');
	await s.ctrl.open();
	assert.equal(s.api.requests.length, 1, 'kept for the life of the editor page');
});

test('T14 refetched after Refresh and after a server apply', async () => {
	const server = action({ apply: { mode: 'server', operation: null, targets: ['meta:x'], requires_saved: false, reapply: false, label: '', meta_map: {} } });
	const s = await opened([server], {
		[RUN]: () => envelope(),
		[APPLY]: () => ({ mode: 'server', operation: null, targets: ['meta:x'], value: 'New headline', message: 'Saved.', links: [], sync: {} })
	});
	await s.ctrl.refresh();
	assert.equal(s.api.requests.filter((r) => r.method === 'GET').length, 2);
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	assert.equal(s.api.requests.filter((r) => r.method === 'GET').length, 3);
});

test('T16 zero actions installed: empty state and no request at all', async () => {
	const s = setup([], {}, { config: { hasActions: false } });
	await s.ctrl.open();
	assert.equal(s.ctrl.state.phase, 'empty');
	assert.equal(s.api.requests.length, 0);
});

test('T23 a double click sends one run', async () => {
	let release;
	const s = await opened([action()], { [RUN]: () => new Promise((resolve) => { release = resolve; }) });
	s.ctrl.run('test/headline');
	s.ctrl.run('test/headline');
	assert.equal(s.api.requests.filter((r) => r.method === 'POST').length, 1);
	release(envelope());
	await settle();
	assert.equal(s.ctrl.slot('test/headline').status, 'result');
});

test('T24 the run sends the unsaved title and only declared needs', async () => {
	const ed = makeEditor({ title: 'Not saved yet', excerpt: 'secret excerpt' });
	const s = await opened([action()], { [RUN]: () => envelope() }, { ed });
	await s.ctrl.run('test/headline');
	const body = s.api.requests[1].data;
	assert.deepEqual(body.context, { title: 'Not saved yet', text: 'Hello world' });
	assert.equal(body.post_id, 10);
	assert.ok(!JSON.stringify(body).includes('secret excerpt'));
});

test('T25 context over the limit is not sent', async () => {
	const s = await opened([action()], {}, { config: { contextLimit: 20 } });
	await s.ctrl.run('test/headline');
	assert.equal(s.api.requests.length, 1);
	assert.equal(s.ctrl.slot('test/headline').message, t.tooLong);
});

test('T35 cancel aborts a sync run and tells the server the run token', async () => {
	let signal;
	const s = await opened([action()], {
		[RUN]: (options) => new Promise((resolve, reject) => {
			signal = options.signal;
			signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
		}),
		'DELETE /editor-ai-sidebar/v1/jobs/*': () => null
	});
	const running = s.ctrl.run('test/headline');
	s.ctrl.cancel('test/headline');
	await running;
	await settle();
	assert.equal(signal.aborted, true);
	assert.equal(s.ctrl.slot('test/headline').status, 'idle');
	const del = s.api.requests.find((r) => r.method === 'DELETE');
	assert.equal(del.path, '/editor-ai-sidebar/v1/jobs/' + 'tok'.repeat(6));
	assert.equal(s.ctrl.slot('test/headline').result, null, 'no result shown');
});

test('T35 cancel during a queued run deletes the job', async () => {
	const s = await opened([action()], {
		[RUN]: () => ({ job_id: 'j'.repeat(32), poll_after: 3 }),
		'DELETE /editor-ai-sidebar/v1/jobs/*': () => null
	});
	await s.ctrl.run('test/headline');
	await settle();
	assert.equal(s.ctrl.slot('test/headline').status, 'polling');
	s.ctrl.cancel('test/headline');
	assert.equal(s.api.requests.at(-1).path, '/editor-ai-sidebar/v1/jobs/' + 'j'.repeat(32));
	assert.equal(s.timers.pending.size, 0);
});

test('T34 and T41 polling follows the schedule; an expired job shows the gone message', async () => {
	let polls = 0;
	const s = await opened([action({ max_wait: 30 })], {
		[RUN]: () => ({ job_id: 'j'.repeat(32), poll_after: 3 }),
		['GET /editor-ai-sidebar/v1/jobs/' + 'j'.repeat(32)]: () => {
			polls++;
			if (polls < 3) {
				return { job_id: 'j'.repeat(32), poll_after: 3 };
			}
			throw { code: 'job_gone', message: 'gone', data: { status: 404 } };
		}
	});
	await s.ctrl.run('test/headline');
	await settle();
	const delays = [];
	for (let i = 0; i < 3; i++) {
		delays.push(s.timers.fire());
		await settle();
	}
	assert.deepEqual(delays, [3000, 6000, 12000]);
	assert.equal(s.ctrl.slot('test/headline').message, t.gone);
});

test('T34 polling stops at max_wait with the timeout message', async () => {
	const s = await opened([action({ max_wait: 10 })], {
		[RUN]: () => ({ job_id: 'j'.repeat(32), poll_after: 3 }),
		['GET /editor-ai-sidebar/v1/jobs/' + 'j'.repeat(32)]: () => ({ job_id: 'j'.repeat(32), poll_after: 3 })
	});
	await s.ctrl.run('test/headline');
	await settle();
	s.timers.fire();
	await settle();
	s.timers.fire();
	await settle();
	assert.equal(s.timers.pending.size, 0);
	assert.equal(s.ctrl.slot('test/headline').message, t.timeout);
});

test('T39 a client timeout shows the timeout message and does not retry', async () => {
	const s = await opened([action({ timeout: 5 })], {
		[RUN]: (options) => new Promise((resolve, reject) => {
			options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
		})
	});
	const running = s.ctrl.run('test/headline');
	assert.equal(s.timers.fire(), 5000);
	await running;
	await settle();
	assert.equal(s.ctrl.slot('test/headline').message, t.timeout);
	assert.equal(s.api.requests.filter((r) => r.method === 'POST').length, 1);
});

test('T40 and T69 a second auth failure shows the session message', async () => {
	const s = await opened([action()], {
		[RUN]: () => {
			throw { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } };
		}
	});
	await s.ctrl.run('test/headline');
	await settle();
	assert.equal(s.ctrl.slot('test/headline').message, t.session);
	assert.equal(s.api.requests.filter((r) => r.method === 'POST').length, 1, 'apiFetch refreshes the nonce itself; we do not retry');

	const logged = makeController({ api: makeApi({ [LIST]: () => { throw { code: 'rest_forbidden', data: { status: 401 } }; } }) });
	await logged.ctrl.open();
	await settle();
	assert.equal(logged.ctrl.state.phase, 'error');
	assert.equal(logged.ctrl.state.message, t.session);
});

test('T52 set_title edits the editor state only; the post is dirty, not saved', async () => {
	const ed = makeEditor({ title: 'Old' });
	const s = await opened([action()], {
		[RUN]: () => envelope(),
		[APPLY]: (options) => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: options.data.value, message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	assert.deepEqual(ed.calls, [['editPost', { title: 'New headline' }]]);
	assert.equal(ed.state.title, 'New headline');
	assert.equal(ed.state.dirty, true);
	assert.equal(ed.state.undo, 'Old', 'the editor keeps the old title for its own undo');
	assert.equal(s.ctrl.slot('test/headline').items.a.note, t.appliedEditor);
	const body = s.api.requests.find((r) => r.path.endsWith('/apply')).data;
	assert.equal(body.approved, true);
	assert.equal(body.context_hash, core.contextHash({ title: 'Old', text: 'Hello world' }));
});

test('T47 nothing is written until Approve', async () => {
	const ed = makeEditor();
	const s = await opened([action()], { [RUN]: () => envelope() }, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	assert.deepEqual(ed.calls, []);
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
});

test('T54 a title edited since the run asks before replacing', async () => {
	const ed = makeEditor({ title: 'At run time' });
	const s = await opened([action()], {
		[RUN]: () => envelope(),
		[APPLY]: () => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: 'New headline', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	ed.state.title = 'Edited by hand';
	await s.ctrl.approve('test/headline', 'a');
	const item = s.ctrl.slot('test/headline').items.a;
	assert.deepEqual(item.confirm, { kind: 'replace', current: 'Edited by hand', proposed: 'New headline' });
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
	await s.ctrl.confirmReplace('test/headline', 'a');
	await settle();
	assert.equal(ed.state.title, 'New headline');
});

test('T50 context changed: Apply anyway resends with confirm_stale', async () => {
	let first = true;
	const s = await opened([action()], {
		[RUN]: () => envelope(),
		[APPLY]: (options) => {
			if (first) {
				first = false;
				throw { code: 'context_changed', message: 'changed', data: { status: 409 } };
			}
			return { mode: 'editor', operation: 'set_title', targets: ['title'], value: options.data.value, message: '', links: [], sync: {} };
		}
	});
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	assert.deepEqual(s.ctrl.slot('test/headline').items.a.confirm, { kind: 'stale' });
	await s.ctrl.confirmStale('test/headline', 'a');
	await settle();
	const applies = s.api.requests.filter((r) => r.path.endsWith('/apply'));
	assert.equal(applies[0].data.confirm_stale, false);
	assert.equal(applies[1].data.confirm_stale, true);
	assert.equal(s.ctrl.slot('test/headline').items.a.applied, true);
});

const altAction = action({
	id: 'test/alt',
	group: 'alt_text',
	needs: ['selection'],
	selection: ['core/image'],
	selection_attributes: ['id', 'alt'],
	apply: { mode: 'editor', operation: 'set_block_attribute', targets: ['content'], requires_saved: false, reapply: false, label: '', position: 'end', attribute: 'alt', meta_map: {} }
});
const ALT_RUN = 'POST /editor-ai-sidebar/v1/actions/test/alt/run';

function images() {
	return makeEditor({
		blocks: [
			{ clientId: 'i1', name: 'core/image', attributes: { id: 1, alt: '' } },
			{ clientId: 'p1', name: 'core/paragraph', attributes: {} },
			{ clientId: 'i2', name: 'core/image', attributes: { id: 2, alt: '' } }
		],
		selected: ['i2']
	});
}

test('T22 and T65 the result stays tied to its block when the selection moves', async () => {
	const ed = images();
	const s = await opened([altAction], {
		[ALT_RUN]: () => envelope({ action_id: 'test/alt' }),
		[APPLY]: (options) => ({ mode: 'editor', operation: 'set_block_attribute', targets: ['content'], value: options.data.value, attribute: 'alt', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/alt');
	await settle();
	assert.deepEqual(s.ctrl.slot('test/alt').snapshot.blockLabel, { title: 'Image', index: 2, total: 2 });
	ed.state.selected = ['i1'];
	await s.ctrl.approve('test/alt', 'a');
	await settle();
	assert.deepEqual(ed.calls, [['updateBlockAttributes', 'i2', { alt: 'New headline' }]]);
	assert.equal(ed.getBlock('i1').attributes.alt, '');
	const body = s.api.requests.find((r) => r.path.endsWith('/apply')).data;
	assert.equal(body.context_hash, core.contextHash({ selection: { name: 'core/image', client_id: 'i2', attributes: { id: 2, alt: '' } } }));
});

test('T53 block removed before approve: no request, gone message', async () => {
	const ed = images();
	const s = await opened([altAction], { [ALT_RUN]: () => envelope({ action_id: 'test/alt' }) }, { ed });
	await s.ctrl.run('test/alt');
	await settle();
	ed.state.blocks = ed.state.blocks.filter((b) => b.clientId !== 'i2');
	await s.ctrl.approve('test/alt', 'a');
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
	assert.equal(s.ctrl.slot('test/alt').items.a.error, t.blockGone);
});

test('T53 block removed while the apply was in flight: gone message, nothing changed', async () => {
	const ed = images();
	const s = await opened([altAction], {
		[ALT_RUN]: () => envelope({ action_id: 'test/alt' }),
		[APPLY]: () => {
			ed.state.blocks = ed.state.blocks.filter((b) => b.clientId !== 'i2');
			return { mode: 'editor', operation: 'set_block_attribute', targets: ['content'], value: 'x', attribute: 'alt', message: '', links: [], sync: {} };
		}
	}, { ed });
	await s.ctrl.run('test/alt');
	await settle();
	await s.ctrl.approve('test/alt', 'a');
	await settle();
	assert.equal(s.ctrl.slot('test/alt').items.a.error, t.blockGone);
	assert.deepEqual(ed.calls, []);
});

const summary = action({
	id: 'test/summary',
	group: 'summary',
	result: { layout: 'single', item_type: 'list', fields: {} },
	apply: { mode: 'editor', operation: 'insert_blocks', targets: ['content'], requires_saved: false, reapply: false, label: '', position: 'start', attribute: null, meta_map: {} }
});

test('T62 summary inserts a list block at the start; T77 not in the code editor', async () => {
	const ed = makeEditor();
	const s = await opened([summary], {
		'POST /editor-ai-sidebar/v1/actions/test/summary/run': () => envelope({ action_id: 'test/summary', items: [{ id: 'a', type: 'list', value: ['One', 'Two'], notes: [] }] }),
		[APPLY]: (options) => ({ mode: 'editor', operation: 'insert_blocks', targets: ['content'], value: options.data.value, position: 'start', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/summary');
	await settle();
	ed.state.codeEditor = true;
	await s.ctrl.approve('test/summary', 'a');
	assert.equal(s.ctrl.slot('test/summary').items.a.error, t.codeEditor);
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
	ed.state.codeEditor = false;
	await s.ctrl.approve('test/summary', 'a');
	await settle();
	assert.deepEqual(ed.calls, [['insertBlocks', ['One', 'Two'], true, 'start']]);
});

test('T77 code editor mode leaves other operations working', async () => {
	const ed = makeEditor({ codeEditor: true });
	const s = await opened([action()], {
		[RUN]: () => envelope(),
		[APPLY]: () => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: 'New headline', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	assert.equal(ed.state.title, 'New headline');
});

const metaServer = action({
	id: 'test/meta',
	group: 'meta',
	result: { layout: 'single', item_type: 'fields', fields: { title: { label: 'Meta title', limit: 60 } } },
	apply: { mode: 'server', operation: null, targets: ['meta:my_description'], requires_saved: true, reapply: false, label: '', position: 'end', attribute: null, meta_map: {} }
});
const META_RUN = 'POST /editor-ai-sidebar/v1/actions/test/meta/run';

test('T56 and T64 a server apply syncs the editor copy without making the post dirty', async () => {
	const ed = makeEditor();
	const s = await opened([metaServer], {
		[META_RUN]: () => envelope({ action_id: 'test/meta', items: [{ id: 'm', type: 'fields', value: { title: 'T' }, notes: [] }] }),
		[APPLY]: () => ({ mode: 'server', operation: null, targets: ['meta:my_description'], value: { title: 'T' }, message: 'Saved.', links: [{ label: 'Open', url: 'https://example.test/wp-admin/x' }], sync: { meta: { my_description: 'new' } } })
	}, { ed });
	await s.ctrl.run('test/meta');
	await settle();
	await s.ctrl.approve('test/meta', 'm');
	await settle();
	assert.deepEqual(ed.calls, [['sync', { meta: { my_description: 'new' } }]]);
	assert.equal(ed.state.dirty, false);
	assert.equal(ed.meta().my_description, 'new', 'a following save sends the new value');
	const item = s.ctrl.slot('test/meta').items.m;
	assert.equal(item.note, 'Saved.');
	assert.deepEqual(item.links, [{ label: 'Open', url: 'https://example.test/wp-admin/x' }]);
});

test('T58 and T67 requires_saved saves first; a failed save stops the apply', async () => {
	const ed = makeEditor({ dirty: true, isNew: true });
	const s = await opened([metaServer], {
		[META_RUN]: () => envelope({ action_id: 'test/meta', items: [{ id: 'm', type: 'fields', value: { title: 'T' }, notes: [] }] }),
		[APPLY]: () => ({ mode: 'server', operation: null, targets: [], value: { title: 'T' }, message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/meta');
	await settle();
	assert.equal(s.ctrl.approveLabel(metaServer), t.saveAndApprove);
	ed.state.saveOk = false;
	await s.ctrl.approve('test/meta', 'm');
	await settle();
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
	assert.equal(s.ctrl.slot('test/meta').items.m.error, t.saveFailed);
	ed.state.saveOk = true;
	await s.ctrl.approve('test/meta', 'm');
	await settle();
	assert.deepEqual(ed.calls.map((c) => c[0]), ['save', 'save', 'sync']);
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 1);
	assert.equal(s.ctrl.approveLabel(metaServer), t.approve);
});

test('T59 without apply capability: Approve does nothing, Copy copies', async () => {
	const ed = makeEditor();
	const s = await opened([action({ can_apply: false })], { [RUN]: () => envelope() }, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 0);
	s.ctrl.copy('test/headline', 'a');
	assert.deepEqual(ed.calls, [['copy', 'New headline']]);
});

test('T60 choices close after one apply unless reapply', async () => {
	const choices = envelope({ layout: 'choices', items: [{ id: 'a', type: 'text', value: 'One', notes: [] }, { id: 'b', type: 'text', value: 'Two', notes: [] }] });
	const apply = (options) => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: options.data.value, message: '', links: [], sync: {} });
	const s = await opened([action({ result: { layout: 'choices', item_type: 'text', fields: {} } })], { [RUN]: () => choices, [APPLY]: apply });
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	await s.ctrl.approve('test/headline', 'b');
	assert.equal(s.api.requests.filter((r) => r.path.endsWith('/apply')).length, 1);
});

test('T44 edits: only on editable actions, edited value is what is sent', async () => {
	const s = await opened([action({ editable: false })], { [RUN]: () => envelope() });
	await s.ctrl.run('test/headline');
	await settle();
	s.ctrl.startEdit('test/headline', 'a');
	assert.equal(s.ctrl.slot('test/headline').items.a.editing, false);

	const e = await opened([action({ editable: true })], {
		[RUN]: () => envelope(),
		[APPLY]: (options) => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: options.data.value, message: '', links: [], sync: {} })
	});
	await e.ctrl.run('test/headline');
	await settle();
	e.ctrl.startEdit('test/headline', 'a');
	e.ctrl.setValue('test/headline', 'a', 'My own headline');
	assert.equal(e.ctrl.slot('test/headline').items.a.edited, true);
	await e.ctrl.approve('test/headline', 'a');
	await settle();
	assert.equal(e.api.requests.find((r) => r.path.endsWith('/apply')).data.value, 'My own headline');
	assert.equal(e.ed.state.title, 'My own headline');
});

test('T73 a long item applies the full value', async () => {
	const long = 'शब्द '.repeat(800).trim();
	const s = await opened([action()], {
		[RUN]: () => envelope({ items: [{ id: 'a', type: 'text', value: long, notes: [] }] }),
		[APPLY]: (options) => ({ mode: 'editor', operation: 'set_title', targets: ['title'], value: options.data.value, message: '', links: [], sync: {} })
	});
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.approve('test/headline', 'a');
	await settle();
	assert.equal(s.api.requests.find((r) => r.path.endsWith('/apply')).data.value, long);
	assert.equal(s.ed.state.title, long);
});

test('T74 remaining time comes from expires_in and a monotonic clock', async () => {
	const s = await opened([action()], { [RUN]: () => envelope({ expires_in: 600 }) });
	s.advance(5_000_000);
	await s.ctrl.run('test/headline');
	await settle();
	assert.equal(s.ctrl.remaining('test/headline'), 600);
	s.advance(90_000);
	assert.equal(s.ctrl.remaining('test/headline'), 510);
	s.advance(10_000_000);
	assert.equal(s.ctrl.remaining('test/headline'), 0);
});

test('T75 zero items shows the no-suggestions message', async () => {
	const s = await opened([action()], { [RUN]: () => envelope({ result_id: null, items: [], notes: [{ level: 'info', text: 'Too short.' }], expires_in: 0 }) });
	await s.ctrl.run('test/headline');
	await settle();
	const slot = s.ctrl.slot('test/headline');
	assert.equal(slot.status, 'empty');
	assert.equal(slot.message, t.noSuggestions);
	assert.deepEqual(slot.notes, [{ level: 'info', text: 'Too short.' }]);
});

test('T46 dismiss calls the dismiss route and writes nothing', async () => {
	const ed = makeEditor();
	const s = await opened([action()], {
		[RUN]: () => envelope(),
		['POST /editor-ai-sidebar/v1/results/' + 'a'.repeat(32) + '/dismiss']: () => null
	}, { ed });
	await s.ctrl.run('test/headline');
	await settle();
	await s.ctrl.dismiss('test/headline');
	const req = s.api.requests.at(-1);
	assert.ok(req.path.endsWith('/dismiss'));
	assert.deepEqual(req.data, { post_id: 10, action_id: 'test/headline' });
	assert.deepEqual(ed.calls, []);
	assert.equal(s.ctrl.slot('test/headline').status, 'idle');
});

test('ruling 1: a pending job listed on open is resumed', async () => {
	const job = 'j'.repeat(32);
	const s = await opened([action({ max_wait: 60, pending_job: { job_id: job, poll_after: 4, elapsed: 50, client_id: null } })], {
		['GET /editor-ai-sidebar/v1/jobs/' + job]: () => envelope()
	});
	assert.equal(s.ctrl.slot('test/headline').status, 'polling');
	assert.deepEqual(s.ctrl.slot('test/headline').schedule, [4]);
	s.timers.fire();
	await settle();
	assert.equal(s.ctrl.slot('test/headline').status, 'result');
});

test('set_block_attribute refuses an attribute that is not a string attribute of the block', async () => {
	const ed = images();
	ed.attributeIsString = (name, attribute) => attribute === 'caption';
	const s = await opened([altAction], {
		[ALT_RUN]: () => envelope({ action_id: 'test/alt' }),
		[APPLY]: () => ({ mode: 'editor', operation: 'set_block_attribute', targets: ['content'], value: 'x', attribute: 'alt', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/alt');
	await settle();
	await s.ctrl.approve('test/alt', 'a');
	await settle();
	assert.deepEqual(ed.calls, []);
	assert.equal(s.ctrl.slot('test/alt').items.a.error, t.cannotApply);
});

test('set_block_attribute escapes provider text into an attribute stored as HTML', async () => {
	const ed = images();
	ed.attributeIsHtml = (name, attribute) => attribute === 'alt';
	const s = await opened([altAction], {
		[ALT_RUN]: () => envelope({ action_id: 'test/alt' }),
		[APPLY]: () => ({ mode: 'editor', operation: 'set_block_attribute', targets: ['content'], value: '<img src=x onerror=alert(1)> & "q"', attribute: 'alt', message: '', links: [], sync: {} })
	}, { ed });
	await s.ctrl.run('test/alt');
	await settle();
	await s.ctrl.approve('test/alt', 'a');
	await settle();
	assert.deepEqual(ed.calls, [['updateBlockAttributes', 'i2', { alt: '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot;' }]]);
});
