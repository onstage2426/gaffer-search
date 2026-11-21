# Migration

What a theme changes when it updates the plugin. Newest first. Each entry: what changed, how
to find the affected code, what to do.

## Unreleased

### The daily check re-syncs schedules and deny files (nothing to change in a theme)

- A changed `rebuild` schedule and a deleted deny `.htaccess` in the index folder were only
  fixed when someone opened wp-admin; the daily health check does both now.

### Updates come only as a release's built zip (nothing to change in a theme)

- The updater offers a release only when it has its `gaffer-search.zip`. Before, a release
  without it (made by hand, or while its upload ran) would have been installed from GitHub's
  source zip, which has no `vendor/`: every request would have failed until the plugin folder
  was replaced. Nothing to change.

## v0.3.0 (2026-11-21)

### No admin screens: everything is WP-CLI (nothing to change in a theme)

- **Settings → Gaffer Search** and **Network Admin → Settings → Gaffer Search** are gone, and
  with them the plugin's JavaScript build. Everything they did is a WP-CLI command
  (`docs/hosting.md`): `wp gaffer-search status` (now also names what this PHP lacks),
  `rebuild`, `verify [--fix]`, `search <index> [<query>]` (the test search), `clean [--delete]`
  (index files no definition uses, a killed build's temp files), `rotate-secret <token|search>`.
  On a network add `--url=<site>`. Gaffer's `php gaffer doctor --wp` checks the endpoint over HTTP
  (the dashboard's config check). Find: bookmarks or notes that point to the screen.

### Several web servers build by themselves (`GAFFER_SEARCH_SNAPSHOT_DIR` is gone)

- Full rebuilds are no longer shared between web servers through `GAFFER_SEARCH_SNAPSHOT_DIR`
  (published generations, a rebuild lock in the database): each server builds and updates its
  own copy (`GAFFER_SEARCH_INDEX_DIR`). The constant does nothing now. Find:
  `grep -n GAFFER_SEARCH_SNAPSHOT_DIR wp-config.php`; remove the line, delete the snapshot folder,
  and run `wp gaffer-search rebuild` from every server's crontab (`docs/hosting.md`, "Several web
  servers"). A single server changes nothing.

## v0.2.0 (2026-11-21)

### A search that couldn't run says so (nothing to change in a theme)

- `search()` from PHP adds `code` (`index_not_built`, `search_failed`) to the empty response it
  gives when the index isn't built or the search failed (feature `search_codes`,
  `docs/contract.md`). With a Gaffer that reads it, such a page stays out of page caches and
  search engines instead of being kept as an empty shop.

### A daily health check (nothing to change in a theme)

- A daily job per site (WP-cron `gaffer_search_health_check`) compares about 60 documents of
  each index with the database and puts the ones that differ in the update log: a write that
  fires no hook (direct SQL, an import past WordPress) is fixed within a day instead of waiting
  for a rebuild. It also fetches a probe file from each folder the plugin protects, like a
  visitor. `status()` reports both (feature `health_checks`); `wp gaffer-search status` exits 1
  when a folder was served (`docs/hosting.md` has the nginx rule). With a system cron instead
  of WP-cron, the job runs with `wp cron event run --due-now`.
