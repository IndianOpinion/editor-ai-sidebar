# editor-ai-sidebar

One sidebar in the WordPress block editor for every AI action your plugins provide, with one way to show a result and one explicit approve step before anything is written. It makes no model calls of its own: provider plugins register actions through one filter and answer one REST contract that this plugin defines and enforces.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

**Measured result:** none yet. The plugin was written from an idea; the first release on a live site records its own numbers.

## Why

A site with five AI helpers in the editor ends up with five panels, five spinners and five ideas of what "accept" means. Some write to the post the moment the model answers, and an editor who learned that one panel waits for a click assumes the next one does too. A helper that writes to the database while the editor is open can also be undone by the editor's next save, because the editor holds its own copy of the title, excerpt, meta and blocks.

This plugin is the host: one sidebar, fixed result types, a server-checked approve, and editor-safe writes.

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later, the block editor.

**Without Composer.** Download the release zip, unpack it into `wp-content/plugins/editor-ai-sidebar/` and activate "Editor AI Sidebar". The plugin carries its own autoloader and has no runtime dependencies.

**With Composer.**

```sh
composer require indianopinion/editor-ai-sidebar
```

The package type is `wordpress-plugin`, so `composer/installers` (if your project uses it) places it in your plugins folder.

**As a must-use plugin.** Copy the plugin folder to `wp-content/mu-plugins/editor-ai-sidebar/` and copy `mu-plugin/editor-ai-sidebar-loader.php` to `wp-content/mu-plugins/`. Must-use plugins never run activation hooks; the plugin creates its table on the first admin or REST request instead.

## Quick start

1. Activate the plugin. Open a post. The toolbar shows an "AI actions" button; the sidebar says "No AI actions are installed."
2. Copy `examples/word-count/` into `wp-content/plugins/` and activate it. Open the sidebar again: "Count words" appears. Click it, read the count, click "Copy".
3. To see every kind of action, activate `examples/canned-providers/` too. It registers a summary, headlines, a meta title and description, alt text for a selected image, and a queued translation, all with canned results.

## Writing a provider

A provider is a plugin that adds a definition to the `editor_ai_sidebar_actions` filter. This is the smallest one that works:

```php
add_filter('editor_ai_sidebar_actions', function (array $actions): array {
    $actions['my-plugin/shout'] = [
        'id'     => 'my-plugin/shout',
        'label'  => __('Shout the title', 'my-plugin'),
        'group'  => 'headlines',
        'needs'  => ['title'],
        'result' => ['layout' => 'single', 'item_type' => 'text'],
        'apply'  => ['mode' => 'editor', 'operation' => 'set_title'],
        'run'    => function (array $request): array {
            return [
                'layout' => 'single',
                'items'  => [['id' => 'a', 'type' => 'text', 'value' => strtoupper($request['context']['title'])]],
            ];
        },
    ];
    return $actions;
});
```

The editor clicks "Shout the title", sees the result, and clicks "Approve". The sidebar then edits the title in the editor; the post is unsaved until the editor saves it, and the editor's undo works.

### The definition

| Key | Type | Required | Default | Meaning |
|---|---|---|---|---|
| `id` | string | yes | | `<provider>/<name>`: lower-case letters, digits, hyphens; 3 to 64 characters |
| `contract` | int | no | `1` | Contract version. This release supports `1` |
| `label` | string | yes | | Button text, translated by you |
| `description` | string | no | `''` | One line under the button |
| `group` | string | yes | | `summary`, `headlines`, `meta`, `alt_text`, `translation` or `other` |
| `order` | int | no | `10` | Order within the group |
| `icon` | string | no | `''` | A Dashicons slug |
| `post_types` | string[] | no | every sidebar type | Where it appears |
| `needs` | array | no | `['title', 'text']` | Context fields sent to `run`: `title`, `excerpt`, `content`, `text`, `featured_media`, `language`, `post_type`, `status`, plus `selection_attributes => [...]` for block-bound actions |
| `selection` | string[] or null | no | `null` | Block names the action works on, for example `['core/image']` |
| `result` | array | yes | | `layout` (`single` or `choices`), `item_type` (`text`, `list` or `fields`), `fields` (for `fields`: name to label, or to `['label' => ..., 'limit' => int]`) |
| `apply` | array | yes | | `mode` (`editor` or `server`), `operation` (editor mode), `targets` (server mode), `requires_saved`, `reapply`, `label`, `position`, `attribute`, `meta_map` |
| `editable` | bool | no | `false` | Let the editor change the value before approving |
| `run_capability` | string | no | `edit_post` | Meta capabilities get the post id |
| `apply_capability` | string | no | `edit_post` | As above |
| `timeout` | int | no | `60` | Seconds, 5 to 300 |
| `max_wait` | int | no | `600` | Seconds for queued runs, 30 to 3,600 |
| `daily_cap` | int | no | `200` | Host safety net: runs per action per UTC day across the site |
| `run` | callable | yes | | `fn(RunRequest $r): Result\|Pending\|WP_Error` |
| `poll` | callable | queued | | `fn(string $ref, RunRequest $r): Result\|Pending\|WP_Error` |
| `cancel` | callable | no | | `fn(string $ref): void` |
| `apply_callback` | callable | server mode | | `fn(ApplyRequest $a): ApplyOutcome\|WP_Error` |
| `validate` | callable | no | | `fn(Item $edited, RunRequest $original): true\|WP_Error` |
| `dismiss` | callable | no | | `fn(string $result_id, Item[] $items, int $user_id): void` |
| `available` | callable | no | | `fn(int $post_id, int $user_id): true\|string`; a string is the reason shown. Keep it cheap: it runs on every sidebar open |

A definition that fails validation is dropped with a reason (`invalid_id`, `invalid_definition`, `duplicate_id`, `contract_unsupported`, `core_field_server_write`, `unknown_operation`, `meta_not_in_rest`). The others keep working. Rejections are listed under Settings, Writing, and in `wp editor-ai-sidebar actions`.

### Editor operations

| Operation | Item type | Effect in the editor |
|---|---|---|
| `set_title` | `text` | Edits the title |
| `set_excerpt` | `text` | Edits the excerpt |
| `insert_blocks` | `text` or `list` | Inserts a paragraph or a list at `position`: `start`, `end` (default) or `after_selection`. Values are escaped |
| `set_block_attribute` | `text` | Sets `attribute` on the block the result was made for (needs `selection`) |
| `set_meta` | `text` or `fields` | Edits meta registered with `show_in_rest`; `fields` map to keys through `meta_map`, `text` uses one `meta:<key>` target |
| `copy` | any | Copies the value to the clipboard; writes nothing |

### Server mode

Use `'apply' => ['mode' => 'server', 'targets' => [...]]` and an `apply_callback` when the write belongs on the server: a meta key the editor does not hold, an attachment's alt text, a new draft. Targets are `meta:<key>`, `attachment_alt:<id>`, `attachment_alt:<from selection>`, `featured_media`, `new_post` and `provider`. The title, excerpt, content, status, date, slug and author can never be server targets: the editor would send its own copy back on the next save. After a server apply the sidebar updates the editor's copy of registered meta, the featured image and attachment alt text without making the post dirty.

### Queued runs

Anything that can take more than a few tens of seconds should return `Pending::job($your_ref, $poll_after_seconds)` from `run` and finish in `poll`. The browser polls from your suggested delay (at least 2 s), doubling to 15 s, for at most `max_wait`. Your reference never leaves the server. If the editor reloads, the sidebar picks up the pending job again while it lives.

### Plain arrays or value objects

Callbacks may type their parameter as `array` to receive the plain array form, or as the value object (`RunRequest`, `ApplyRequest`, `Item`) to receive that. They may return either form. The array form of a result is:

```json
{ "layout": "single", "items": [ { "id": "a", "type": "text", "value": "...", "notes": [ { "level": "warning", "text": "..." } ], "score": 0.8, "score_label": "Clarity", "lang": "hi", "dir": "ltr" } ], "notes": [] }
```

Limits: 20 items; text up to 20,000 characters; lists up to 50 entries of 1,000 characters; up to 10 fields; up to 10 notes per item of 300 characters each.

### Errors from your provider

Return a `WP_Error` with `data.status` between 400 and 599 for "switched off", "daily cap reached", "not configured" or "the model declined". The editor sees your message as plain text (at most 300 characters). An exception or a fatal error shows "This action failed. The site log has details." and the class and message go to the event log, not to the browser. From `apply_callback`, add `'final' => true` to the error data when a retry cannot succeed.

## Configuration

| Setting | Default | Where |
|---|---|---|
| Sidebar on or off | on | Settings, Writing, "AI actions"; the constant `EDITOR_AI_SIDEBAR_DISABLED` overrides it |
| Switch off single actions | none | Same screen |
| `editor_ai_sidebar_title` | "AI actions" | Filter |
| `editor_ai_sidebar_post_types` | editor post types shown in REST | Filter |
| `editor_ai_sidebar_view_capability` | `edit_posts` | Filter |
| `editor_ai_sidebar_settings_capability` | `manage_options` | Filter |
| `editor_ai_sidebar_rate_limit` | `['runs' => 30, 'window' => 600]` per user | Filter |
| `editor_ai_sidebar_daily_cap` | the action's `daily_cap` (200); `0` switches the host cap off | Filter, `($cap, $action_id)` |
| `editor_ai_sidebar_context_limit` | 524288 bytes | Filter |
| `editor_ai_sidebar_result_ttl` | 1800 seconds (60 to 86,400) | Filter |
| `editor_ai_sidebar_show_when_empty` | `true` | Filter |
| `editor_ai_sidebar_action_enabled` | settings | Filter, `($on, $action_id, $post_id)` |

Actions fired: `editor_ai_sidebar_run_started`, `editor_ai_sidebar_result_issued`, `editor_ai_sidebar_run_failed`, `editor_ai_sidebar_applied`, `editor_ai_sidebar_dismissed`, `editor_ai_sidebar_contract_violation`. None of them carries item text.

PHP functions: `editor_ai_sidebar_actions($post_id = null, $user_id = null)`, `editor_ai_sidebar_rejected_definitions()`, `editor_ai_sidebar_contract_versions()`.

WP-CLI: `wp editor-ai-sidebar actions [--post=<id>] [--user=<id>] [--format=table|json]` and `wp editor-ai-sidebar log [--limit=<n>] [--format=table|json]`. There is no command to run or apply an action: an apply needs a human click.

## How it works

1. Loading the editor makes no request. Opening the sidebar makes one: `GET /wp-json/editor-ai-sidebar/v1/actions?post_id=`.
2. A click sends only the declared context, read from the unsaved editor state, to `POST /actions/<id>/run`. The server checks the sidebar switch, the user, `edit_post`, the action, the capability, the selection, the context size and shape, the rate limit, the daily cap and `available`, in that order, before any provider code runs.
3. While `run` runs, the host watches for writes to the post and to the action's targets. A provider that writes early has its result thrown away.
4. A valid result gets a 128-bit random id and a record in the plugin's table: who ran it, for which post and action, a SHA-256 hash of each item, a hash of the context, and an expiry (30 minutes by default).
5. Approve sends `POST /results/<id>/apply` with `approved: true`, the value and the current context hash. The server refuses unless the record exists, is unexpired, unused, issued to this user for this post and action, the value matches what was issued (or the action allows edits and `validate` agrees), and the context has not changed (or the editor confirmed "Apply anyway").
6. Editor mode: the server marks the record used and answers with the operation; the sidebar performs it through the editor's data stores. Server mode: the server calls `apply_callback`, then the sidebar syncs the editor's copy.

Every route answers with `Cache-Control: no-store` and `X-Editor-AI-Sidebar-Contract: 1`. Full details are in [docs/PRD.md](docs/PRD.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Limitations

- Block editor only. Nothing for the classic editor, the site editor or the widget editor.
- Results render only through the fixed item types; providers cannot ship their own JavaScript.
- Only the user who ran an action can approve its result, within the result lifetime.
- The sidebar scripts ship unminified (about 54 KB with the stylesheet, about 15 KB gzipped) because there is no build step.
- The rate limit and daily cap live in transients and are safety nets, not exact counters under heavy concurrency.
- Cancelling a synchronous run stops the result from being issued; the provider's call itself still finishes on the server.

## FAQ

**Does this plugin call any AI service?** No. It makes no outbound HTTP request at all. Providers do the calling and own their keys, prompts and spend.

**Can a provider write to the post without the editor's approval?** Not through this plugin. A write to the post or to the action's targets during `run` discards the result, and the apply route refuses anything the server did not issue to that user. A provider plugin is still PHP running on your site: install only providers you trust.

**What happens when a provider plugin is deactivated?** Its actions disappear from the sidebar, and approving an older result from it returns "The plugin that made this result is no longer active."

**Why did "Apply" ask me to confirm?** Either the post changed since the result was made ("Apply anyway" or "Run again"), or the field you are replacing was edited since the run ("Replace the current title?").

**Where is the log?** `wp editor-ai-sidebar log`, or the counts on Settings, Writing. It holds ids and codes only, never post text or model output.

## Development

```sh
composer install
composer check        # phpcs, phpstan, phpunit
npm test              # sidebar script tests (Node 20 or later, no dependencies)
```

The MySQL integration test runs when `EDITOR_AI_SIDEBAR_TEST_MYSQL` points at a server (`host:port` or a socket path, user `root`, no password).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
