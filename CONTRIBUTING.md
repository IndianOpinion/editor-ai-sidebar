# Contributing

Thank you for helping. Bug reports, fixes and new tests are all welcome.

## Setup

You need PHP 8.1 or later, Composer 2, and Node 20 or later for the script tests.

```sh
git clone https://github.com/IndianOpinion/editor-ai-sidebar.git
cd editor-ai-sidebar
composer install
```

No WordPress install is needed for the tests: `tests/stubs/` holds a small stub layer.

## Tests

```sh
composer test         # PHPUnit
npm test              # sidebar scripts, Node's built-in test runner, no dependencies
composer analyse      # PHPStan, level 8
composer lint         # PHP_CodeSniffer: PSR-12 plus WordPress security sniffs
composer check        # all three PHP checks
```

The MySQL integration test is skipped unless `EDITOR_AI_SIDEBAR_TEST_MYSQL` is set to `host:port` or a socket path for a server where `root` has no password. It creates and uses a database named `editor_ai_sidebar_test`.

Every behaviour in `docs/PRD.md` section 4 has a test; the test names carry the case number (`T27` and so on). A change in behaviour needs a test that fails before it and passes after.

## Style

- PHP: PSR-12, `declare(strict_types=1);`, PHP 8.1 syntax only (no readonly classes, no DNF types).
- JavaScript: plain ES5-compatible scripts using the WordPress globals. There is no build step, and there should not be one.
- No HTML from a provider ever reaches the page. Render strings as text.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- One topic per pull request. Describe what changes for an editor or a provider author.
- Keep `composer check` and `npm test` green.
- Update `CHANGELOG.md` under "Unreleased".
- Changes to the REST contract or the definition keys need a note in `docs/ARCHITECTURE.md`. Contract version 1 must keep working.

A DCO-style sign-off is not required.
