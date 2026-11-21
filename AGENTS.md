# gaffer-search — working on this plugin

Gaffer's search engine. This repo is cloned into Gaffer's `plugins/gaffer-search/`; Gaffer's
`AGENTS.md` (two folders up) has the workflow every plugin shares: commits, the public-knowledge
rule, contracts, testing on real sites, releases. This file holds only what's particular to
gaffer-search.

Themes never call it: they define `config/search.php` and use `Gaffer\Search`; Gaffer talks to
the plugin only through the engine contract. It stays a plugin, not a theme package: its data
outlives the theme, one copy serves a network, the endpoints need a fixed path; it never
bundles or calls Gaffer (Gaffer hands it data and closures). `design.md` has the why.

The plugin builds indexes from WordPress content with the library `onstage2426/fuzor` (SQLite,
BM25, facets; only its copy in `vendor/` is in this repo) and serves searches from
`endpoints/search.php` **without booting WordPress**, authorized by signed tokens.

## Where things are

- **The engine contract** (`docs/contract.md`, `src/Engine.php`, `tests/contract/*.json` run by
  `tests/ContractTest.php`): the plugin's whole public API for code, found through
  `apply_filters('gaffer_search_engine', null, 1)`. It only grows: a recorded case is never edited
  or deleted (`tests/contract/MANIFEST` holds their sha256, checked by the test); new cases are
  recorded with `RECORD_CONTRACT=1 composer test` (writes only missing expectations and appends
  to the manifest). Cases must not freeze what the contract doesn't promise (relevance order:
  give such cases a sort). `warnings` are compared by count only (`ContractTest::PROSE`): their
  text is for people and partly Fuzor's, which renames its settings. Additions are features
  (`supports()`), listed in the contract. Gaffer keeps a copy of the fixtures
  (`tests/fixtures/search-contract/` there).
- Public API: `API.md` + `api.txt` (`tests/ApiTest.php`: the contract's methods, the response
  keys, the endpoint's error codes, WP-CLI, the constants; every class and function `@internal`).
  Docs: `docs/contract.md`, `docs/hosting.md`, `docs/migration.md` (one entry per change sites
  react to, newest first, under "Unreleased").
- PHP: `src/Engine.php` (contract 1 over the internals: `Services\Tokens` signs, `Services\PhpSearch`
  searches, `Services\Locks` adds the `term`/`visibility` locks; no global functions), `src/Support/`
  (`SearchRunner` — the one search path for the endpoint and PHP, `FilterQuery`, `Token`,
  `Labels`, `ConfigLocation`, `ProtectedDir`, `Language/` adapters), `src/Services/`
  (`IndexService` builds per language, `SearchConfig` writes the endpoint config,
  `ChangeTracker`, `IndexRegistry`: what `Engine::define()` registered), `src/Builders/`
  (products, posts), `src/Bootstrap.php` (also `uninstall()`), the dashboard
  (`Controllers/Admin/`, `templates/dashboard.php`, `assets/js/admin/admin.js`: status, rebuild,
  verify, test search, delete, secrets).
- No frontend JavaScript: the search JS is Gaffer's (`resources/js/search.js` there).
- Gaffer's side: `src/Search/`, `resources/ai/*/plugins/gaffer-search`, `resources/js/search*`.
  A contract addition lands in both repos: the feature here, `supports()` checks there (Gaffer's
  `contract-change` skill; `bin/dev/contract gaffer-search` there maps the boundary).
- Tests: `tests/Unit`, `tests/Endpoint` (PHP's built-in server on the real endpoint with
  per-site fixture configs), `tests/ContractTest.php`, `tests/stubs/wordpress.php`.
  Benchmark: `bench/` (`bun run bench`, 100k generated products; baseline in `bench/README.md`).

## Checks, deploys, releases

- Before committing: `composer check` (phpcs + phpstan + phpunit) and `bun run build`.
- On a site: `bash deploy.sh <site>/wp-content/plugins/gaffer-search` (builds, generates
  `src/WpHubUpdater` if missing, rsyncs, stamps a version like `0.2.2.2` so the auto-updater
  doesn't replace it). Which sites: Gaffer's `local/AGENTS_LOCAL.md`.
- Browser tests: `php gaffer render:screenshot <path> --fill=… --click=…` in a theme on
  `config/search.php` (lists the endpoint's failed requests with their answer).
- The user tags releases; never tag 1.0 (that comes after long testing). Release builds come
  from tags (`.github/workflows/release.yml`); the updater installs GitHub's releases.
- A zip by hand, between tags (the version must be newer than the last tag, or the updater
  replaces the zip with that release):

  ```bash
  R=$PWD; B=/tmp/gs-build; rm -rf $B; mkdir -p $B/src $B/pkg; git archive HEAD | tar -x -C $B/src; cd $B/src
  composer install -q && vendor/bin/wp-hub-updater GafferSearch src/WpHubUpdater
  composer install -q --no-dev --optimize-autoloader && bun install && bun run build
  V=$(git -C "$R" describe --tags --match 'v*' | sed -E 's/^v//; s/-([0-9]+)-g[0-9a-f]+$/.\1/')   # v0.1.0 + 1 commit: 0.1.0.1
  sed -i "s/^ \* Version:.*/ * Version: $V/" gaffer-search.php
  cp .deployignore /tmp/gs-exclude   # docs/migration.md ships (sites read it after updating)
  rsync -a --exclude-from=/tmp/gs-exclude --exclude=.deployignore ./ $B/pkg/gaffer-search/
  cd $B/pkg && zip -qr /tmp/gaffer-search.zip gaffer-search
  ```

  Check it: every PHP file passes `php -l`, `assets/dist/` and `src/WpHubUpdater/` are in,
  `vendor/` holds only `onstage2426/fuzor` (and Composer's files).

## Key design (don't undo without a decision)

- Taxonomy fields store **slugs**; names come from a labels file next to each index
  (`{slug}.labels.json`) and go out as `labels` / `hit.labels` / facet-hit `label`.
- **One endpoint config per site**: `wp-content/gaffer-search/sites/{blog_id}.php` (has a
  `format`; rewritten on the next request when it differs). Tokens carry `site` (+ `language`
  on a site with languages), set in `Tokens::payload()`, never by callers; the endpoint picks
  the config by the unverified `site` claim, then verifies with that site's secret.
- **One index file per language** on WPML sites (`IndexSlug::compute(name, secret, language)`);
  builds switch the language; `Languages::current()` decides which file requests read.
- Builders implement `IndexBuilderInterface` v2 (`BuildContext` everywhere, `documentsFor()`,
  `labels()`); `ProductIndexBuilder` builds URLs with `get_permalink()`.
- Locked token filters replace the visitor's filter for that field (visibility, term archive)
  — that's the security model, the endpoint knows no user.
- Content changes are **per document**: `ChangeTracker` appends post IDs per index to the site's
  update log (`UpdateQueue`, table `{prefix}gaffer_search_queue`; post ID 0 = labels only). Each server
  keeps its own position (`{index}.state.json` next to its files) and applies the log with
  `IndexService::applyUpdates()` (WP-cron job `gaffer_search_apply_updates`, or `wp gaffer-search update`):
  `documentsFor()` per language under the build lock, upsert + delete. Full rebuilds stay for
  hierarchical term structure changes, other sites' changes, changed builders, a position
  behind the pruned log, and the `rebuildInterval` schedule. Several servers: `GAFFER_SEARCH_INDEX_DIR`
  (local disk); each server builds and updates its own copy.
- Tokens never expire unless signed with `ttl` (→ `expires` claim, `token_expired`, private
  caching): public cached pages must keep working.
- Every folder the plugin writes (indexes, endpoint configs) gets `ProtectedDir`'s
  deny `.htaccess` + `index.php`: an index holds every stored document, also hidden ones.
- Hooks are all `gaffer_search_…`; per-index hooks get the index name as an argument.
- A plugin update must never undefine a theme's index or change what an existing site shows.

## Gotchas learned here

- **Web server and CLI see different paths** (a container and the host): the endpoint config
  stores `index_dir` relative to itself when both are in `wp-content`
  (`ConfigLocation::indexDirFor()`), so a CLI WordPress load no longer rewrites it with its own
  paths (it did until 2026-11-01: `index_not_built` until the next web request).
  `GAFFER_SEARCH_INDEX_DIR` stays absolute: set it the same for both, or build where the web
  server runs. Build in each site's own context (`$_SERVER['REQUEST_URI'] = '/second/'` before
  `wp-load.php` for a network's site 2).
- `onstage2426/fuzor` comes from a VCS repository entry in `composer.json`, not only Packagist:
  Packagist's record of the `2.x` branch can lag far behind (it served `3d6c4e8` while the branch
  was 77 commits further). `composer update onstage2426/fuzor` then reads the branch from GitHub.
- WPML stays loaded when code `switch_to_blog()`s to a site without it: it logs "table doesn't
  exist" errors and filters `home_url()` (`?lang=nl`); use the site record for URLs.
- `get_pagenum_link()` returns HTML-escaped URLs (`&#038;`): pass `false` for redirects.
- In a top-level PHP script, `$id` is a global that WordPress (`setup_postdata()`) overwrites:
  wrap test scripts in a function.
- phpstan reads "`[] when`" in a `@return` description as part of the type.
- WCML syncs stock/prices to translated products and variations with queries that fire no hook:
  updates always include a post's translations (`LanguageAdapter::translations()`).
- WP-cron holds a `doing_cron` lock for up to a minute: a test that requests `wp-cron.php`
  right after a spawn does nothing. Wait it out, or call the hook with `do_action()` in-process.
- WordPress alone can use most of PHP's 128 MB (a 562-product shop boots to ~114 MB): anything
  that reads a whole index (build, `verify()`) calls `wp_raise_memory_limit('admin')` itself, and
  builders flush the runtime object cache after each batch (`releaseBatch()`).
