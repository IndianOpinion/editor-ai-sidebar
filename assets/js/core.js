/**
 * Editor AI Sidebar: logic with no WordPress dependency.
 *
 * Plain script, no build step. In the browser it sets
 * window.editorAiSidebarCore; under Node it is a CommonJS module, so the
 * tests load the same file the editor runs.
 */
(function (root, factory) {
	'use strict';
	var core = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = core;
	} else {
		root.editorAiSidebarCore = core;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var GROUPS = ['summary', 'headlines', 'meta', 'alt_text', 'translation', 'other'];
	var COLLAPSE_AT = 2000;
	var MIN_POLL = 2;
	var MAX_POLL = 15;

	// ------------------------------------------------------------------
	// Canonical JSON and SHA-256 (same rule as the PHP Canonical class)
	// ------------------------------------------------------------------

	function canonical(value) {
		if (value === null || typeof value !== 'object') {
			return JSON.stringify(value);
		}
		if (Array.isArray(value)) {
			if (!value.length) {
				return '{}';
			}
			return '[' + value.map(canonical).join(',') + ']';
		}
		var keys = Object.keys(value).filter(function (key) {
			return value[key] !== undefined;
		}).sort();
		if (!keys.length) {
			return '{}';
		}
		return '{' + keys.map(function (key) {
			return JSON.stringify(key) + ':' + canonical(value[key]);
		}).join(',') + '}';
	}

	function utf8(text) {
		if (typeof TextEncoder !== 'undefined') {
			return new TextEncoder().encode(text);
		}
		var bytes = [];
		var encoded = unescape(encodeURIComponent(text));
		for (var i = 0; i < encoded.length; i++) {
			bytes.push(encoded.charCodeAt(i));
		}
		return bytes;
	}

	var K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];

	/**
	 * SHA-256 of a string's UTF-8 bytes, as lower-case hex. Written out so
	 * it works on plain-HTTP admin screens where crypto.subtle is missing.
	 */
	function sha256Hex(text) {
		var bytes = utf8(text);
		var length = bytes.length;
		var words = new Array(((length + 9 + 63) >> 6) << 4).fill(0);
		var i;
		for (i = 0; i < length; i++) {
			words[i >> 2] |= bytes[i] << (24 - (i % 4) * 8);
		}
		words[length >> 2] |= 0x80 << (24 - (length % 4) * 8);
		var bitsHigh = Math.floor(length / 0x20000000);
		words[words.length - 2] = bitsHigh;
		words[words.length - 1] = (length << 3) >>> 0;

		var h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
		var w = new Array(64);
		for (var block = 0; block < words.length; block += 16) {
			for (i = 0; i < 16; i++) {
				w[i] = words[block + i] | 0;
			}
			for (i = 16; i < 64; i++) {
				var x = w[i - 15];
				var y = w[i - 2];
				var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
				var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
				w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
			}
			var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], k = h[7];
			for (i = 0; i < 64; i++) {
				var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
				var ch = (e & f) ^ (~e & g);
				var t1 = (k + S1 + ch + K[i] + w[i]) | 0;
				var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
				var maj = (a & b) ^ (a & c) ^ (b & c);
				var t2 = (S0 + maj) | 0;
				k = g;
				g = f;
				f = e;
				e = (d + t1) | 0;
				d = c;
				c = b;
				b = a;
				a = (t1 + t2) | 0;
			}
			h[0] = (h[0] + a) | 0;
			h[1] = (h[1] + b) | 0;
			h[2] = (h[2] + c) | 0;
			h[3] = (h[3] + d) | 0;
			h[4] = (h[4] + e) | 0;
			h[5] = (h[5] + f) | 0;
			h[6] = (h[6] + g) | 0;
			h[7] = (h[7] + k) | 0;
		}
		return h.map(function (n) {
			return ('00000000' + (n >>> 0).toString(16)).slice(-8);
		}).join('');
	}

	function contextHash(context) {
		return sha256Hex(canonical(context));
	}

	function byteLength(text) {
		return utf8(text).length;
	}

	/**
	 * Length in user-perceived characters (lesson 8).
	 */
	function graphemeCount(text) {
		text = String(text);
		if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
			var count = 0;
			var iterator = new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)[Symbol.iterator]();
			while (!iterator.next().done) {
				count++;
			}
			return count;
		}
		return Array.from(text).length;
	}

	// ------------------------------------------------------------------
	// Ordering, context and small helpers
	// ------------------------------------------------------------------

	function compareActions(a, b) {
		var ga = GROUPS.indexOf(a.group);
		var gb = GROUPS.indexOf(b.group);
		if (ga !== gb) {
			return ga - gb;
		}
		if (a.order !== b.order) {
			return a.order - b.order;
		}
		if (a.label !== b.label) {
			return a.label < b.label ? -1 : 1;
		}
		return a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
	}

	/**
	 * Group actions in the fixed order; empty groups are left out (case 15).
	 */
	function groupActions(actions) {
		var sorted = actions.slice().sort(compareActions);
		var groups = [];
		GROUPS.forEach(function (group) {
			var members = sorted.filter(function (action) {
				return action.group === group;
			});
			if (members.length) {
				groups.push({ group: group, actions: members });
			}
		});
		return groups;
	}

	/**
	 * Delays in seconds between polls: from the suggested delay (at least
	 * 2), doubling to 15, until max_wait is used up (case 34).
	 */
	function pollSchedule(pollAfter, maxWait) {
		var delay = Math.max(MIN_POLL, Math.min(MAX_POLL, Math.floor(pollAfter) || MIN_POLL));
		var total = 0;
		var out = [];
		while (total + delay <= maxWait) {
			out.push(delay);
			total += delay;
			delay = Math.min(delay * 2, MAX_POLL);
		}
		return out;
	}

	function pick(object, names) {
		var out = {};
		names.forEach(function (name) {
			if (object && Object.prototype.hasOwnProperty.call(object, name) && object[name] !== undefined) {
				var value = object[name];
				if (value === null || typeof value !== 'object') {
					out[name] = value;
				}
			}
		});
		return out;
	}

	/**
	 * Whether a selection-bound action can run now (case 20).
	 */
	function selectionState(action, ed) {
		if (!action.selection) {
			return { ok: true, block: null };
		}
		var selected = ed.selectedBlocks();
		if (selected.length === 1 && action.selection.indexOf(selected[0].name) !== -1) {
			return { ok: true, block: selected[0] };
		}
		return { ok: false, block: null };
	}

	/**
	 * Context from the unsaved editor state, only the declared needs
	 * (case 24). `block` pins the selection to a given block (apply).
	 */
	function buildContext(action, ed, block) {
		var context = {};
		action.needs.forEach(function (need) {
			switch (need) {
				case 'title':
					context.title = String(ed.title() || '');
					break;
				case 'excerpt':
					context.excerpt = String(ed.excerpt() || '');
					break;
				case 'content':
					context.content = String(ed.content() || '');
					break;
				case 'text':
					context.text = String(ed.text() || '');
					break;
				case 'featured_media':
					context.featured_media = parseInt(ed.featuredMedia(), 10) || 0;
					break;
				case 'language':
					context.language = String(ed.language() || 'en');
					break;
				case 'post_type':
					context.post_type = String(ed.postType() || '');
					break;
				case 'status':
					context.status = String(ed.status() || '');
					break;
				case 'selection':
					var chosen = block || selectionState(action, ed).block;
					if (chosen) {
						context.selection = {
							name: chosen.name,
							client_id: chosen.clientId,
							attributes: pick(chosen.attributes, action.selection_attributes || [])
						};
					}
					break;
			}
		});
		return context;
	}

	/**
	 * "Image 3 of 5": where a block sits among blocks of its type (case 22).
	 */
	function blockLabel(ed, block) {
		var ids = ed.blocksOfName(block.name);
		var index = ids.indexOf(block.clientId);
		return { title: ed.blockTitle(block.name), index: index + 1, total: ids.length };
	}

	function escapeHtml(text) {
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function valueToText(item, action) {
		if (typeof item.value === 'string') {
			return item.value;
		}
		if (Array.isArray(item.value)) {
			return item.value.join('\n');
		}
		var fields = (action && action.result && action.result.fields) || {};
		return Object.keys(item.value).map(function (name) {
			var label = fields[name] && fields[name].label ? fields[name].label : name;
			return label + ': ' + item.value[name];
		}).join('\n');
	}

	/**
	 * Current value of an editor-mode target, for the replace check (case 54).
	 */
	function targetValue(action, ed, block) {
		var apply = action.apply;
		switch (apply.operation) {
			case 'set_title':
				return String(ed.title() || '');
			case 'set_excerpt':
				return String(ed.excerpt() || '');
			case 'set_meta':
				var meta = ed.meta() || {};
				var keys = metaKeys(action);
				return keys.map(function (key) {
					return meta[key] === undefined || meta[key] === null ? '' : String(meta[key]);
				}).join('\n');
			case 'set_block_attribute':
				var current = block ? ed.getBlock(block.clientId) : null;
				var value = current && current.attributes ? current.attributes[apply.attribute] : '';
				return value === undefined || value === null ? '' : String(value);
		}
		return null;
	}

	function metaKeys(action) {
		var map = action.apply.meta_map || {};
		var keys = Object.keys(map).map(function (field) {
			return map[field];
		});
		(action.apply.targets || []).forEach(function (target) {
			if (target.indexOf('meta:') === 0) {
				keys.push(target.slice(5));
			}
		});
		return keys.filter(function (key, index) {
			return keys.indexOf(key) === index;
		});
	}

	function proposedText(action, value) {
		if (typeof value === 'string') {
			return value;
		}
		return valueToText({ value: value }, action);
	}

	// ------------------------------------------------------------------
	// Error messages (lesson 11: say what to do next)
	// ------------------------------------------------------------------

	function isSessionError(error) {
		if (!error) {
			return false;
		}
		var status = error.data && error.data.status;
		return status === 401 || error.code === 'rest_cookie_invalid_nonce' || error.code === 'rest_not_logged_in';
	}

	function errorMessage(error, t) {
		if (!error) {
			return t.generic;
		}
		if (error.timedOut) {
			return t.timeout;
		}
		if (error.code === 'fetch_error') {
			return t.network;
		}
		if (isSessionError(error)) {
			return t.session;
		}
		switch (error.code) {
			case 'provider_error':
				return t.providerError;
			case 'job_gone':
			case 'result_expired':
				return t.gone;
			case 'context_too_large':
				return t.tooLong;
			case 'rate_limited':
				var after = error.data && error.data.retry_after;
				return after ? t.rateLimitedFor(after) : t.rateLimited;
		}
		if (error.message) {
			return String(error.message);
		}
		return t.generic;
	}

	// ------------------------------------------------------------------
	// Controller: the sidebar's state machine
	// ------------------------------------------------------------------

	/**
	 * deps: api(options) returning a Promise (apiFetch), ed (editor
	 * adapter), config, t (translated strings), timers {set, clear},
	 * now() in milliseconds from a monotonic clock, speak(message),
	 * token() returning a random run token, onChange().
	 */
	function Controller(deps) {
		this.deps = deps;
		this.state = { phase: 'closed', message: '', actions: [], slots: {}, announce: '' };
		this.loads = 0;
	}

	Controller.prototype.changed = function () {
		if (this.deps.onChange) {
			this.deps.onChange(this.state);
		}
	};

	Controller.prototype.path = function (suffix) {
		return '/' + this.deps.config.namespace + suffix;
	};

	Controller.prototype.action = function (id) {
		for (var i = 0; i < this.state.actions.length; i++) {
			if (this.state.actions[i].id === id) {
				return this.state.actions[i];
			}
		}
		return null;
	};

	Controller.prototype.slot = function (id) {
		if (!this.state.slots[id]) {
			this.state.slots[id] = { status: 'idle', message: '', items: {} };
		}
		return this.state.slots[id];
	};

	Controller.prototype.announce = function (message) {
		this.state.announce = message;
		if (this.deps.speak) {
			this.deps.speak(message);
		}
	};

	/**
	 * First open of the sidebar: the first moment any request is made
	 * (case 13). With nothing installed no request is made (case 16).
	 */
	Controller.prototype.open = function () {
		if (this.state.phase !== 'closed') {
			return Promise.resolve();
		}
		if (!this.deps.config.hasActions) {
			this.state.phase = 'empty';
			this.changed();
			return Promise.resolve();
		}
		return this.load();
	};

	Controller.prototype.load = function () {
		var self = this;
		self.state.phase = 'loading';
		self.loads++;
		self.changed();
		return self.deps.api({
			path: self.path('/actions?post_id=' + encodeURIComponent(self.deps.config.postId)),
			method: 'GET'
		}).then(function (actions) {
			self.state.actions = Array.isArray(actions) ? actions : [];
			self.state.phase = 'ready';
			self.state.message = '';
			self.state.actions.forEach(function (action) {
				var slot = self.slot(action.id);
				if (action.pending_job && slot.status === 'idle') {
					self.resume(action);
				}
			});
			self.changed();
		}, function (error) {
			self.state.phase = 'error';
			self.state.message = errorMessage(error, self.deps.t);
			self.changed();
		});
	};

	Controller.prototype.refresh = function () {
		return this.load();
	};

	Controller.prototype.isBusy = function (id) {
		var status = this.slot(id).status;
		return status === 'running' || status === 'polling';
	};

	Controller.prototype.run = function (id) {
		var self = this;
		var action = self.action(id);
		if (!action || self.isBusy(id) || !action.available) {
			return Promise.resolve();
		}
		var ed = self.deps.ed;
		var selection = selectionState(action, ed);
		if (!selection.ok) {
			return Promise.resolve();
		}
		var context = buildContext(action, ed, selection.block);
		var slot = self.slot(id);
		slot.items = {};
		slot.result = null;
		slot.notes = [];
		if (byteLength(canonical(context)) > self.deps.config.contextLimit) {
			slot.status = 'error';
			slot.message = self.deps.t.tooLong;
			self.changed();
			return Promise.resolve();
		}
		slot.snapshot = {
			block: selection.block ? { clientId: selection.block.clientId, name: selection.block.name } : null,
			blockLabel: selection.block ? blockLabel(ed, selection.block) : null,
			target: targetValue(action, ed, selection.block)
		};
		slot.status = 'running';
		slot.message = '';
		slot.token = self.deps.token();
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		slot.abort = controller;
		slot.timedOut = false;
		slot.timer = self.deps.timers.set(function () {
			slot.timedOut = true;
			if (controller) {
				controller.abort();
			}
		}, action.timeout * 1000);
		self.changed();

		return self.deps.api({
			path: self.path('/actions/' + action.id + '/run'),
			method: 'POST',
			data: { post_id: self.deps.config.postId, context: context, run_token: slot.token },
			signal: controller ? controller.signal : undefined
		}).then(function (body) {
			self.deps.timers.clear(slot.timer);
			if (slot.status !== 'running') {
				return;
			}
			if (body && body.job_id && body.result_id === undefined) {
				self.startPolling(action, body.job_id, body.poll_after, action.max_wait);
				return;
			}
			self.showResult(action, body);
		}, function (error) {
			self.deps.timers.clear(slot.timer);
			if (slot.status !== 'running') {
				return;
			}
			if (error && error.name === 'AbortError') {
				if (slot.timedOut) {
					slot.status = 'error';
					slot.message = self.deps.t.timeout;
					self.announce(slot.message);
				} else {
					slot.status = 'idle';
				}
				self.changed();
				return;
			}
			self.fail(id, error);
		});
	};

	Controller.prototype.fail = function (id, error) {
		var slot = this.slot(id);
		slot.status = 'error';
		slot.message = errorMessage(error, this.deps.t);
		this.announce(slot.message);
		this.changed();
	};

	Controller.prototype.resume = function (action) {
		var job = action.pending_job;
		var slot = this.slot(action.id);
		var ed = this.deps.ed;
		var block = job.client_id ? ed.getBlock(job.client_id) : null;
		slot.snapshot = {
			block: block ? { clientId: block.clientId, name: block.name } : null,
			blockLabel: block ? blockLabel(ed, block) : null,
			target: null
		};
		slot.resumed = true;
		this.startPolling(action, job.job_id, job.poll_after, Math.max(0, action.max_wait - (job.elapsed || 0)));
	};

	Controller.prototype.startPolling = function (action, jobId, pollAfter, budget) {
		var self = this;
		var slot = self.slot(action.id);
		slot.status = 'polling';
		slot.jobId = jobId;
		slot.schedule = pollSchedule(pollAfter || MIN_POLL, budget);
		slot.step = 0;
		self.changed();
		self.nextPoll(action);
	};

	Controller.prototype.nextPoll = function (action) {
		var self = this;
		var slot = self.slot(action.id);
		if (slot.status !== 'polling') {
			return;
		}
		if (slot.step >= slot.schedule.length) {
			slot.status = 'error';
			slot.message = self.deps.t.timeout;
			self.announce(slot.message);
			self.changed();
			return;
		}
		var delay = slot.schedule[slot.step];
		slot.step++;
		slot.timer = self.deps.timers.set(function () {
			if (slot.status !== 'polling') {
				return;
			}
			self.deps.api({ path: self.path('/jobs/' + slot.jobId), method: 'GET' }).then(function (body) {
				if (slot.status !== 'polling') {
					return;
				}
				if (body && body.job_id && body.result_id === undefined) {
					self.nextPoll(action);
					return;
				}
				self.showResult(action, body);
			}, function (error) {
				if (slot.status !== 'polling') {
					return;
				}
				self.fail(action.id, error);
			});
		}, delay * 1000);
	};

	Controller.prototype.cancel = function (id) {
		var slot = this.slot(id);
		if (slot.status === 'running') {
			slot.status = 'idle';
			this.deps.timers.clear(slot.timer);
			if (slot.abort) {
				slot.abort.abort();
			}
			this.deps.api({ path: this.path('/jobs/' + slot.token), method: 'DELETE' }).catch(function () {});
		} else if (slot.status === 'polling') {
			slot.status = 'idle';
			this.deps.timers.clear(slot.timer);
			this.deps.api({ path: this.path('/jobs/' + slot.jobId), method: 'DELETE' }).catch(function () {});
		}
		this.changed();
	};

	Controller.prototype.showResult = function (action, body) {
		var slot = this.slot(action.id);
		var t = this.deps.t;
		slot.result = body;
		slot.notes = (body && body.notes) || [];
		slot.receivedAt = this.deps.now();
		slot.items = {};
		slot.closed = false;
		if (!body || !body.result_id || !body.items || !body.items.length) {
			slot.status = 'empty';
			slot.message = t.noSuggestions;
			this.announce(slot.message);
			this.changed();
			return;
		}
		body.items.forEach(function (item) {
			slot.items[item.id] = {
				value: item.value,
				editing: false,
				edited: false,
				expanded: false,
				busy: false,
				applied: false,
				error: '',
				note: '',
				links: [],
				confirm: null
			};
		});
		slot.status = 'result';
		slot.message = '';
		this.announce(t.resultReady(action.label));
		this.changed();
	};

	/**
	 * Seconds left, from the server's expires_in and a monotonic clock
	 * started when the answer arrived (case 74).
	 */
	Controller.prototype.remaining = function (id) {
		var slot = this.slot(id);
		if (!slot.result || !slot.result.expires_in) {
			return 0;
		}
		var spent = (this.deps.now() - slot.receivedAt) / 1000;
		return Math.max(0, Math.floor(slot.result.expires_in - spent));
	};

	Controller.prototype.original = function (id, itemId) {
		var slot = this.slot(id);
		var items = (slot.result && slot.result.items) || [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].id === itemId) {
				return items[i];
			}
		}
		return null;
	};

	Controller.prototype.startEdit = function (id, itemId) {
		var action = this.action(id);
		var item = this.slot(id).items[itemId];
		if (!action || !action.editable || !item) {
			return;
		}
		item.editing = true;
		this.changed();
	};

	Controller.prototype.setValue = function (id, itemId, value) {
		var item = this.slot(id).items[itemId];
		var original = this.original(id, itemId);
		if (!item || !original || !item.editing) {
			return;
		}
		item.value = value;
		item.edited = canonical(value) !== canonical(original.value);
		item.confirm = null;
		this.changed();
	};

	Controller.prototype.toggleExpand = function (id, itemId) {
		var item = this.slot(id).items[itemId];
		if (item) {
			item.expanded = !item.expanded;
			this.changed();
		}
	};

	Controller.prototype.approveLabel = function (action) {
		var ed = this.deps.ed;
		var t = this.deps.t;
		if (action.apply.mode === 'server' && action.apply.requires_saved && (ed.isDirty() || ed.isNew())) {
			return t.saveAndApprove;
		}
		return action.apply.label || t.approve;
	};

	/**
	 * Approve one item. The only path to a write (case 47).
	 */
	Controller.prototype.approve = function (id, itemId, flags) {
		flags = flags || {};
		var self = this;
		var t = self.deps.t;
		var ed = self.deps.ed;
		var action = self.action(id);
		var slot = self.slot(id);
		var item = slot.items[itemId];
		if (!action || !item || item.busy || item.applied || slot.closed || !action.can_apply) {
			return Promise.resolve();
		}
		var apply = action.apply;
		var block = slot.snapshot && slot.snapshot.block;
		item.error = '';
		item.confirm = null;

		if (apply.mode === 'editor') {
			if (apply.operation === 'insert_blocks' && ed.isCodeEditor()) {
				item.error = t.codeEditor;
				self.changed();
				return Promise.resolve();
			}
			if (apply.operation === 'set_block_attribute' && (!block || !ed.getBlock(block.clientId))) {
				item.error = t.blockGone;
				self.changed();
				return Promise.resolve();
			}
			if (!flags.replace && slot.snapshot && slot.snapshot.target !== null && slot.snapshot.target !== undefined) {
				var now = targetValue(action, ed, block);
				if (now !== null && now !== '' && now !== slot.snapshot.target) {
					item.confirm = { kind: 'replace', current: now, proposed: proposedText(action, item.value) };
					self.changed();
					return Promise.resolve();
				}
			}
		}

		item.busy = true;
		self.changed();

		var ready = Promise.resolve(true);
		if (apply.mode === 'server' && apply.requires_saved && (ed.isDirty() || ed.isNew())) {
			ready = Promise.resolve(ed.save());
		}
		return ready.then(function (saved) {
			if (!saved) {
				item.busy = false;
				item.error = t.saveFailed;
				self.changed();
				return;
			}
			var context = buildContext(action, ed, block ? ed.getBlock(block.clientId) || null : null);
			return self.deps.api({
				path: self.path('/results/' + slot.result.result_id + '/apply'),
				method: 'POST',
				data: {
					post_id: self.deps.config.postId,
					action_id: action.id,
					item_id: itemId,
					value: item.value,
					approved: true,
					context_hash: contextHash(context),
					confirm_stale: !!flags.stale
				}
			}).then(function (response) {
				item.busy = false;
				self.applied(action, slot, item, response);
			}, function (error) {
				item.busy = false;
				if (error && error.code === 'context_changed') {
					item.confirm = { kind: 'stale' };
				} else {
					item.error = errorMessage(error, t);
				}
				self.changed();
			});
		});
	};

	Controller.prototype.applied = function (action, slot, item, response) {
		var t = this.deps.t;
		var ed = this.deps.ed;
		if (response.mode === 'editor') {
			var outcome = performEditorOp(ed, action, response, slot.snapshot);
			if (outcome === 'gone' || outcome === 'invalid' || outcome === 'unknown') {
				item.error = outcome === 'gone' ? t.blockGone : t.cannotApply;
				slot.closed = true;
				this.changed();
				return;
			}
			item.note = response.operation === 'copy' ? t.copied : t.appliedEditor;
		} else {
			if (response.sync) {
				ed.sync(response.sync);
			}
			item.note = response.message || t.appliedServer;
			item.links = response.links || [];
			this.load();
		}
		item.applied = true;
		if (!action.apply.reapply) {
			slot.closed = true;
		}
		this.announce(item.note);
		this.changed();
	};

	Controller.prototype.confirmReplace = function (id, itemId) {
		return this.approve(id, itemId, { replace: true });
	};

	Controller.prototype.confirmStale = function (id, itemId) {
		return this.approve(id, itemId, { replace: true, stale: true });
	};

	Controller.prototype.cancelConfirm = function (id, itemId) {
		var item = this.slot(id).items[itemId];
		if (item) {
			item.confirm = null;
			this.changed();
		}
	};

	Controller.prototype.dismiss = function (id) {
		var self = this;
		var slot = self.slot(id);
		var result = slot.result;
		var hadRecord = result && result.result_id && !slot.closed;
		slot.status = 'idle';
		slot.result = null;
		slot.items = {};
		self.changed();
		if (!hadRecord) {
			return Promise.resolve();
		}
		return self.deps.api({
			path: self.path('/results/' + result.result_id + '/dismiss'),
			method: 'POST',
			data: { post_id: self.deps.config.postId, action_id: id }
		}).catch(function () {});
	};

	Controller.prototype.copy = function (id, itemId) {
		var item = this.slot(id).items[itemId];
		if (!item) {
			return;
		}
		this.deps.ed.copy(valueToText(item, this.action(id)));
		item.note = this.deps.t.copied;
		this.changed();
	};

	/**
	 * Run an editor operation through the editor's stores (section 5.5).
	 */
	function performEditorOp(ed, action, response, snapshot) {
		var value = response.value;
		var apply = action.apply;
		switch (response.operation) {
			case 'set_title':
				ed.editPost({ title: value });
				return 'done';
			case 'set_excerpt':
				ed.editPost({ excerpt: value });
				return 'done';
			case 'set_meta':
				var meta = {};
				if (typeof value === 'string') {
					meta[metaKeys(action)[0]] = value;
				} else {
					var map = apply.meta_map || {};
					Object.keys(map).forEach(function (field) {
						if (Object.prototype.hasOwnProperty.call(value, field)) {
							meta[map[field]] = value[field];
						}
					});
				}
				ed.editPost({ meta: meta });
				return 'done';
			case 'set_block_attribute':
				var block = snapshot && snapshot.block;
				if (!block || !ed.getBlock(block.clientId)) {
					return 'gone';
				}
				if (ed.attributeIsString && !ed.attributeIsString(block.name, apply.attribute)) {
					return 'invalid';
				}
				var attributes = {};
				// An attribute stored as HTML (rich text, or sourced from markup)
				// would read provider text as markup: escape it first (lesson 6).
				attributes[apply.attribute] = ed.attributeIsHtml && ed.attributeIsHtml(block.name, apply.attribute)
					? escapeHtml(value)
					: value;
				ed.updateBlockAttributes(block.clientId, attributes);
				return 'done';
			case 'insert_blocks':
				ed.insertBlocks(Array.isArray(value) ? value : [value], Array.isArray(value), apply.position || 'end');
				return 'done';
			case 'copy':
				ed.copy(valueToText({ value: value }, action));
				return 'done';
		}
		return 'unknown';
	}

	return {
		GROUPS: GROUPS,
		COLLAPSE_AT: COLLAPSE_AT,
		canonical: canonical,
		sha256Hex: sha256Hex,
		contextHash: contextHash,
		byteLength: byteLength,
		graphemeCount: graphemeCount,
		compareActions: compareActions,
		groupActions: groupActions,
		pollSchedule: pollSchedule,
		selectionState: selectionState,
		buildContext: buildContext,
		blockLabel: blockLabel,
		escapeHtml: escapeHtml,
		valueToText: valueToText,
		targetValue: targetValue,
		metaKeys: metaKeys,
		isSessionError: isSessionError,
		errorMessage: errorMessage,
		performEditorOp: performEditorOp,
		Controller: Controller
	};
});
