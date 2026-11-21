# Migration

What a theme changes when it updates the plugin. Newest first. Each entry: what changed, how
to find the affected code, what to do.

## Unreleased

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
