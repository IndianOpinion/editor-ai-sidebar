/**
 * Editor AI Sidebar: block editor glue.
 *
 * Plain script with no build step (ruling 5). Uses the WordPress globals
 * only: wp.plugins, wp.element, wp.components, wp.data, wp.apiFetch,
 * wp.blocks, wp.i18n and wp.a11y. Registers one plugin with one document
 * sidebar; exposes no public JavaScript API.
 */
(function (wp, core, view, config) {
	'use strict';
	if (!wp || !core || !view || !config || !wp.plugins || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;
	var data = wp.data;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var editorPkg = wp.editor || {};
	var editPostPkg = wp.editPost || {};
	var PluginSidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	if (!PluginSidebar) {
		return;
	}

	var NAME = 'editor-ai-sidebar';

	function sel(store) {
		return data.select(store);
	}

	function dis(store) {
		return data.dispatch(store);
	}

	function toText(html) {
		if (!html) {
			return '';
		}
		// DOMParser builds an inert document: no script runs, no image loads.
		var doc = new window.DOMParser().parseFromString(String(html), 'text/html');
		return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function flatten(blocks, out) {
		blocks.forEach(function (block) {
			out.push(block);
			if (block.innerBlocks && block.innerBlocks.length) {
				flatten(block.innerBlocks, out);
			}
		});
		return out;
	}

	function copyText(text) {
		if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
			window.navigator.clipboard.writeText(text).catch(function () {});
			return;
		}
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		try {
			document.execCommand('copy');
		} catch (e) {
			// Nothing else to try; the value stays on screen to copy by hand.
		}
		document.body.removeChild(area);
	}

	/**
	 * The editor adapter the controller talks to. Reads the unsaved state
	 * and writes only through the editor's data stores (section 5.5).
	 */
	var ed = {
		title: function () {
			return sel('core/editor').getEditedPostAttribute('title');
		},
		excerpt: function () {
			return sel('core/editor').getEditedPostAttribute('excerpt');
		},
		content: function () {
			return sel('core/editor').getEditedPostContent();
		},
		text: function () {
			return toText(sel('core/editor').getEditedPostContent());
		},
		featuredMedia: function () {
			return sel('core/editor').getEditedPostAttribute('featured_media');
		},
		language: function () {
			return config.language;
		},
		postType: function () {
			return sel('core/editor').getCurrentPostType();
		},
		status: function () {
			return sel('core/editor').getEditedPostAttribute('status');
		},
		meta: function () {
			return sel('core/editor').getEditedPostAttribute('meta') || {};
		},
		selectedBlocks: function () {
			var be = sel('core/block-editor');
			return be.getSelectedBlockClientIds().map(function (id) {
				return be.getBlock(id);
			}).filter(Boolean);
		},
		attributeIsString: function (name, attribute) {
			var type = wp.blocks.getBlockType(name);
			var spec = type && type.attributes ? type.attributes[attribute] : null;
			return !!spec && (spec.type === 'string' || spec.type === 'rich-text');
		},
		getBlock: function (clientId) {
			return sel('core/block-editor').getBlock(clientId);
		},
		blocksOfName: function (name) {
			return flatten(sel('core/block-editor').getBlocks(), []).filter(function (block) {
				return block.name === name;
			}).map(function (block) {
				return block.clientId;
			});
		},
		blockTitle: function (name) {
			var type = wp.blocks.getBlockType(name);
			return type && type.title ? type.title : name;
		},
		isCodeEditor: function () {
			var editor = sel('core/editor');
			if (editor.getEditorMode) {
				return editor.getEditorMode() === 'text';
			}
			var editPost = sel('core/edit-post');
			return !!(editPost && editPost.getEditorMode && editPost.getEditorMode() === 'text');
		},
		isDirty: function () {
			return sel('core/editor').isEditedPostDirty();
		},
		isNew: function () {
			return sel('core/editor').isEditedPostNew();
		},
		save: function () {
			return Promise.resolve(dis('core/editor').savePost()).then(function () {
				return sel('core/editor').didPostSaveRequestSucceed();
			}, function () {
				return false;
			});
		},
		editPost: function (edits) {
			dis('core/editor').editPost(edits);
		},
		updateBlockAttributes: function (clientId, attributes) {
			dis('core/block-editor').updateBlockAttributes(clientId, attributes);
		},
		insertBlocks: function (values, asList, position) {
			var be = sel('core/block-editor');
			var blocks;
			if (asList) {
				blocks = [wp.blocks.createBlock('core/list', {}, values.map(function (value) {
					return wp.blocks.createBlock('core/list-item', { content: core.escapeHtml(value) });
				}))];
			} else {
				blocks = values.map(function (value) {
					return wp.blocks.createBlock('core/paragraph', { content: core.escapeHtml(value) });
				});
			}
			var index;
			var root;
			if (position === 'start') {
				index = 0;
			} else if (position === 'after_selection' && be.getSelectedBlockClientId()) {
				var selected = be.getSelectedBlockClientId();
				root = be.getBlockRootClientId(selected) || undefined;
				index = be.getBlockIndex(selected) + 1;
			}
			dis('core/block-editor').insertBlocks(blocks, index, root, false);
		},
		copy: copyText,
		/**
		 * Update the editor's saved copy without an edit, so the post does
		 * not turn dirty and the next save does not send old values (case 56).
		 */
		sync: function (sync) {
			var editor = sel('core/editor');
			var type = editor.getCurrentPostType();
			var id = editor.getCurrentPostId();
			var record = sel('core').getEntityRecord('postType', type, id);
			if (record && (sync.meta || sync.featured_media !== undefined)) {
				var next = Object.assign({}, record);
				if (sync.meta) {
					next.meta = Object.assign({}, record.meta || {}, sync.meta);
				}
				if (sync.featured_media !== undefined) {
					next.featured_media = sync.featured_media;
				}
				dis('core').receiveEntityRecords('postType', type, [next], undefined, false);
			}
			if (sync.attachment_alt) {
				Object.keys(sync.attachment_alt).forEach(function (mediaId) {
					var media = sel('core').getEntityRecord('root', 'media', parseInt(mediaId, 10));
					if (media) {
						dis('core').receiveEntityRecords('root', 'media', [
							Object.assign({}, media, { alt_text: sync.attachment_alt[mediaId] })
						], undefined, false);
					}
				});
			}
		}
	};

	var listeners = [];
	var ctrl = new core.Controller({
		api: wp.apiFetch,
		ed: ed,
		config: config,
		t: view.strings(__, sprintf),
		timers: {
			set: function (fn, ms) {
				return window.setTimeout(fn, ms);
			},
			clear: function (id) {
				window.clearTimeout(id);
			}
		},
		now: function () {
			return window.performance && window.performance.now ? window.performance.now() : Date.now();
		},
		speak: function (message) {
			if (wp.a11y && wp.a11y.speak) {
				wp.a11y.speak(message, 'polite');
			}
		},
		token: function () {
			var bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			return Array.prototype.map.call(bytes, function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
		},
		onChange: function () {
			listeners.forEach(function (fn) {
				fn();
			});
		}
	});

	var components = {
		Button: wp.components.Button,
		PanelBody: wp.components.PanelBody,
		Spinner: wp.components.Spinner,
		TextareaControl: wp.components.TextareaControl
	};

	function Body() {
		var tick = useState(0);
		var setTick = tick[1];
		// Re-render when the selection, dirty state or editor mode changes.
		data.useSelect(function (select) {
			return [
				select('core/block-editor').getSelectedBlockClientIds().join(','),
				select('core/editor').isEditedPostDirty()
			].join('|');
		}, []);
		useEffect(function () {
			var listener = function () {
				setTick(function (n) {
					return n + 1;
				});
			};
			listeners.push(listener);
			ctrl.open();
			var clock = window.setInterval(listener, 30000);
			return function () {
				listeners = listeners.filter(function (fn) {
					return fn !== listener;
				});
				window.clearInterval(clock);
			};
		}, []);
		return view.render({
			h: el,
			c: components,
			t: ctrl.deps.t,
			core: core,
			ctrl: ctrl,
			ed: ed,
			config: config,
			formatTime: null
		});
	}

	wp.plugins.registerPlugin(NAME, {
		icon: 'lightbulb',
		render: function () {
			return el(Fragment, null,
				PluginSidebarMoreMenuItem ? el(PluginSidebarMoreMenuItem, { target: NAME, icon: 'lightbulb' }, config.title) : null,
				el(PluginSidebar, { name: NAME, title: config.title, icon: 'lightbulb' }, el(Body))
			);
		}
	});
})(window.wp, window.editorAiSidebarCore, window.editorAiSidebarView, window.editorAiSidebarConfig);
