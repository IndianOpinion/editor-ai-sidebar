/**
 * Editor AI Sidebar: rendering.
 *
 * Pure functions from controller state to an element tree, built with the
 * `h` (createElement) and components handed in. Provider text only ever
 * goes in as a child string, which React renders as a text node (case 42).
 * Plain script; under Node it is a CommonJS module for the tests.
 */
(function (root, factory) {
	'use strict';
	var view = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = view;
	} else {
		root.editorAiSidebarView = view;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Translated strings. `__` and `sprintf` come from wp.i18n in the editor.
	 */
	function strings(__, sprintf) {
		var d = 'editor-ai-sidebar';
		return {
			approve: __('Approve', d),
			saveAndApprove: __('Save and approve', d),
			edit: __('Edit', d),
			copy: __('Copy', d),
			cancel: __('Cancel', d),
			dismiss: __('Dismiss', d),
			refresh: __('Refresh', d),
			showAll: __('Show all', d),
			showLess: __('Show less', d),
			working: __('Working...', d),
			loading: __('Loading actions...', d),
			noneInstalled: __('No AI actions are installed.', d),
			howToAdd: __('How to add AI actions', d),
			noneForType: __('No AI actions apply to this kind of content.', d),
			noSuggestions: __('No suggestions this time.', d),
			tooLong: __('This post is too long for this action.', d),
			timeout: __('This took too long. Try again, or ask the site owner to switch this action to queued mode.', d),
			session: __('Your session has expired. Save your work and reload the editor.', d),
			network: __('The site could not be reached. Check your connection and try again.', d),
			gone: __('This result is no longer available. Run the action again.', d),
			providerError: __('This action failed. The site log has details.', d),
			generic: __('Something went wrong. Try again.', d),
			rateLimited: __('Too many runs in a short time. Wait a little and try again.', d),
			rateLimitedFor: function (seconds) {
				return sprintf(__('Too many runs in a short time. Try again in %d seconds.', d), seconds);
			},
			appliedEditor: __('Applied to the editor. Save the post to keep it.', d),
			appliedServer: __('Done.', d),
			copied: __('Copied to the clipboard.', d),
			blockGone: __('The block this was made for is gone.', d),
			cannotApply: __('The editor cannot apply this result. Ask the site owner to check the action.', d),
			codeEditor: __('Switch to the visual editor to insert blocks.', d),
			saveFailed: __('The post could not be saved, so nothing was approved.', d),
			viewOnly: __('You can view this but an editor must approve it.', d),
			stale: __('The post has changed since this result was made.', d),
			applyAnyway: __('Apply anyway', d),
			runAgain: __('Run again', d),
			replace: __('Replace', d),
			current: __('Current', d),
			proposed: __('New', d),
			warning: __('Warning', d),
			replaceTitle: __('Replace the current title?', d),
			replaceExcerpt: __('Replace the current excerpt?', d),
			replaceValue: __('Replace the current value?', d),
			selectBlock: function (title) {
				return sprintf(__('Select a block of type %s', d), title);
			},
			selectImage: __('Select an image block', d),
			blockOf: function (title, index, total) {
				return sprintf(__('%1$s %2$d of %3$d', d), title, index, total);
			},
			madeAt: function (time) {
				return sprintf(__('Made at %s', d), time);
			},
			expiresIn: function (minutes) {
				return minutes < 1
					? __('Expires in less than a minute', d)
					: sprintf(__('Expires in %d min', d), minutes);
			},
			resultReady: function (label) {
				return sprintf(__('%s: result ready.', d), label);
			},
			counter: function (count, limit) {
				return sprintf(__('%1$d of %2$d characters', d), count, limit);
			},
			groups: {
				summary: __('Summary', d),
				headlines: __('Headlines', d),
				meta: __('Meta', d),
				alt_text: __('Alt text', d),
				translation: __('Translation', d),
				other: __('Other', d)
			}
		};
	}

	function selectionHint(env, action) {
		var t = env.t;
		var names = action.selection || [];
		if (names.length === 1 && names[0] === 'core/image') {
			return t.selectImage;
		}
		return t.selectBlock(names.map(function (name) {
			return env.ed.blockTitle(name);
		}).join(', '));
	}

	function render(env) {
		var h = env.h;
		var c = env.c;
		var t = env.t;
		var state = env.ctrl.state;
		var children = [];

		if (state.phase === 'empty') {
			children.push(h('p', { key: 'empty', className: 'eas-empty' }, t.noneInstalled));
			if (env.config.canManage) {
				children.push(h('p', { key: 'help' }, h('a', {
					href: env.config.helpUrl,
					target: '_blank',
					rel: 'noopener noreferrer'
				}, t.howToAdd)));
			}
		} else if (state.phase === 'loading' || state.phase === 'closed') {
			children.push(h('div', { key: 'loading', className: 'eas-loading' }, h(c.Spinner, null), t.loading));
		} else {
			children.push(h('div', { key: 'toolbar', className: 'eas-toolbar' }, h(c.Button, {
				variant: 'tertiary',
				onClick: function () {
					env.ctrl.refresh();
				}
			}, t.refresh)));
			if (state.phase === 'error') {
				children.push(h('div', { key: 'error', className: 'eas-error', role: 'alert' }, state.message));
			} else if (!state.actions.length) {
				children.push(h('p', { key: 'none', className: 'eas-empty' }, t.noneForType));
			} else {
				env.core.groupActions(state.actions).forEach(function (group) {
					children.push(h(c.PanelBody, {
						key: 'group-' + group.group,
						title: t.groups[group.group] || group.group,
						initialOpen: true
					}, group.actions.map(function (action) {
						return renderAction(env, action);
					})));
				});
			}
		}
		children.push(h('div', {
			key: 'live',
			className: 'screen-reader-text',
			'aria-live': 'polite'
		}, state.announce || ''));
		return h('div', { className: 'eas-sidebar' }, children);
	}

	function renderAction(env, action) {
		var h = env.h;
		var c = env.c;
		var t = env.t;
		var ctrl = env.ctrl;
		var slot = ctrl.slot(action.id);
		var busy = slot.status === 'running' || slot.status === 'polling';
		var selection = env.core.selectionState(action, env.ed);
		var disabled = !action.available || busy || !selection.ok;
		var parts = [];

		parts.push(h(c.Button, {
			key: 'run',
			variant: 'secondary',
			className: 'eas-run',
			icon: action.icon || undefined,
			disabled: disabled,
			'aria-disabled': disabled,
			isBusy: busy,
			onClick: function () {
				if (!disabled) {
					ctrl.run(action.id);
				}
			}
		}, action.label));
		if (action.description) {
			parts.push(h('p', { key: 'desc', className: 'eas-description' }, action.description));
		}
		if (!action.available) {
			parts.push(h('p', { key: 'reason', className: 'eas-reason' }, action.reason));
		} else if (!selection.ok) {
			parts.push(h('p', { key: 'select', className: 'eas-reason' }, selectionHint(env, action)));
		}
		if (busy) {
			parts.push(h('div', { key: 'busy', className: 'eas-busy' },
				h(c.Spinner, null),
				h('span', null, t.working),
				h(c.Button, {
					variant: 'link',
					onClick: function () {
						ctrl.cancel(action.id);
					}
				}, t.cancel)
			));
		}
		if (slot.status === 'error') {
			parts.push(h('div', { key: 'error', className: 'eas-error', role: 'alert' }, slot.message));
		}
		if (slot.status === 'empty') {
			parts.push(h('div', { key: 'empty-result', className: 'eas-result' },
				h('p', null, slot.message),
				renderNotes(env, slot.notes || []),
				h(c.Button, {
					variant: 'tertiary',
					onClick: function () {
						ctrl.dismiss(action.id);
					}
				}, t.dismiss)
			));
		}
		if (slot.status === 'result') {
			parts.push(renderResult(env, action, slot));
		}
		return h('div', { key: action.id, className: 'eas-action', 'data-action': action.id }, parts);
	}

	function renderNotes(env, notes) {
		var h = env.h;
		if (!notes || !notes.length) {
			return null;
		}
		return h('ul', { className: 'eas-notes' }, notes.map(function (note, index) {
			return h('li', {
				key: index,
				className: note.level === 'warning' ? 'eas-note is-warning' : 'eas-note'
			}, note.level === 'warning' ? env.t.warning + ': ' + note.text : note.text);
		}));
	}

	function renderResult(env, action, slot) {
		var h = env.h;
		var c = env.c;
		var t = env.t;
		var ctrl = env.ctrl;
		var header = [h('strong', { key: 'label' }, action.label)];
		if (slot.snapshot && slot.snapshot.blockLabel) {
			var label = slot.snapshot.blockLabel;
			header.push(h('span', { key: 'block', className: 'eas-block' }, t.blockOf(label.title, label.index, label.total)));
		}
		if (env.formatTime && slot.receivedAt !== undefined) {
			header.push(h('span', { key: 'time', className: 'eas-time' }, t.madeAt(env.formatTime())));
		}
		var minutes = Math.floor(ctrl.remaining(action.id) / 60);
		header.push(h('span', { key: 'expires', className: 'eas-expires' }, t.expiresIn(minutes)));

		var items = slot.result.items.map(function (item) {
			return renderItem(env, action, slot, item);
		});
		return h('div', { key: 'result', className: 'eas-result', role: 'region', 'aria-label': action.label },
			h('div', { className: 'eas-result-header' }, header),
			renderNotes(env, slot.notes),
			h(slot.result.layout === 'choices' ? 'ol' : 'div', { className: 'eas-items' }, items),
			h(c.Button, {
				variant: 'tertiary',
				onClick: function () {
					ctrl.dismiss(action.id);
				}
			}, t.dismiss)
		);
	}

	function renderValue(env, action, item, state) {
		var h = env.h;
		var t = env.t;
		var value = state.value;
		if (typeof value === 'string') {
			var long = env.core.graphemeCount(value) > env.core.COLLAPSE_AT;
			var collapsed = long && !state.expanded;
			return h('div', null,
				h('div', { className: collapsed ? 'eas-value is-collapsed' : 'eas-value' }, value),
				long ? h(env.c.Button, {
					variant: 'link',
					onClick: function () {
						env.ctrl.toggleExpand(action.id, item.id);
					}
				}, state.expanded ? t.showLess : t.showAll) : null
			);
		}
		if (Array.isArray(value)) {
			return h('ol', { className: 'eas-value eas-list' }, value.map(function (entry, index) {
				return h('li', { key: index }, entry);
			}));
		}
		var fields = (action.result && action.result.fields) || {};
		return h('dl', { className: 'eas-value eas-fields' }, Object.keys(value).map(function (name) {
			var label = fields[name] && fields[name].label ? fields[name].label : name;
			return [h('dt', { key: 'dt-' + name }, label), h('dd', { key: 'dd-' + name }, value[name])];
		}));
	}

	function renderEditor(env, action, item, state) {
		var h = env.h;
		var c = env.c;
		var t = env.t;
		var ctrl = env.ctrl;
		var value = state.value;
		if (typeof value === 'string') {
			return h(c.TextareaControl, {
				label: t.edit,
				value: value,
				onChange: function (next) {
					ctrl.setValue(action.id, item.id, next);
				}
			});
		}
		if (Array.isArray(value)) {
			return h(c.TextareaControl, {
				label: t.edit,
				value: value.join('\n'),
				onChange: function (next) {
					ctrl.setValue(action.id, item.id, next.split('\n').filter(function (line) {
						return line.trim() !== '';
					}));
				}
			});
		}
		var fields = (action.result && action.result.fields) || {};
		return h('div', { className: 'eas-field-editors' }, Object.keys(value).map(function (name) {
			var spec = fields[name] || {};
			var limit = spec.limit || null;
			var count = env.core.graphemeCount(value[name]);
			return h('div', { key: name, className: 'eas-field-editor' },
				h(c.TextareaControl, {
					label: spec.label || name,
					value: value[name],
					onChange: function (next) {
						var copy = {};
						Object.keys(value).forEach(function (key) {
							copy[key] = value[key];
						});
						copy[name] = next;
						ctrl.setValue(action.id, item.id, copy);
					}
				}),
				limit ? h('p', {
					className: count > limit ? 'eas-counter is-over' : 'eas-counter'
				}, t.counter(count, limit)) : null
			);
		}));
	}

	function replaceQuestion(t, action) {
		switch (action.apply.operation) {
			case 'set_title':
				return t.replaceTitle;
			case 'set_excerpt':
				return t.replaceExcerpt;
		}
		return t.replaceValue;
	}

	function renderItem(env, action, slot, item) {
		var h = env.h;
		var c = env.c;
		var t = env.t;
		var ctrl = env.ctrl;
		var state = slot.items[item.id];
		var parts = [];

		if (item.score !== null && item.score !== undefined) {
			parts.push(h('span', { key: 'score', className: 'eas-score' },
				(item.score_label ? item.score_label + ': ' : '') + String(item.score)));
		}
		parts.push(h('div', { key: 'notes' }, renderNotes(env, item.notes || [])));
		parts.push(h('div', { key: 'value' }, state.editing ? renderEditor(env, action, item, state) : renderValue(env, action, item, state)));

		if (state.confirm && state.confirm.kind === 'replace') {
			parts.push(h('div', { key: 'confirm', className: 'eas-confirm', role: 'alertdialog' },
				h('p', null, replaceQuestion(t, action)),
				h('p', null, h('strong', null, t.current + ': '), state.confirm.current),
				h('p', null, h('strong', null, t.proposed + ': '), state.confirm.proposed),
				h(c.Button, { variant: 'primary', onClick: function () { ctrl.confirmReplace(action.id, item.id); } }, t.replace),
				h(c.Button, { variant: 'tertiary', onClick: function () { ctrl.cancelConfirm(action.id, item.id); } }, t.cancel)
			));
		}
		if (state.confirm && state.confirm.kind === 'stale') {
			parts.push(h('div', { key: 'stale', className: 'eas-confirm', role: 'alertdialog' },
				h('p', null, t.stale),
				h(c.Button, { variant: 'primary', onClick: function () { ctrl.confirmStale(action.id, item.id); } }, t.applyAnyway),
				h(c.Button, { variant: 'secondary', onClick: function () { ctrl.run(action.id); } }, t.runAgain)
			));
		}
		if (state.error) {
			parts.push(h('p', { key: 'error', className: 'eas-error', role: 'alert' }, state.error));
		}
		if (state.note) {
			parts.push(h('p', { key: 'note', className: 'eas-applied' }, state.note));
		}
		if (state.links && state.links.length) {
			parts.push(h('ul', { key: 'links', className: 'eas-links' }, state.links.map(function (link, index) {
				return h('li', { key: index }, h('a', { href: link.url }, link.label));
			})));
		}

		var buttons = [];
		var approveDisabled = !action.can_apply || state.applied || state.busy || !!slot.closed;
		buttons.push(h(c.Button, {
			key: 'approve',
			variant: 'primary',
			disabled: approveDisabled,
			'aria-disabled': approveDisabled,
			isBusy: state.busy,
			onClick: function () {
				if (!approveDisabled) {
					ctrl.approve(action.id, item.id);
				}
			}
		}, ctrl.approveLabel(action)));
		if (action.editable && action.can_apply && !state.editing && !state.applied && !slot.closed) {
			buttons.push(h(c.Button, {
				key: 'edit',
				variant: 'secondary',
				onClick: function () {
					ctrl.startEdit(action.id, item.id);
				}
			}, t.edit));
		}
		if (!action.can_apply) {
			buttons.push(h(c.Button, {
				key: 'copy',
				variant: 'secondary',
				onClick: function () {
					ctrl.copy(action.id, item.id);
				}
			}, t.copy));
		}
		parts.push(h('div', { key: 'buttons', className: 'eas-buttons' }, buttons));
		if (!action.can_apply) {
			parts.push(h('p', { key: 'view-only', className: 'eas-reason' }, t.viewOnly));
		}

		var props = { key: item.id, className: 'eas-item', 'data-item': item.id };
		if (item.lang) {
			props.lang = item.lang;
		}
		if (item.dir) {
			props.dir = item.dir;
		}
		return h(slot.result.layout === 'choices' ? 'li' : 'div', props, parts);
	}

	return { strings: strings, render: render };
});
