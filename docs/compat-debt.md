# Compatibility debt

What a site's running search depends on that could break it after a plugin, Gaffer, Fuzor or
platform update, and what the plugin carries or plans because of it. Kept by Gaffer's
`prompts/audit-gaffer-search.md` (run it now and then, and before a plugin release that sites
must take). Every entry is dated, says why, and what removing or fixing it takes. Fixed entries
are removed here and mentioned in `migration.md`.

Last audit: 2026-11-21 (WordPress 7.1.5, WooCommerce 11.2–11.3, WPML 5.1.0 with WooCommerce
Multilingual 5.6.2, PHP 8.5.12, SQLite 3.46.1, Fuzor 2.x 36955f9). The plugin calls nothing
that PHP 8.5/8.6, WordPress 7.x or WooCommerce 11.x deprecates.

## Open

Start the next audit here; remove an item when it's done or decided.

- **Before the plugin's 1.0** (Gaffer's full-stack audit, 2026-11-22; Gaffer's Search API is
  experimental until then and freezes with it). Decide in one cycle:
  - `Search::count()`/`facet_counts()` (Gaffer) return 0/empty when the search couldn't run
    (`code` set): null then; `count()` has no user (remove).
  - `Counter` has no `attrs()`: a theme copies `endpoint`/`token` into `createCounter()` by hand.
    Gaffer writes them; then the public `endpoint`/`token` on `Archive`, `Searchbar`, `Counter`
    become internal (deprecate first: one site uses them).
  - The JavaScript's `nl-NL`/EUR fallbacks (`helpers.js`) become the browser's locale and an
    explicit currency.
  - `problems()` refuses an index name over 64 characters (the update log's `index_name` is
    `varchar(64)`: a longer name never takes in changes). Free now, constrained after 1.0.
  - `gaffer_search_options` (autoloaded, unwritten since 0.3.0's admin screens): move a stored
    `index_dir` out once (show it in `status`), drop the option and its hook.
  - The endpoint's `?check=<site>` probe isn't in the contract and only a test calls it: remove.
  - `api.txt` misses the response's `code` and the updater's `wp hu` commands (the CLI is
    tooling since 2026-11-27, except the crontab commands); `hosting.md`
    tells hosts to delete the internal `hu_state-gaffer-search`.
  - `contract.md` wording: `updates_checked` is "first read of `status()`", not "first run of
    this version"; `served: null` means not checked or no folder in the web root;
    `index_unknown` answers 503 for good. Every feature is ready to fold into the baseline after
    those two wordings.
  - Fuzor's warning text reaches sites (console, `initial()` JSON in cached pages, doctor
    baselines) while Fuzor calls it unstable: prefix it or declare it free.
- **Requests to libraries we own** (2026-11-22, none breaks anything there): Fuzor: throw on an
  index file with missing info keys (`IndexFiles` opens with `@` today), promise an order for
  facet values with equal counts. The updater library: an option that requires the release asset
  (`ReleaseAsset` reads the internal `Reference->downloadUrl` and accepts only
  `releases/download` URLs: a change there means no update is offered, which `update_checks`
  shows after 7 days); document when `hu_request_update_result` runs.
- **A health check that never ran reads as green** (2026-11-22): `drift_checked` is null before
  the first daily check, and Gaffer can't tell "new" from "WP-cron never runs". A first-seen time
  needs a contract wording change (`drift_checked` is documented as null before the first one).
- **A reader that can't write the index folder** (2026-11-22, seen on a dev host where the CLI
  and the web server are different users): a freshly published version has no `-wal`/`-shm`, and
  SQLite can't open a WAL database read-only without creating them, so searches answer 503
  `index_not_built` until a writer opens it. `hosting.md` already says to run the CLI as the web
  server's user; a daily-check line when the folder isn't writable for the web server would show
  it.
- **Change tracking that fails** rebuilds every index a minute later (2026-11-22; before, the
  change was only logged and the index stayed stale until the daily sample found it).

- **The updater and GitHub** (2026-11-21, break-first audit). Releases come from GitHub's API
  without a token: 60 requests an hour per IP, about two checks a day per site; a 403/429 stores a
  backoff until the reset (`hu_ratelimit-gaffer-search`), so several sites on one server stay far
  below it. The updater offers only a release's `gaffer-search.zip` (`Support\ReleaseAsset`):
  its fallback to the source zipball (no `vendor/`) would fatal every request, and a manual
  `wp plugin update` has no fatal-error rollback. The release workflow publishes only after the
  upload (draft first): keep it that way. A failed check looks like "no update" to the updater:
  the plugin records the last check that found the latest release and its error (`UpdateCheck`,
  feature `update_checks`, 2026-11-22), and `status` and Gaffer's health line warn after 7 days.
  Not covered by the backoff: GitHub's secondary limit (a 403 without `x-ratelimit-remaining: 0`)
  and, without a token, 304s still count; both harmless at two checks a day.
- **Conditional requests** (2026-11-22): the endpoint compares `If-None-Match` weakly (nginx's
  gzip sends `W/"…"`). Where WP Rocket's root `.htaccess` drops the ETag, `If-Modified-Since`
  gives the 304.
- **Work that needs wp-admin** (2026-11-21): none left. The rebuild schedules and the index
  folder's deny files are synced on `admin_init` and by the daily health job.

- **Secret rotation has no grace period** (2026-11-15, deferred). Rotating the token secret
  makes every cached page's searches fail (`invalid_token`) until the page cache is cleared.
  Verifying against the previous secret for a day would cost a second option and a second
  `hash_hmac()` for old tokens.

## Kept for compatibility

- **Tokens never expire unless signed with `ttl`, and carry `c: 1`** (contract 1). Pages in a
  page cache keep their tokens for good, so the endpoint reads every token the engine ever
  signed: `tests/contract/endpoint-token-from-before-the-contract.json` (no `c`) and the
  `endpoint-signed-*` cases (each kind of lock as signed on 2026-11-21) freeze that. Never
  removed in contract 1.
- **A definition's `language` key is ignored** (2026-11-15). Fuzor 2 has no stemmers; a theme
  written before keeps its index, and `problems()` asks to remove the line. Removing the
  special case would leave only the generic "unknown key" problem: no site would break.
- **Built-in product fields win over `meta` lines** for `_price`, `_sku` and the rest
  (2026-11-04). A theme written before they were built in keeps its index. Rule: a plugin
  update never undefines a theme's index.
- **What an index was built from, as one option per index** (2026-11-05):
  `migrateBuiltOptions()` moves the two arrays of before that date once. Remove it when no site
  can still have them (every site that ran a release since then has migrated).
- **A position recorded before update logs had an ID is trusted once** (2026-11-21), so all of a
  site's servers don't rebuild at once after the update. Remove with the `migrateBuiltOptions()`
  cleanup.

## Platform dependencies and their timelines

- **SQLite 3.46.0 or newer** (Fuzor's `MIN_SQLITE_VERSION`, 2026-11-15). Debian 13, RHEL 10 and
  Ubuntu 26.04 ship 3.46.1; Ubuntu 24.04 ships 3.45.1, where the plugin refuses to activate.
  Builds check it (`Requirements`); the endpoint doesn't: a web PHP linked to an older SQLite
  than the CLI's fails every search with `search_failed` while WP-CLI works; Gaffer's
  `doctor --wp` searches over HTTP and shows it.
- **PHP 8.5, 64-bit, `pdo_sqlite`, `mbstring`** (2026-11-15). PHP 8.5 deprecates
  `PDO::sqliteCreateFunction()` and its siblings (removal in 9.0); neither the plugin nor Fuzor
  calls them.
- **Fuzor's index files** (2026-11-21). Every 2.x release reads the files of earlier 2.x
  releases (Fuzor's `docs/operations.md`), so `IndexService::FILE_FORMAT` changes only with a
  Fuzor major. A change still means: every search of the index fails until its rebuild is done
  (old files don't open), disk for two copies, and on a network every site at once.
- **WooCommerce's product objects** (2026-11-21). Builders read prices, stock, SKU and rating
  from `WC_Product` getters, except variations' `_stock_status` and `attribute_*` and images'
  `_thumbnail_id`/`_wp_attached_file`, read from post meta in one query per batch
  (`ProductIndexBuilder`, 2026-11-22: a storage move in WooCommerce would filter wrongly without
  an error; the daily sample compares the documents). WooCommerce 11.2 skips `set_object_terms` when a
  product's terms didn't change (no change to track then); its per-request product object cache
  is flushed with each batch (`releaseBatch()`). Scheduled sales: WooCommerce saves the product
  when a sale starts or ends, which fires the hooks `ChangeTracker` listens to (not measured
  yet; the daily sample check would catch a miss).
- **WooCommerce Multilingual's sync** (2026-11-21). It copies stock and prices between
  translations with queries that fire no hook, so an update always includes a post's
  translations. Its opt-in "Editor Scoped Sync" (5.5.6) syncs translated variations in the
  background afterwards: those changes reach the index through the daily sample check (within a
  day, or a few days on a large catalog), not within seconds.
- **WPML's language codes** (2026-11-21). One index file per language, named from the code;
  WPML 5 kept the codes (`en-us`). A changed code would rebuild that language's file, and pages
  cached with the old code answer `language_unknown` (the page is stale) until the cache clears.
- **WP-cron** (2026-11-21). Updates, rebuilds, the build of a missing index and the daily health
  check are WP-cron jobs. WordPress 6.9 moved the spawn to `shutdown`. Without traffic, behind
  HTTP auth or with `DISABLE_WP_CRON`, a system cron runs them (`docs/hosting.md`);
  `status()`'s `pending` shows when it doesn't.
- **The web server** (2026-11-21). The endpoint is a PHP file in the plugin folder, served
  directly; a rule blocking PHP in `wp-content/plugins` stops every browser search (the
  `doctor --wp` searches through it over HTTP). The deny `.htaccess` in the plugin's folders covers
  Apache and LiteSpeed only; the daily health check now fetches a probe file from each and
  reports a served folder in `status()`.

## Not self-adapting yet

- **The browser side** (2026-11-21). Gaffer's search JavaScript writes the request keys and reads
  the answer keys and error codes (`contract.md`, "The browser side"). The endpoint cases freeze
  the plugin's half, and Gaffer's JavaScript tests replay them; what the JavaScript sends is
  checked against the parameters the endpoint reads, but no case records a request the
  JavaScript built. Still prose: the order of `sort[]` and how a form names `filter[…]`.

## Rules that follow from this

- A plugin update never undefines a theme's index or changes what an existing site shows.
- The contract only grows (`contract.md`); a recorded case is never edited or deleted.
- Nothing often written goes into an autoloaded option: one non-autoloaded option per key.
- What the endpoint reads (its config, the index files, tokens in cached pages) is read in
  every shape an earlier release wrote, or its change rebuilds the index in the background
  while the old one keeps answering.
