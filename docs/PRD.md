# editor-ai-sidebar: product requirements

Status: draft for review. Version target: 0.1.0.
Plugin slug: `editor-ai-sidebar`. Namespace: `IndianOpinion\EditorAiSidebar`.
Global prefix for functions, hooks, options and transients: `editor_ai_sidebar_`.
REST namespace: `editor-ai-sidebar/v1`.

Written from scratch; not derived from any third-party plugin. This PRD is
written from the one-line description of the project, the public block
editor documentation and the public PRDs of sibling projects under
github.com/IndianOpinion. It describes no private code.

## 1. Problem

A site that adds several language-model helpers to the block editor ends up
with several panels. One plugin writes a summary, another suggests
headlines, a third drafts the meta description, a fourth fills alt text, a
fifth drafts a translation. Each one invents its own buttons, its own
spinner, its own error text and its own idea of what "accept" means. Some
write to the post the moment the model answers. An editor who has learned
that one panel waits for a click assumes the next one does too, and an
unchecked line goes live.

There is a second, quieter failure. A helper that writes to the database
while the editor is open can be undone by the editor itself: the block
editor keeps its own copy of the title, the excerpt, registered post meta
and every block attribute, and the next save sends that copy back. The
helper's write vanishes, or worse, half of it does.

editor-ai-sidebar is a host. It puts one sidebar in the block editor, with
one button per registered action, one way to show a result, and one
explicit approve step before anything is written. It makes no model calls
of its own. Sibling plugins register actions through one PHP filter and
answer one REST contract that this plugin defines and enforces. With no
actions registered it shows an empty state and does nothing else.

No measured result from a deployment of this plugin is claimed. It is
written from an idea; the first release records its own numbers.

## 2. Goals and non-goals

Goals:

- One document sidebar in the block editor, titled "AI actions" (filterable),
  listing every registered action available for the current post and user.
- One registration filter and one REST contract, versioned, documented well
  enough that a third party can write a provider without reading this
  plugin's code.
- Every result is shown before anything changes. Every change needs an
  explicit approve click by a user who may make it. The server refuses an
  apply that does not match a result it issued to that user.
- Two ways to apply: into the unsaved editor state (the user then saves as
  usual), or through the provider's own server-side apply callback.
- Synchronous and queued providers both work: a run can return a result at
  once or a job the sidebar polls.
- Works with zero actions registered, with one, and with dozens.
- Accessible, translatable and right-to-left safe.

Non-goals:

- No model calls, no provider keys, no prompts, no spend tracking. Those
  belong to the providers (and to `gen-queue` and `spend-meter` where a
  provider uses them).
- No bulk or background processing. One post, one click, one result.
- No classic editor support in 0.1.0 (see open questions).
- No site editor, widget editor or template editing in 0.1.0. The sidebar
  registers only in the post editor.
- No provider-supplied JavaScript in 0.1.0. Results render through the
  host's fixed set of item types and editor operations.
- No automatic apply, no "apply all", no "always accept from this action".
- No publishing. No apply operation changes post status.

## 3. Users and setting

- **Site administrators** install the plugin and the provider plugins, and
  can switch the sidebar or single actions off.
- **Editors and authors** use the sidebar while writing in the block
  editor. They are the only people who approve results.
- **Provider authors** (the sibling plugins `tldr-block`,
  `headline-options`, `meta-writer`, `alt-text-fill`, `translate-sidecar`,
  and any third party) register actions and implement the contract. Each
  provider ships its own adapter; this plugin ships none for them.
- Runs inside WordPress admin, in the block editor screen, on single sites
  and multisite. The server side is PHP; the sidebar is a script that uses
  the editor's own data stores and `apiFetch`.

## 4. Behaviour

Terms used below. An **action** is one registered button. A **run** is one
click of that button that sends context to the server. A **result** is what
a run returns: one or more **items**. **Apply** is the approved write of one
item. **Editor mode** applies into the unsaved editor state; **server mode**
applies through the provider's apply callback.

### 4.1 Registration

1. Providers register actions by adding definitions to the filter
   `editor_ai_sidebar_actions` (section 5.2). The filter runs once per
   request that needs the list, after `init`, and its output is validated.
2. An action id is a string of the form `<provider>/<name>`, each part
   lower-case letters, digits and hyphens, 3 to 64 characters in total.
   Anything else is rejected with reason `invalid_id`.
3. A definition missing a required key (`id`, `label`, `group`, `result`,
   `apply`, `run`) or carrying a key of the wrong type is rejected. The
   rest of the list is kept.
4. Two definitions with the same id: the first one wins, the second is
   rejected with reason `duplicate_id`.
5. A definition whose `contract` is higher than the contract versions this
   plugin supports is rejected with reason `contract_unsupported`. A
   missing `contract` means version 1.
6. A server-mode action that declares any of the core post fields `title`,
   `excerpt`, `content`, `status`, `date`, `slug` or `author` as a target is
   rejected with reason `core_field_server_write`. Those fields are only
   ever changed in editor mode (case 47 explains why).
7. An editor-mode action whose `operation` is not one of the fixed editor
   operations (section 5.5) is rejected with reason `unknown_operation`.
8. An editor-mode `set_meta` action whose meta key is not registered with
   `show_in_rest` for the post type is rejected for that post type with
   reason `meta_not_in_rest`.
9. Every rejection is recorded in the event log (section 6) with the id, the
   reason and the provider's plugin file when it can be found, at most once
   per id and reason per day. The settings screen lists current rejections.
   No admin notice appears on other screens.
10. A provider plugin that is deactivated simply stops adding its
    definitions. Results it issued earlier are refused on apply with
    `action_gone` (case 48).

### 4.2 Showing the sidebar

11. The sidebar registers in the block editor for every post type that
    supports the editor and `show_in_rest`, filtered by
    `editor_ai_sidebar_post_types`. It does not register in the site editor
    or the widget editor.
12. A user sees the sidebar when they can `edit_post` on the current post
    and hold the view capability (default `edit_posts`).
13. The sidebar is a document-level sidebar with a toolbar button and an
    entry in the editor's options menu. Opening it is the first moment any
    request is made. Loading the editor alone makes no request.
14. On first open the sidebar fetches the action list for the post
    (`GET /actions?post_id=`). The list is kept for the life of the editor
    page and refetched only when the user presses "Refresh" or after an
    apply in server mode.
15. Actions are grouped and ordered: `summary`, `headlines`, `meta`,
    `alt_text`, `translation`, then `other`; within a group by `order`, then
    by label. Groups with no available actions are not shown.
16. Zero actions registered at all: the sidebar shows "No AI actions are
    installed." For users with the settings capability it adds one line
    linking to the README section on providers. No further request is made.
    The site can hide the toolbar button in this state with the filter
    `editor_ai_sidebar_show_when_empty` (default true).
17. Actions registered but none available for this post type: the sidebar
    shows "No AI actions apply to this kind of content."
18. An action that is registered but unavailable (the provider's
    `available` callback returns a reason string, or the user lacks the run
    capability) shows as a disabled button with the reason as text beneath
    it. Unavailable actions are listed so the editor knows they exist;
    actions hidden by post type or switched off by the administrator are
    not listed.
19. The whole sidebar switched off in settings, or the constant
    `EDITOR_AI_SIDEBAR_DISABLED` true: the script is not enqueued and the
    REST routes return 503 `sidebar_disabled`.

### 4.3 Selection-bound actions

20. An action may declare `selection` as a list of block names (for
    example `core/image`). Its button is enabled only while exactly one
    block of a listed name is selected. With nothing or the wrong block
    selected the button is disabled with the text "Select an image block"
    (built from the block type's title).
21. The run sends the selected block's name, client id and the attributes
    the action lists in `needs.selection_attributes` (for example `id`,
    `url`, `alt`, `caption`). Other attributes are not sent.
22. Selecting a different block while a selection-bound result is on screen
    does not move the result. The result stays tied to the block it was
    made for, and its header names that block ("Image 3 of 5").

### 4.4 Running an action

23. Clicking an available button starts a run. The button shows a busy
    state and a Cancel link. A second click on the same action while it
    runs does nothing.
24. The run request carries the post id, the action id, and only the
    context fields the action lists in `needs`: any of `title`, `excerpt`,
    `content` (serialised blocks), `text` (plain text of the content),
    `selection`, `featured_media`, `language`, `post_type`, `status`.
    Values come from the unsaved editor state, not from the saved post.
25. Context larger than the configured limit (default 512 KB of JSON) is not
    sent. The sidebar shows "This post is too long for this action." The
    server applies the same limit and returns 413 `context_too_large`.
26. Runs of different actions may overlap. At most one run per action per
    post per user is in flight; the server refuses a second with 409
    `run_in_progress` for as long as the first holds its lock (lock
    lifetime is the action timeout plus 30 seconds).
27. The server checks, in order: sidebar enabled, nonce, user logged in,
    post exists and the user can `edit_post`, action exists and is enabled,
    run capability, selection rules, context size and shape, the host rate
    limit, the action's `available` callback. The first failure is returned
    with its code (section 5.4) and no provider code runs.
28. The host rate limit is a guard against runaway clicks, not a spend cap:
    default 30 runs per user per 10 minutes across all actions, filterable.
    Over it: 429 `rate_limited` with a `retry_after` in seconds.
29. The provider's `run` callback receives a read-only `RunRequest`
    (section 5.3) and returns a `Result`, a `Pending`, or a `WP_Error`.
    Anything else is treated as a provider fault (case 37).
30. During `run`, the host watches for writes to the action's declared
    targets on this post: `post_updated`, `wp_insert_post_data` for this
    post id, and `added_post_meta`, `updated_post_meta`, `deleted_post_meta`
    for declared meta keys, and attachment alt changes for declared
    attachment targets. Any such write makes the host discard the result,
    return 500 `provider_wrote_during_run`, and record the event. Providers
    may write their own storage (suggestion tables, their own private meta
    keys that are not targets) freely.
31. A `Result` is validated (section 5.6): known item types, non-empty
    values, lengths within limits, at most 20 items, ids unique. Invalid:
    502 `invalid_result`, nothing shown but the message, details logged.
32. A valid result is given a result id (128 random bits, URL-safe) and a
    record is stored server side (section 6): user, post, action, item
    hashes, context hash, creation time, expiry (default 30 minutes), used
    flag. The response carries the result, its id, the context hash and the
    expiry.
33. A `Pending` carries a provider job reference and a suggested poll
    delay. The host stores a job record (user, post, action, provider
    reference, started, expiry) and returns 202 with a host job id. The
    provider reference is never sent to the browser.
34. The sidebar polls `GET /jobs/<id>` starting at the suggested delay
    (minimum 2 seconds), doubling up to 15 seconds, for at most the
    action's `max_wait` (default 10 minutes). Each poll calls the provider's
    `poll` callback, which returns a `Result`, a `Pending` or a `WP_Error`.
    A final result is validated and stored as in cases 31 and 32.
35. Cancel during a synchronous run aborts the browser request; the server
    finishes the call it started but discards the result when the client is
    gone, and no result id is issued to anyone. Cancel during a queued run
    calls `DELETE /jobs/<id>`, which calls the provider's optional `cancel`
    callback and deletes the job record.
36. Closing the editor tab with a job pending: the job record expires; the
    provider keeps whatever it keeps. Reopening the sidebar does not resume
    the old job in 0.1.0 (see open questions).

### 4.5 Failures during a run

37. The provider throws, returns the wrong type, or emits a PHP fatal
    caught by the shutdown handler: 500 `provider_error`. The editor sees
    "This action failed. The site log has details." The exception class and
    message go to the event log, not to the browser.
38. The provider returns a `WP_Error`: its code and message are passed
    through (message as plain text, at most 300 characters), with the HTTP
    status from its data when it is between 400 and 599, else 500. This is
    how providers report "switched off", "daily cap reached", "provider not
    configured" and "the model declined".
39. The request times out in the browser (the action's `timeout`, default
    60 seconds): the sidebar shows "This took too long. Try again, or ask
    the site owner to switch this action to queued mode." It does not retry
    on its own.
40. The network drops or the nonce has expired: `apiFetch` refreshes the
    nonce once through its middleware; a second failure shows "Your session
    has expired. Save your work and reload the editor." Nothing is retried
    beyond that.
41. A poll returns 404 because the job record expired: the sidebar shows
    "This result is no longer available. Run the action again."

### 4.6 Showing a result

42. The result appears under the action's button, in a panel headed by the
    action label, the time it was made, and any notes. Every string from the
    provider is rendered as a text node. No provider text is ever inserted
    as HTML anywhere in the sidebar.
43. Item types: `text` (one string), `list` (an ordered list of strings),
    `fields` (named strings with labels and optional limits). The result's
    `layout` is `single` (one item) or `choices` (two to 20 items, the user
    picks one). Each item may carry notes (`info` or `warning`), a score
    with a label, a language tag and a text direction.
44. Each item shows an Approve button (label from the action, default
    "Approve"), and, when the action is `editable`, an Edit control that
    turns the item into an input. Edited values show a length counter in
    user-perceived characters when the item or field declares a limit.
45. Warnings are shown above the Approve button and do not block it. A
    provider that wants to block approval returns no item, or an error.
46. Dismiss closes the result without applying. It calls
    `POST /results/<id>/dismiss`, which marks the record used and calls the
    provider's optional `dismiss` callback (for providers that record
    rejections). Dismiss never writes to the post.

### 4.7 Approving and applying

47. Approve is the only path to a write. The click sends
    `POST /results/<id>/apply` with the item id, `approved: true`, the
    value (always, edited or not), and the current context hash of the
    action's declared needs as the editor sees them now.
48. The server checks: sidebar enabled, nonce, the result record exists and
    has not expired (410 `result_expired`), belongs to this user (403
    `not_your_result`), matches the post and action in the URL body, is not
    used (409 `result_used`), the action still exists (410 `action_gone`),
    the user holds the apply capability and `edit_post` on the post, and the
    post is not locked by another user (409 `post_locked`).
49. A value that was not edited must hash to the stored item hash. A value
    that differs while the action is not `editable` is refused with 422
    `value_mismatch`. An edited value on an editable action is passed to the
    provider's `validate` callback when present; a `WP_Error` from it is
    returned with status 422 and the item stays open for more editing.
50. The context hash in the apply request differs from the stored one: the
    server returns 409 `context_changed` unless the request carries
    `confirm_stale: true`. The sidebar then shows "The post has changed
    since this result was made." with "Apply anyway" and "Run again". Apply
    anyway resends with `confirm_stale: true`.
51. Editor mode, server side: after the checks the record is marked used,
    the event is logged, the action `editor_ai_sidebar_applied` fires, and
    the response returns the editor operation and the final value. The
    server writes nothing to the post.
52. Editor mode, client side: the sidebar performs the operation through
    the editor's data stores (section 5.5). The post becomes dirty and
    unsaved. The user saves as usual. The editor's own undo reverts the
    change. A note under the item says "Applied to the editor. Save the post
    to keep it."
53. Editor mode, target moved: for `set_block_attribute`, the sidebar
    checks that the block with the stored client id still exists before it
    sends the apply request. If it is gone, no request is sent, the record
    stays unused until it expires, and the sidebar says "The block this was
    made for is gone." If the block disappears between the server's answer
    and the store update, the same message shows; the record is then used
    and the user runs the action again.
54. Editor mode, target changed: if the target's current value in the
    editor differs from its value at run time (for example the title was
    edited while headlines were generated), the sidebar asks "Replace the
    current title?" showing both, before sending the apply request.
55. Server mode: after the checks the server calls the provider's `apply`
    callback with an `ApplyRequest` (section 5.3). On success the record is
    marked used, the event is logged, `editor_ai_sidebar_applied` fires,
    and the response carries the provider's `ApplyOutcome`: a message, the
    list of targets changed, and optional links (for example to a new
    translation draft).
56. Server mode, sync back: for each changed target that the block editor
    also holds (registered meta, featured media, attachment alt of a block
    in the post) the sidebar updates the editor's copy without making the
    post dirty, so the next save does not send the old value back. Targets
    the editor does not hold need no sync.
57. Server mode, provider failure: a `WP_Error` from `apply` is returned
    with its status and message. The record stays unused so the user may
    retry, unless the provider sets `final: true` in the error data. An
    exception is 500 `provider_error`, record unused, event logged.
58. Server mode with unsaved changes: when the post has unsaved edits and
    the server-mode action declares `requires_saved: true`, the Approve
    button reads "Save and approve"; the sidebar saves the post first,
    waits for the save to finish, then sends the apply. A failed save stops
    the apply.
59. A user who can run but cannot apply sees the result with the Approve
    button disabled and the text "You can view this but an editor must
    approve it." plus a Copy button. Copy never writes to the site.
60. Approve on a `choices` result applies exactly one item. The record is
    used afterwards; to apply a different choice the user runs again unless
    the action declares `reapply: true`, in which case the record stays
    usable for other items until it expires, and each apply is logged.
61. No apply path changes post status, publishes, schedules, or trashes.
    Any provider `ApplyOutcome` that reports a status change on the current
    post is logged as a contract violation.

### 4.8 Mapping of the description's five action kinds

These are examples of how the sibling providers are expected to register.
They bind nothing in those projects; each provider's own PRD decides.

62. Summary: group `summary`, `needs` title and text, layout `single`, item
    `list` or `text`. Either server mode (the provider approves its own
    stored candidate) or editor mode `insert_blocks` at the start of the
    content.
63. Headlines: group `headlines`, `needs` title, text and excerpt, layout
    `choices`, item `text` with score and notes, editor mode `set_title`,
    `editable: true`.
64. Meta: group `meta`, item `fields` (title and description with limits),
    server mode with targets naming the meta keys, or editor mode
    `set_meta` when the meta is registered for REST. Sync back (case 56)
    applies.
65. Alt text: group `alt_text`, `selection` `core/image`, needs
    `selection_attributes` `id`, `url` and `alt`, item `text`, editor mode
    `set_block_attribute` on `alt`. A provider may also offer a server-mode
    action that writes the attachment's own alt field; it then says in its
    label that existing posts keep their copy.
66. Translation: group `translation`, `needs` title, content and language,
    usually queued (case 33), item `fields` showing a preview, server mode
    whose apply creates a draft in the target language and returns a link.
    The draft is never published by the apply.

### 4.9 Edge cases

67. New post never saved (an auto-draft): actions run normally; server-mode
    actions with `requires_saved: true` save first (case 58).
68. Post in the trash or deleted while the editor is open: run and apply
    return 404 `post_missing`.
69. User logged out in another tab: 401 from every route; the sidebar shows
    the session message from case 40.
70. Two editors on one post: the block editor's post lock already stops the
    second from editing. Server-mode apply checks the lock (case 48).
71. Right-to-left interface: the sidebar mirrors. Items carrying `dir` are
    rendered with that direction regardless of the interface direction.
72. A result in a language other than the interface: the item's language tag
    is set on its element so screen readers pronounce it correctly.
73. Very long items (a translation preview): items over 2,000 characters are
    shown collapsed with "Show all"; the full value is still what is
    approved.
74. Clock skew between server and browser: expiry is checked on the server
    only; the sidebar shows remaining time computed from the server's
    `expires_in` seconds, not from timestamps.
75. A provider returns a `Result` with zero items: shown as "No suggestions
    this time." with the provider's notes. No record is stored.
76. Multisite: everything is per site. Network activation registers on each
    site; settings are per site.
77. The editor is in code editor mode: editor operations still apply through
    the data stores; `insert_blocks` is disabled with "Switch to the visual
    editor to insert blocks."
78. Object cache present: result and job records use transients, so they
    live in the object cache. Losing them early gives `result_expired`,
    never a write.
79. Page caching or a CDN in front of admin: every route sends
    `Cache-Control: no-store` and is POST or authenticated GET, so nothing
    is cached.

## 5. Public interface

Names in this section are this project's own.

### 5.1 PHP functions

| Function | Returns | Notes |
|---|---|---|
| `editor_ai_sidebar_actions( ?int $post_id = null, ?int $user_id = null )` | `array<string, ActionDefinition>` | Validated, enabled actions; filtered by post type when a post is given. For tests and other plugins. |
| `editor_ai_sidebar_rejected_definitions()` | `array<int, array{id: string, reason: string}>` | The rejections from case 9 for this request. |
| `editor_ai_sidebar_contract_versions()` | `int[]` | Supported contract versions. 0.1.0 returns `[1]`. |

There is no `register_action` function. The filter is the one way in.

### 5.2 The registration filter

`apply_filters( 'editor_ai_sidebar_actions', array $actions )`

`$actions` is an array keyed by action id. Each value is an array:

| Key | Type | Required | Default | Meaning |
|---|---|---|---|---|
| `id` | string | yes | | `<provider>/<name>`, case 2 |
| `contract` | int | no | 1 | Contract version |
| `label` | string | yes | | Button text, translated by the provider |
| `description` | string | no | `''` | One line under the button |
| `group` | string | yes | | `summary`, `headlines`, `meta`, `alt_text`, `translation`, `other` |
| `order` | int | no | 10 | Order within the group |
| `icon` | string | no | `''` | A Dashicons slug |
| `post_types` | string[] | no | all sidebar post types | Where it appears |
| `needs` | array | no | `['title','text']` | Context fields (case 24); may include `selection_attributes` |
| `selection` | string[] or null | no | null | Block names (case 20) |
| `result` | array | yes | | `layout` (`single` or `choices`), `item_type` (`text`, `list`, `fields`), `fields` (for `fields`: name to label and optional `limit`) |
| `apply` | array | yes | | `mode` (`editor` or `server`), `operation` (editor mode), `targets` (list of target strings, section 5.5), `requires_saved` (bool), `reapply` (bool), `label` (button text) |
| `editable` | bool | no | false | Case 49 |
| `run_capability` | string | no | `edit_post` | Meta capabilities get the post id |
| `apply_capability` | string | no | `edit_post` | As above |
| `timeout` | int | no | 60 | Seconds, 5 to 300 |
| `max_wait` | int | no | 600 | Seconds for queued runs, 30 to 3,600 |
| `run` | callable | yes | | `fn( RunRequest $r ): Result\|Pending\|WP_Error` |
| `poll` | callable | queued only | | `fn( string $provider_ref, RunRequest $r ): Result\|Pending\|WP_Error` |
| `cancel` | callable | no | | `fn( string $provider_ref ): void` |
| `apply_callback` | callable | server mode | | `fn( ApplyRequest $a ): ApplyOutcome\|WP_Error` |
| `validate` | callable | no | | `fn( Item $edited, RunRequest $original ): true\|WP_Error` |
| `dismiss` | callable | no | | `fn( string $result_id, Item[] $items, int $user_id ): void` |
| `available` | callable | no | | `fn( int $post_id, int $user_id ): true\|string`; a string is the reason shown |

A `Pending` returned by an action with no `poll` callback is a provider
fault (case 37).

Other filters:

| Filter | Arguments | Default | Purpose |
|---|---|---|---|
| `editor_ai_sidebar_post_types` | `string[] $types` | case 11 | Where the sidebar appears |
| `editor_ai_sidebar_view_capability` | `string $cap` | `edit_posts` | See the sidebar |
| `editor_ai_sidebar_settings_capability` | `string $cap` | `manage_options` | Settings screen |
| `editor_ai_sidebar_title` | `string $title` | "AI actions" | Sidebar title |
| `editor_ai_sidebar_show_when_empty` | `bool $show` | true | Case 16 |
| `editor_ai_sidebar_rate_limit` | `array{runs: int, window: int}` | 30 per 600 s | Case 28 |
| `editor_ai_sidebar_context_limit` | `int $bytes` | 524288 | Case 25 |
| `editor_ai_sidebar_result_ttl` | `int $seconds` | 1800 | Case 32, 60 to 86,400 |
| `editor_ai_sidebar_action_enabled` | `bool $on, string $id, int $post_id` | settings | Turn an action off per post |

Actions:

| Action | Arguments | When |
|---|---|---|
| `editor_ai_sidebar_run_started` | `string $action_id, int $post_id, int $user_id` | Before `run` |
| `editor_ai_sidebar_result_issued` | `string $action_id, int $post_id, int $user_id, string $result_id, int $item_count` | Case 32 |
| `editor_ai_sidebar_run_failed` | `string $action_id, int $post_id, int $user_id, string $code` | Any failure in 4.5 |
| `editor_ai_sidebar_applied` | `string $action_id, int $post_id, int $user_id, string $result_id, string $item_id, bool $edited, string $mode` | Cases 51 and 55 |
| `editor_ai_sidebar_dismissed` | `string $action_id, int $post_id, int $user_id, string $result_id` | Case 46 |
| `editor_ai_sidebar_contract_violation` | `string $action_id, string $code, array $detail` | Cases 30, 37, 61 |

No action passes item text, so a logging plugin cannot copy model output
by accident. A listener that wants the text reads it from the provider.

### 5.3 Value objects

All immutable, namespace `IndianOpinion\EditorAiSidebar`.

- `RunRequest`: `actionId()`, `postId()`, `userId()`, `context()` (array of
  the declared needs only), `selection()` (`?array` with `name`,
  `clientId`, `attributes`), `language()` (BCP 47 from the editor, else the
  site locale), `contextHash()`.
- `Result`: built with `Result::single( Item $item, array $notes = [] )` or
  `Result::choices( array $items, array $notes = [] )`. Notes are
  `Note` objects.
- `Item`: `Item::text( string $id, string $value )`,
  `Item::list( string $id, array $values )`,
  `Item::fields( string $id, array $values )`, with `withNote( Note )`,
  `withScore( float $score, string $label )`, `withLanguage( string $tag,
  string $dir = 'ltr' )`. Item ids match `[a-z0-9-]{1,32}`.
- `Note`: `Note::info( string $text )`, `Note::warning( string $text )`.
- `Pending`: `Pending::job( string $provider_ref, int $poll_after_seconds
  = 3 )`.
- `ApplyRequest`: `actionId()`, `postId()`, `userId()`, `resultId()`,
  `item()` (the final `Item`), `edited()`, `confirmStale()`.
- `ApplyOutcome`: `ApplyOutcome::done( string $message, array $targets,
  array $links = [] )`. Links are arrays with `label` and an admin URL on
  the same site.

The host also accepts plain arrays of the same shapes from providers that
do not want to depend on the classes; section 5.6 is the array form.

### 5.4 REST API

Namespace `editor-ai-sidebar/v1`. Cookie auth with the `wp_rest` nonce, or
application passwords. Every route has a permission callback. Every
response sends `Cache-Control: no-store`.

| Method and route | Body or query | Success |
|---|---|---|
| `GET /actions` | `post_id` | 200 list of action descriptors: `id`, `label`, `description`, `group`, `order`, `icon`, `needs`, `selection`, `result`, `apply` (without callbacks), `editable`, `timeout`, `max_wait`, `available` (bool), `reason` (string), `can_apply` (bool) |
| `POST /actions/<id>/run` | `post_id`, `context` (object) | 200 result envelope, or 202 `{ job_id, poll_after }` |
| `GET /jobs/<job_id>` | | 200 envelope, 202 pending, 404 gone |
| `DELETE /jobs/<job_id>` | | 204 |
| `POST /results/<result_id>/apply` | `post_id`, `action_id`, `item_id`, `value`, `approved` (must be true), `context_hash`, `confirm_stale` | 200 `{ mode, operation, targets, value, message, links }` |
| `POST /results/<result_id>/dismiss` | `post_id`, `action_id` | 204 |

Result envelope: `{ result_id, action_id, post_id, layout, items: [ { id,
type, value, notes, score, score_label, lang, dir } ], notes, context_hash,
expires_in }`.

`approved` missing or not `true` is 400 `approval_missing`. This keeps a
script that replays a run response from applying it by accident.

Error codes (body `{ code, message, data: { status } }`):
`sidebar_disabled` 503, `rest_forbidden` 401/403, `post_missing` 404,
`action_unknown` 404, `action_disabled` 403, `action_unavailable` 409,
`selection_required` 400, `context_invalid` 400, `context_too_large` 413,
`rate_limited` 429, `run_in_progress` 409, `provider_error` 500,
`provider_wrote_during_run` 500, `invalid_result` 502, `job_gone` 404,
`result_expired` 410, `not_your_result` 403, `result_used` 409,
`action_gone` 410, `approval_missing` 400, `value_mismatch` 422,
`context_changed` 409, `post_locked` 409, plus any code a provider returns
through a `WP_Error`.

### 5.5 Editor operations and targets

The sidebar implements exactly these editor operations, through the
editor's data stores, never by touching the DOM:

| Operation | Item type | Effect |
|---|---|---|
| `set_title` | `text` | Edits the post title |
| `set_excerpt` | `text` | Edits the excerpt |
| `insert_blocks` | `text` or `list` | Inserts a paragraph (text) or a list block (list) at `position`: `start`, `end`, or `after_selection`. Values are escaped into block content. |
| `set_block_attribute` | `text` | Sets `attribute` on the block the result was made for. The attribute must be named in the action and be a string attribute of that block type. |
| `set_meta` | `text` or `fields` | Edits registered meta keys; a `fields` item maps field names to meta keys through `apply.meta_map` |
| `copy` | any | Copies the value to the clipboard; writes nothing |

Target strings for server mode: `meta:<key>`, `attachment_alt:<from
selection>` or `attachment_alt:<id>`, `featured_media`, `new_post`
(creates a separate draft), `provider` (the provider's own storage only).
Targets drive the write watch (case 30) and the sync back (case 56).

### 5.6 Array form of a result

```
{ "layout": "single" | "choices",
  "items": [ { "id": "a", "type": "text" | "list" | "fields",
               "value": string | string[] | { name: string },
               "notes": [ { "level": "info" | "warning", "text": string } ],
               "score": number?, "score_label": string?,
               "lang": string?, "dir": "ltr" | "rtl"? } ],
  "notes": [ ... ] }
```

Limits: 20 items; text values up to 20,000 characters; list values up to
50 entries of up to 1,000 characters; fields up to 10 names; notes up to 10
per item, 300 characters each. Strings must be valid UTF-8 and are stored
and returned as given (no trimming beyond leading and trailing white space).

### 5.7 JavaScript

The sidebar script registers one plugin with the editor's plugin API. It
uses `PluginSidebar` and `PluginSidebarMoreMenuItem` from the editor
package when present, falling back to the older edit-post package. It
exposes no public JavaScript API in 0.1.0, and no provider script is loaded
or required.

### 5.8 WP-CLI

| Command | Effect |
|---|---|
| `wp editor-ai-sidebar actions [--post=<id>] [--user=<id>] [--format=table\|json]` | Lists registered actions, availability and rejections |
| `wp editor-ai-sidebar log [--limit=<n>] [--format=table\|json]` | Prints the event log |

There is no CLI to run or apply an action. Apply needs a human click.

### 5.9 Constants

`EDITOR_AI_SIDEBAR_DISABLED` (bool): case 19, overrides settings.

## 6. Data

- **Settings**: one option, `editor_ai_sidebar_settings`, autoloaded:
  enabled flag, list of disabled action ids. Nothing else.
- **Result records**: one transient per result,
  `editor_ai_sidebar_r_<result_id>`, holding user id, post id, action id,
  item ids and SHA-256 hashes of each item's canonical JSON, the item
  values (needed to return the final value on apply and for `dismiss`),
  context hash, created time, used flag. Lifetime is the result TTL. The
  item values are model output about a draft; they live no longer than the
  TTL.
- **Job records**: one transient per job, `editor_ai_sidebar_j_<job_id>`,
  holding user id, post id, action id, provider reference, start time.
  Lifetime is `max_wait` plus 60 seconds.
- **Run locks and rate counters**: transients keyed by user, post and
  action, with lifetimes from cases 26 and 28.
- **Event log**: one option, `editor_ai_sidebar_log`, not autoloaded, a ring
  of the latest 500 events: time, event, action id, post id, user id,
  result id, code. Never item text, never context.
- Nothing is stored in post meta. Nothing is written to the post by this
  plugin, ever; only providers write, and only on apply.
- **Uninstall** deletes the two options and every transient with the
  plugin's prefix (by prefix query on the options table; on an external
  object cache the transients expire on their own). It touches nothing a
  provider stored.

## 7. Configuration and sensible defaults

| Setting | Default | Where |
|---|---|---|
| Sidebar enabled | on | Settings, Writing, "AI actions" section; constant overrides |
| Disabled actions | none | Same screen, a checkbox per registered action |
| Sidebar title | "AI actions" | filter |
| Post types | all editor post types in REST | filter |
| View capability | `edit_posts` | filter |
| Settings capability | `manage_options` | filter |
| Rate limit | 30 runs per 10 minutes per user | filter |
| Context limit | 512 KB | filter |
| Result lifetime | 30 minutes | filter |
| Show when empty | yes | filter |

The settings section also shows the registered actions with their provider,
mode and targets, and the rejected definitions with reasons. It is the one
admin screen.

## 8. Security and privacy

- Every route has a permission callback, a nonce under cookie auth, and
  object-level checks (`edit_post` on the post, the action's capabilities).
- The approval guarantee is enforced on the server, not only in the
  browser: an apply needs a result id issued to the same user for the same
  post and action, unexpired and unused, `approved: true`, and a value that
  matches what was issued unless the action allows editing.
- Result ids are 128 random bits from a CSPRNG. They are compared in
  constant time.
- Provider text is untrusted. It is rendered as text nodes; inserted into
  blocks only through block attributes with escaping; never passed to
  `dangerouslySetInnerHTML` or an HTML block.
- Error messages to the browser never carry stack traces, file paths, keys,
  provider URLs or model names unless the provider put them in its
  `WP_Error` message.
- Context sent to a provider is limited to what the action declares. The
  sidebar never sends the whole editor state to every action.
- Context and item text are never logged. The event log holds ids and codes.
- The settings screen uses the Settings API with its nonce and capability.
- The plugin makes no outbound HTTP request.
- Privacy policy text: the plugin adds a suggested paragraph saying that
  installed AI actions may send post content to the services their
  providers use, and that this plugin itself sends nothing anywhere.

## 9. Performance budget

- Editor load: one script, under 40 KB minified, and one small stylesheet,
  enqueued only on the block editor for enabled post types. No request at
  load.
- Opening the sidebar: one `GET /actions`, server time under 50 ms with ten
  registered actions excluding the providers' own `available` callbacks.
  Providers are told in the README to keep `available` cheap.
- Host overhead per run and per apply, excluding the provider: under 30 ms
  and at most three transient reads and two writes.
- Polling: never faster than every 2 seconds per job, backing off to 15.
- The registration filter runs at most once per request and its validated
  output is memoised for that request.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later.
- Block editor only. The post editor in WordPress 6.4 and later, including
  the sidebar API moving to the editor package in later versions.
- Works with any theme. Does not load on the front end.
- Hosts with page caching, object caching and CDNs in front: case 79.
- Works with application passwords for testing the REST contract.
- Multisite: per site (case 76).
- Providers on contract version 1 keep working on every 0.x release. A new
  contract version is additive; the host supports the old one alongside it.
- Translation-ready (text domain `editor-ai-sidebar`, script translations
  through the standard JSON files), right-to-left safe, keyboard operable,
  results announced through a polite live region.

## 11. Observability

- The event log (section 6) records: run started, result issued, run
  failed with code, applied with mode and edited flag, dismissed, contract
  violation, definition rejected. `wp editor-ai-sidebar log` prints it.
- The settings section shows registered actions, rejected definitions, and
  a count of applies and failures per action over the log window.
- With `WP_DEBUG_LOG` on, provider exceptions are written to the PHP error
  log with the action id and exception class.
- Every action hook in 5.2 fires, so a site can feed its own monitoring.
- Response header `X-Editor-AI-Sidebar-Contract: 1` on every route, so an
  operator can see which contract a site runs.

## 12. Acceptance tests

PHP tests run under PHPUnit with a stub layer. Client tests run under a
JavaScript test runner against the sidebar components and a mocked editor
store. One test per case at least.

| Test | Case |
|---|---|
| T1 A valid definition from the filter appears in `editor_ai_sidebar_actions()` | 1 |
| T2 Ids `Bad/Name`, `noslash`, a 65-character id are rejected with `invalid_id` | 2 |
| T3 Missing `run` rejects that definition only; the others remain | 3 |
| T4 Duplicate id: first kept, second rejected `duplicate_id` | 4 |
| T5 `contract: 2` rejected `contract_unsupported`; missing contract treated as 1 | 5 |
| T6 Server mode with target `title` rejected `core_field_server_write` | 6 |
| T7 Editor mode with operation `run_script` rejected `unknown_operation` | 7 |
| T8 `set_meta` on a key not in REST rejected `meta_not_in_rest` for that type only | 8 |
| T9 Rejections logged once per id and reason per day and listed in settings | 9 |
| T10 Apply on a result whose action vanished returns 410 `action_gone` | 10, 48 |
| T11 Script enqueued for `post` and a custom type in REST; not in the site editor | 11 |
| T12 User without `edit_post` gets 403 on `GET /actions`; sidebar not registered | 12 |
| T13 Loading the editor makes no REST request; opening makes exactly one | 13 |
| T14 List fetched once; refetched after Refresh and after a server apply | 14 |
| T15 Groups render in the fixed order; empty groups hidden | 15 |
| T16 Zero actions: empty state text, settings link only for admins, no further request; button hidden when the filter says so | 16 |
| T17 Actions exist but none for `page`: the post-type empty text | 17 |
| T18 `available` returns a reason: button disabled with that reason; no run possible (server 409 `action_unavailable`) | 18, 27 |
| T19 Constant set: no enqueue, every route 503 | 19 |
| T20 Selection-bound action disabled without an image block selected, enabled with one | 20 |
| T21 Only listed selection attributes reach `RunRequest` | 21 |
| T22 Result stays tied to its block after the selection changes | 22 |
| T23 Double click sends one run | 23 |
| T24 Context carries unsaved title and only declared needs | 24 |
| T25 Context over the limit: client message, server 413 | 25 |
| T26 Second concurrent run 409 `run_in_progress`; lock clears after timeout | 26 |
| T27 Each failed check returns its code and the provider `run` is never called | 27 |
| T28 31st run in the window returns 429 with `retry_after` | 28 |
| T29 `run` returning a string is `provider_error` | 29, 37 |
| T30 `run` that updates a declared meta key gets 500 `provider_wrote_during_run`; writing an undeclared private key is allowed | 30 |
| T31 Result with 21 items, an empty value, or an unknown type is 502 `invalid_result` | 31 |
| T32 Valid result stores a record with hashes and returns `expires_in` | 32 |
| T33 `Pending` gives 202 and the provider reference never appears in any response | 33 |
| T34 Poll schedule doubles from the suggested delay to 15 s and stops at `max_wait` | 34 |
| T35 Cancel aborts a sync run (no result id issued) and calls `cancel` for a queued run | 35 |
| T36 Job record expires after `max_wait` plus 60 s | 36 |
| T37 Exception in `run`: 500 generic message; class and message in the log | 37 |
| T38 Provider `WP_Error` with status 429 passes through with its message as text | 38 |
| T39 Client timeout shows the timeout message and does not retry | 39 |
| T40 Expired nonce refreshed once; second failure shows the session message | 40 |
| T41 Poll after job expiry shows the gone message | 41 |
| T42 Provider text containing `<img onerror>` renders as literal text | 42 |
| T43 Each item type and both layouts render; score, notes, lang and dir shown | 43 |
| T44 Edit control only on editable actions; counter counts graphemes | 44 |
| T45 Warnings show and do not disable Approve | 45 |
| T46 Dismiss marks the record used, calls `dismiss`, writes nothing | 46 |
| T47 No write happens without the apply request; apply without `approved: true` is 400 | 47 |
| T48 Each apply check: expired 410, other user 403, used 409, missing capability 403, locked 409 | 48 |
| T49 Changed value on a non-editable action 422; on an editable one goes to `validate` and a `WP_Error` from it is 422 | 49 |
| T50 Changed context hash: 409 `context_changed`; with `confirm_stale` it applies | 50 |
| T51 Editor-mode apply on the server writes nothing to the post and fires `applied` | 51 |
| T52 `set_title` dispatches an editor edit; post dirty, not saved; undo restores the old title | 52 |
| T53 Block removed before apply: no request sent, gone message shown | 53 |
| T54 Title edited since the run: confirmation shows both values before applying | 54 |
| T55 Server mode calls `apply_callback` once and returns its outcome and links | 55 |
| T56 After a server apply to a registered meta key, the editor store holds the new value and the post is not dirty; a following save does not send the old value | 56 |
| T57 `WP_Error` from `apply_callback` leaves the record unused; with `final: true` it is used | 57 |
| T58 `requires_saved` with unsaved edits saves first; a failed save stops the apply | 58 |
| T59 Run without apply capability: Approve disabled, Copy works, apply route 403 | 59 |
| T60 Choices: second apply 409 unless `reapply` is true | 60 |
| T61 An outcome reporting a status change on the post fires `contract_violation` | 61 |
| T62 Example summary provider (test fixture) in editor mode inserts a list block at the start | 62 |
| T63 Example headline provider: choices, `set_title`, editable | 63 |
| T64 Example meta provider in server mode syncs back both meta keys | 64 |
| T65 Example alt provider sets `alt` on the selected image block only | 65 |
| T66 Example translation provider: queued, server apply returns a draft link; current post status unchanged | 66 |
| T67 Auto-draft with `requires_saved`: saves then applies | 67 |
| T68 Trashed post: run and apply 404 | 68 |
| T69 Logged out: 401 and the session message | 69 |
| T70 Locked post: server apply 409 `post_locked` | 70 |
| T71 RTL interface mirrors; an item with `dir: rtl` renders RTL in an LTR interface | 71 |
| T72 Item `lang` set on the element | 72 |
| T73 Item over 2,000 characters collapsed; the full value is applied | 73 |
| T74 Remaining time uses `expires_in`, not client clocks | 74 |
| T75 Zero-item result: "No suggestions" and no record stored | 75 |
| T76 Multisite: settings and records separate per site | 76 |
| T77 Code editor mode disables `insert_blocks` only | 77 |
| T78 Record evicted early: apply 410, nothing written | 78 |
| T79 Every route sends `Cache-Control: no-store` | 79 |

The example providers in T62 to T66 are test fixtures and examples in
`examples/`, with canned results. They make no model call.

## 13. Open questions

1. Should 0.1.0 resume a pending job after an editor reload (case 36), by
   listing the user's open jobs for the post in `GET /actions`?
2. Should a provider be allowed to ship its own result renderer through a
   JavaScript hook in a later version, or should the fixed item types stay
   the only way to show results?
3. Classic editor: a reduced meta box with the same contract, or nothing?
4. Should the host offer a simple daily run cap per action as a safety net
   for providers that forget their own, or leave caps entirely to providers
   and `spend-meter`?
5. Build tooling: ship built assets made with the standard WordPress
   scripts package, or plain scripts with no build step using the global
   `wp` packages?
6. Is a transient the right home for result records on sites without a
   persistent object cache, given the options table churn, or should a
   small custom table be used?
7. Should `reapply` (case 60) be the default for `choices` layouts, since an
   editor often tries two headlines in a row?
8. Should providers be able to declare that a result may be applied by a
   different user than the one who ran it (an author runs, an editor
   approves later), which would need stored results beyond the TTL?

## 14. Lessons from production

These come from the public PRDs of the sibling projects and from the block
editor's documented behaviour. They are written as rules.

1. Never write to a field the editor also holds while the editor is open,
   unless you update the editor's copy too. The next save sends the old
   value back and your write disappears.
2. Change the title, excerpt and content only in the editor state. Let the
   user save. The editor's undo then works, and the revision history shows
   a human save.
3. Read the unsaved state. An editor asks for help on what is on screen,
   not on what was saved ten minutes ago.
4. The block editor keeps its own copy of image alt text in the post.
   Changing the attachment does not change posts already written. Say which
   one an action changes.
5. Do not trust the browser for approval. A server-issued, single-use,
   user-bound result id is what makes "nothing is written without a click"
   true.
6. Model output is untrusted input. Render it as text. Escape it into
   block attributes. Never as HTML.
7. Long calls die at proxies and load balancers long before PHP gives up.
   Anything that can take more than a few tens of seconds belongs in a
   queue with polling.
8. Count lengths in user-perceived characters. Indic scripts carry many
   combining marks; a code point count cuts them short.
9. Use the editor's own REST fetch helper. It refreshes the nonce in a long
   editing session; a hand-rolled fetch fails after the nonce expires and
   loses the editor's work context.
10. Never log model output or post content in a shared log. Ids and codes
    are enough to debug, and they leak nothing.
11. Make the failure message tell the editor what to do next. "Failed" alone
    produces a support ticket.
12. A host must work with zero providers. Plugins are deactivated in any
    order, and an empty sidebar must never throw.
13. Validate every definition and keep the good ones. One broken provider
    must not take down the others.
