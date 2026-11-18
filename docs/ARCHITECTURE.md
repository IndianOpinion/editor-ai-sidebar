# Architecture

This document describes how editor-ai-sidebar 0.1.0 is built from
[PRD.md](PRD.md), and records every decision taken on the way: the owner's
rulings on the PRD's open questions, and the choices the builder made where
the PRD left room.

## Modules

### PHP (`src/`, namespace `IndianOpinion\EditorAiSidebar`)

| File | Role |
|---|---|
| `Plugin.php` | Wiring and WordPress hooks; activation, deactivation, multisite install, purge cron, capability and post-type helpers |
| `Registry.php` | Runs the `editor_ai_sidebar_actions` filter once per request, validates each definition, keeps the good ones, records rejections (cases 1 to 10) |
| `ActionDefinition.php` | A validated, normalised definition and its REST descriptor |
| `Service.php` | The host's rules: list, run, poll, cancel, apply, dismiss. Returns bodies or throws `ApiError` |
| `Rest.php` | Route registration, the shared permission callback, and the response headers filter |
| `ResultValidator.php` | Turns provider return values (objects or arrays) into checked value objects; section 5.6 limits |
| `Canonical.php` | Canonical JSON and the context and item hashes |
| `WriteWatch.php` | Watches the post and declared targets while a provider runs (case 30) |
| `FatalGuard.php` | Turns a PHP fatal inside a provider call into `provider_error` (case 37) |
| `Limiter.php` | Per-user rate limit (case 28) and per-action daily cap (ruling 4) |
| `Store/RecordStore.php`, `Store/DbRecordStore.php`, `Store/Schema.php` | Result, job and lock records in one small table (ruling 6) |
| `EventLog.php` | Ring of 500 events in one non-autoloaded option; ids and codes only |
| `Settings.php`, `AdminSettings.php` | The settings option and the "AI actions" section on Settings, Writing |
| `Assets.php` | Enqueues the scripts in the post editor only, with an inline config |
| `Cli.php` | `wp editor-ai-sidebar actions` and `log` |
| `Privacy.php` | Suggested privacy policy text |
| `Uninstaller.php` | Removes options, transients, cron and the table on every site |
| `Messages.php` | Translated error messages, each saying what to do next |
| Value objects | `RunRequest`, `Result`, `Item`, `Note`, `Pending`, `ApplyRequest`, `ApplyOutcome` |

### JavaScript (`assets/js/`, plain scripts, no build step)

| File | Role |
|---|---|
| `core.js` | Logic with no WordPress dependency: canonical JSON, SHA-256, grouping, context building, poll schedule, grapheme counting, error messages, and the `Controller` state machine. Loads as a browser global or a CommonJS module |
| `view.js` | Pure render functions from controller state to an element tree, given `createElement` and the components. Translated strings |
| `sidebar.js` | Glue: the editor adapter (reads the unsaved state through `core/editor` and `core/block-editor`, writes only through store actions), `apiFetch`, and `registerPlugin` with `PluginSidebar` from `wp.editor`, falling back to `wp.editPost` |

The split lets Node's built-in test runner load the exact files the editor
runs, with a mocked editor and a scripted `apiFetch`.

## Data flow

```
Editor opens         no request; inline config says whether any action exists
Sidebar opens        GET /actions?post_id=     -> descriptors (+ pending_job for resume)
Click                POST /actions/<id>/run    -> checks (case 27) -> lock -> write watch
                                                  -> provider run -> validate
                                                  -> 200 envelope + result record
                                                  or 202 { job_id } + job record
Queued              GET /jobs/<id> (2 s .. 15 s back-off, up to max_wait) -> provider poll
Approve             POST /results/<id>/apply   -> checks (case 48 to 50) -> claim record
                       editor mode: server returns the operation; the sidebar edits the store
                       server mode: server calls apply_callback; the sidebar syncs its copy
Dismiss             POST /results/<id>/dismiss -> record used -> provider dismiss
```

Every response from the namespace carries `Cache-Control: no-store` and
`X-Editor-AI-Sidebar-Contract: 1`, errors included (added in
`rest_post_dispatch`). A 429 also carries `Retry-After`.

## Rulings on the PRD's open questions

1. **Resume after reload: yes, within the result lifetime.** `GET /actions`
   adds `pending_job` (`job_id`, `poll_after`, `elapsed`, `client_id`) to an
   action when the user has an unexpired job for that post and action that
   started less than one result lifetime ago. The sidebar resumes polling
   with the remaining `max_wait`. Reason: queued runs exist for slow work,
   and a reload should not throw that work away. The job record already
   lived for `max_wait` plus 60 seconds, so the cost is one indexed query.
2. **Fixed item types only; no provider JavaScript hook.** The sidebar
   renders `text`, `list` and `fields` and nothing else, and loads no
   provider script. Reason: provider text is untrusted, and a renderer hook
   would let any provider put HTML in the editor.
3. **Nothing for the classic editor.** The script registers only in the
   post editor's block mode. Reason: the editor-state writes and the
   sync-back depend on the block editor's stores.
4. **The host adds a daily run cap per action.** Definition key `daily_cap`
   (default 200, 1 to 100,000), filter `editor_ai_sidebar_daily_cap`
   (`$cap, $action_id`; returning 0 switches the host cap off). The count is
   per site, per UTC day, and counts runs that reached the provider. It is a
   counter row in the record table, taken with one conditional
   `UPDATE ... SET hits = hits + 1 WHERE hits < cap` after the run lock, so
   concurrent runs cannot exceed the cap. Over
   it: 429 `daily_cap_reached`, logged. Reason: a safety net for providers
   that forget their own; it is not a spend cap.
5. **Plain scripts, no build step, using the WordPress globals.** Reason:
   nothing to install to change a line, and the shipped file is the tested
   file. Cost: the scripts ship unminified, about 54 KB (15 KB gzipped). The PRD budget is now 60 KB unminified for that
   reason; see "Budgets" below.
6. **A small custom table for result records.** `{prefix}editor_ai_sidebar_records`
   per site, created with `dbDelta` on activation and lazily on the first
   admin or REST request (which also covers must-use installs), then checked
   with `SHOW TABLES` before the schema version is stored. Reason: no
   options-table churn on sites without a persistent object cache, an
   atomic single-use claim, and indexed lookups for resume. Job records and
   run locks live in the same table (column `kind`), because resume needs to
   list a user's jobs and a lock needs an atomic insert; transients offer
   neither. Expired rows are removed hourly by WP-Cron and are never honoured
   in the meantime.
7. **`reapply` is not the default.** A `choices` result is used after one
   apply unless the action sets `reapply: true`. With `reapply`, each item
   may be applied once while the record lives, and each apply is logged.
   Reason: one approval, one write, unless the provider asks otherwise.
8. **Only the user who ran a result can approve it.** Any other user gets
   403 `not_your_result`, whatever their role. Reason: the approval is a
   statement by the person who saw the result in context.

## Builder decisions

### Contract details

- **Ids.** Result and job ids are 32 lower-case hex characters (128 bits
  from `random_bytes`). Hex avoids case-insensitive collation surprises in
  MySQL; `find` also compares the stored id with `hash_equals`.
- **Canonical JSON.** Object keys sorted by byte order, no white space,
  Unicode, slashes and line terminators unescaped, integral floats written
  as integers, and an empty array written as `{}`. The context root, the
  selection attributes and field values are objects, and lists are never
  empty, so `{}` is the only reading that both PHP and JavaScript agree on.
  `tests/fixtures/hash-vectors.json` is checked by both test suites.
- **Context rules.** Every declared need must be present, with its type
  (`featured_media` an integer, `language` a BCP 47 tag, the rest strings).
  `selection` is added to the needs automatically for block-bound actions.
  Selection attributes must be scalars or null. Size is measured on the
  canonical JSON.
- **Descriptors.** `GET /actions` returns `needs` as a list of field names
  and `selection_attributes` as its own list, plus `pending_job` (ruling 1).
- **Layouts.** `single` needs exactly one item; `choices` needs two to 20.
  Zero items is allowed for either and stores nothing (case 75);
  `Result::none()` builds it. The item type must match the definition's
  `item_type`, and `fields` names must be declared.
- **Range keys.** `timeout`, `max_wait` and `daily_cap` outside their range
  are clamped, not rejected; a wrong type is still `invalid_definition`.
- **Arrays or objects.** A callback receives the plain array form when its
  parameter is typed `array`, and the value object otherwise. `dismiss`
  always receives `Item` objects, because its parameter is a list in both
  forms.
- **Extra error codes.** `daily_cap_reached` (429, ruling 4),
  `result_mismatch` (400, the body names another post or action),
  `item_unknown` (400), `value_invalid` (422, an edited value that is
  empty or over the limits), `run_cancelled` (409, case 35) and
  `storage_error` (500, the record could not be written).
- **Order of apply checks.** `approved` first, then record exists and is
  unexpired, owner, post and action match, post exists, record unused,
  action still registered, action enabled, capabilities, post lock, item,
  value (case 49), context hash (case 50), then the atomic claim.

### Behaviour

- **Cancelling a synchronous run (case 35).** The sidebar sends a random
  `run_token` with each run. Cancel aborts the fetch and sends
  `DELETE /jobs/<run_token>`; the server stores a short-lived marker, and
  when the provider returns it checks the marker (and
  `connection_aborted()`) and answers `run_cancelled` without issuing a
  result id. PHP cannot interrupt the provider's own call.
- **Locks (case 26).** A lock row per user, post and action, inserted with
  `INSERT IGNORE` on the primary key, lifetime `timeout` plus 30 seconds.
  The open-job check runs after the lock is taken, and the lock is held
  until the job record (if any) is stored, so no second run slips in
  between. An open job for the same action counts as in flight.
- **Claims.** `claim` is one conditional `UPDATE` that matches only an
  unused, unexpired result row. After a claim the record is read again, so
  with `reapply` an item is never applied twice and one apply cannot
  overwrite another's mark.
- **Block attributes stored as HTML.** `set_block_attribute` escapes the
  value when the attribute is `rich-text` or sourced from markup (for
  example a caption), so provider text never becomes markup.
- **Log redaction.** Event log details drop URL query strings, URL
  credentials and values that look like keys, tokens, secrets or
  passwords before they are stored.
- **Records keep the declared context.** Section 6 lists what a record
  holds; this build also stores the declared context, so `validate` and
  `poll` receive the original `RunRequest`. It is the user's own draft, it
  lives no longer than the record, and it is never logged.
- **Server apply.** The record is claimed before `apply_callback` runs, so
  two clicks cannot both write. A `WP_Error` releases it unless
  `data.final` is true; an exception releases it; an outcome that breaks
  the contract keeps it used, because the provider may already have
  written. A status change on the post, or an outcome naming a core field,
  fires `contract_violation` (case 61). Links not on the site's own host
  are dropped and logged.
- **Sync back (case 56).** The apply response carries `sync`: new values of
  meta keys registered with `show_in_rest` (scalars only), the featured
  image id, and attachment alt text. The sidebar merges them into the
  editor's saved copy with `receiveEntityRecords`, which changes no edit,
  so the post does not turn dirty and the next save sends the new value.
- **`requires_saved` (case 58).** Applies when the post is dirty or new
  (an auto-draft, case 67). The label becomes "Save and approve".
- **Editor operations.** `set_block_attribute` also checks that the
  attribute is a string attribute of the block type; if not, nothing is
  written and the item says so. `insert_blocks` escapes values into
  paragraph or list-item content.
- **Fatal errors (case 37).** While a provider call is armed, the plugin
  filters core's `wp_php_error_args` and `wp_php_error_message`, so core's
  handler (and its recovery mode) answers with `provider_error` and the
  generic message. A shutdown fallback answers when core's handler is off.
- **Rate limit (case 28).** A fixed window per user in a transient,
  counted when the check passes.
- **Settings capability.** The settings filter decides who sees the "AI
  actions" section; the Writing page itself still needs `manage_options`.
- **Event log.** Extra events: `job_started`, `job_cancelled`,
  `apply_failed`, `result_empty`. Writes are read-modify-write on one
  option; under heavy concurrency an event can be lost. Acceptable for a
  diagnostic log.
- **Language.** `RunRequest::language()` is the `language` need when
  declared, else the site locale as a BCP 47 tag.
- **Multisite.** Settings, log, table and cron are per site. Network
  activation installs on every site; new sites install on
  `wp_initialize_site` when the plugin is network-active.
- **Uninstall.** Deletes the settings, log and schema version options,
  every transient with the plugin prefix, the purge cron and the table, on
  every site. Nothing a provider stored is touched.

## Budgets

- Editor load: three scripts and one stylesheet, enqueued only in the post
  editor for sidebar post types, for users who may use it. No request until
  the sidebar opens.
- Per run or apply, outside the provider: one primary-key read or insert on
  the table, the lock insert and delete, the daily counter read and its
  conditional update, and one transient read and write (rate limit).
- Polling never faster than 2 seconds, backing off to 15.
- Script size: under 60 KB unminified (PRD section 9, changed for
  ruling 5). Minifying by hand would make the shipped file
  differ from the tested file; a site that wants smaller files can serve
  them through its own minifier.

## Testing

- PHPUnit with a stub layer in `tests/stubs/` and an in-memory record
  store; one or more tests per PRD case, named `T<n>`.
- A MySQL integration test for `DbRecordStore` (claim, lock, purge, open
  jobs, exact id matching), run in CI against a MySQL service and skipped
  locally unless `EDITOR_AI_SIDEBAR_TEST_MYSQL` is set.
- Node's test runner for `core.js` and `view.js` with a mocked editor:
  controller flows, rendering, and the shared hash vectors.
- The example providers in `examples/` run end to end through the REST
  routes in `tests/Unit/ExamplesTest.php`.
