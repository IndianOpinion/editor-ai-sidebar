'use strict';

/**
 * Test doubles for the sidebar: a mocked editor (store reads and writes),
 * a scripted apiFetch, manual timers and a tiny createElement.
 */
const core = require('../../assets/js/core.js');
const view = require('../../assets/js/view.js');

function identity(text) {
	return text;
}

function sprintf(format, ...args) {
	let i = 0;
	return format.replace(/%(\d+\$)?[sd]/g, (match, position) => {
		const value = position ? args[parseInt(position, 10) - 1] : args[i++];
		return String(value);
	});
}

const t = view.strings(identity, sprintf);

function makeEditor(overrides = {}) {
	const state = Object.assign({
		title: 'Unsaved title',
		excerpt: '',
		content: '<!-- wp:paragraph --><p>Hello <b>world</b></p><!-- /wp:paragraph -->',
		featured: 0,
		postType: 'post',
		status: 'draft',
		meta: {},
		blocks: [],
		selected: [],
		dirty: false,
		isNew: false,
		codeEditor: false,
		saveOk: true,
		record: { id: 10, meta: { my_description: 'old' }, featured_media: 0 },
		media: {}
	}, overrides);
	const calls = [];
	const ed = {
		state,
		calls,
		title: () => state.title,
		excerpt: () => state.excerpt,
		content: () => state.content,
		text: () => state.content.replace(/<[^>]+>/g, '').trim(),
		featuredMedia: () => state.featured,
		language: () => 'en-GB',
		postType: () => state.postType,
		status: () => state.status,
		meta: () => Object.assign({}, state.record.meta, state.meta),
		selectedBlocks: () => state.selected.map((id) => ed.getBlock(id)).filter(Boolean),
		getBlock: (id) => state.blocks.find((block) => block.clientId === id) || null,
		blocksOfName: (name) => state.blocks.filter((block) => block.name === name).map((block) => block.clientId),
		blockTitle: (name) => ({ 'core/image': 'Image', 'core/paragraph': 'Paragraph' }[name] || name),
		isCodeEditor: () => state.codeEditor,
		isDirty: () => state.dirty,
		isNew: () => state.isNew,
		save: () => {
			calls.push(['save']);
			if (state.saveOk) {
				state.dirty = false;
				state.isNew = false;
				state.status = 'draft';
			}
			return Promise.resolve(state.saveOk);
		},
		editPost: (edits) => {
			calls.push(['editPost', edits]);
			if (edits.title !== undefined) {
				state.undo = state.title;
				state.title = edits.title;
			}
			if (edits.excerpt !== undefined) {
				state.excerpt = edits.excerpt;
			}
			if (edits.meta) {
				Object.assign(state.meta, edits.meta);
			}
			state.dirty = true;
		},
		updateBlockAttributes: (clientId, attributes) => {
			calls.push(['updateBlockAttributes', clientId, attributes]);
			Object.assign(ed.getBlock(clientId).attributes, attributes);
			state.dirty = true;
		},
		insertBlocks: (values, asList, position) => {
			calls.push(['insertBlocks', values, asList, position]);
			state.dirty = true;
		},
		copy: (text) => {
			calls.push(['copy', text]);
		},
		sync: (sync) => {
			calls.push(['sync', sync]);
			if (sync.meta) {
				state.record = Object.assign({}, state.record, { meta: Object.assign({}, state.record.meta, sync.meta) });
			}
		}
	};
	return ed;
}

/**
 * apiFetch double. `routes` maps "METHOD path" (path without query) to a
 * function(options) returning a value or throwing/rejecting.
 */
function makeApi(routes) {
	const requests = [];
	const api = (options) => {
		requests.push(options);
		const path = options.path.split('?')[0];
		const key = (options.method || 'GET') + ' ' + path;
		const handler = routes[key] || Object.keys(routes).filter((k) => k.endsWith('*') && key.startsWith(k.slice(0, -1))).map((k) => routes[k])[0];
		if (!handler) {
			return Promise.reject({ code: 'rest_no_route', message: 'No route ' + key, data: { status: 404 } });
		}
		try {
			return Promise.resolve(handler(options));
		} catch (error) {
			return Promise.reject(error);
		}
	};
	api.requests = requests;
	return api;
}

function makeTimers() {
	let next = 1;
	const pending = new Map();
	return {
		pending,
		set(fn, ms) {
			const id = next++;
			pending.set(id, { fn, ms });
			return id;
		},
		clear(id) {
			pending.delete(id);
		},
		/** Run the earliest pending timer; returns its delay in ms. */
		fire() {
			const [id, timer] = [...pending.entries()][0] || [];
			if (!timer) {
				return null;
			}
			pending.delete(id);
			timer.fn();
			return timer.ms;
		}
	};
}

function tick() {
	return new Promise((resolve) => setImmediate(resolve));
}

async function settle(times = 5) {
	for (let i = 0; i < times; i++) {
		await tick();
	}
}

function makeController(options = {}) {
	const ed = options.ed || makeEditor();
	const api = options.api || makeApi({});
	const timers = makeTimers();
	let clock = 0;
	const spoken = [];
	const config = Object.assign({
		namespace: 'editor-ai-sidebar/v1',
		postId: 10,
		hasActions: true,
		canManage: false,
		helpUrl: 'https://example.test/help',
		contextLimit: 524288,
		language: 'en-GB'
	}, options.config || {});
	const ctrl = new core.Controller({
		api,
		ed,
		config,
		t,
		timers,
		now: () => clock,
		speak: (message) => spoken.push(message),
		token: () => 'tok'.repeat(6),
		onChange: () => {}
	});
	return {
		ctrl,
		ed,
		api,
		timers,
		config,
		spoken,
		advance(ms) {
			clock += ms;
		}
	};
}

function action(overrides = {}) {
	return Object.assign({
		id: 'test/headline',
		label: 'Suggest a headline',
		description: '',
		group: 'headlines',
		order: 10,
		icon: '',
		needs: ['title', 'text'],
		selection_attributes: [],
		selection: null,
		result: { layout: 'single', item_type: 'text', fields: {} },
		apply: { mode: 'editor', operation: 'set_title', targets: ['title'], requires_saved: false, reapply: false, label: '', position: 'end', attribute: null, meta_map: {} },
		editable: false,
		timeout: 60,
		max_wait: 600,
		available: true,
		reason: '',
		can_apply: true,
		pending_job: null
	}, overrides);
}

function envelope(overrides = {}) {
	return Object.assign({
		result_id: 'a'.repeat(32),
		action_id: 'test/headline',
		post_id: 10,
		layout: 'single',
		items: [{ id: 'a', type: 'text', value: 'New headline', notes: [], score: null, score_label: null, lang: null, dir: null }],
		notes: [],
		context_hash: 'x',
		expires_in: 1800
	}, overrides);
}

/** createElement double: a plain tree. */
function h(type, props, ...children) {
	return { type, props: props || {}, children: children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false) };
}

const components = { Button: 'Button', PanelBody: 'PanelBody', Spinner: 'Spinner', TextareaControl: 'TextareaControl' };

function renderTree(setup) {
	return view.render({ h, c: components, t, core, ctrl: setup.ctrl, ed: setup.ed, config: setup.config, formatTime: null });
}

function walk(node, visit) {
	if (node === null || node === undefined) {
		return;
	}
	if (Array.isArray(node)) {
		node.forEach((child) => walk(child, visit));
		return;
	}
	visit(node);
	if (typeof node === 'object') {
		walk(node.children, visit);
	}
}

function texts(node) {
	const out = [];
	walk(node, (n) => {
		if (typeof n === 'string' || typeof n === 'number') {
			out.push(String(n));
		}
	});
	return out;
}

function find(node, predicate) {
	const out = [];
	walk(node, (n) => {
		if (n && typeof n === 'object' && !Array.isArray(n) && predicate(n)) {
			out.push(n);
		}
	});
	return out;
}

function buttons(node, label) {
	return find(node, (n) => n.type === 'Button' && texts(n).join('') === label);
}

module.exports = { core, view, t, makeEditor, makeApi, makeTimers, makeController, action, envelope, h, renderTree, texts, find, buttons, settle, walk };
